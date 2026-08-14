<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\Database;
use PublishGo\Core\Env;
use PublishGo\Core\HttpException;
use PublishGo\Core\Jwt;
use PublishGo\Core\Request;
use PublishGo\Core\Response;
use PublishGo\Core\Validator;
use PublishGo\Models\AuditLog;
use PublishGo\Models\Company;
use PublishGo\Models\User;

/**
 * Painel central da Publish Go (super-admin).
 * Opera sobre TODAS as empresas — não é escopado por company_id.
 * Todas as rotas exigem papel 'admin'.
 */
final class AdminController extends Controller
{
    private function guard(Request $request): void
    {
        $this->requireRole($request, 'admin');
    }

    /** Visão global da plataforma. */
    public function overview(Request $request): mixed
    {
        $this->guard($request);

        $companies = Database::first(
            "SELECT COUNT(*) AS total, SUM(is_active = 1) AS active FROM companies WHERE slug <> 'publish-go'"
        ) ?? [];
        $orders = Database::first(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN status='delivered' THEN total ELSE 0 END),0) AS gmv,
                    COALESCE(SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END),0) AS delivered
             FROM orders"
        ) ?? [];
        $couriers = Database::first(
            "SELECT COUNT(*) AS total, SUM(status='online') AS online FROM couriers"
        ) ?? [];
        $today = Database::first(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(CASE WHEN status='delivered' THEN total ELSE 0 END),0) AS revenue
             FROM orders WHERE DATE(created_at) = CURDATE()"
        ) ?? [];

        return [
            'companies_total' => (int) ($companies['total'] ?? 0),
            'companies_active' => (int) ($companies['active'] ?? 0),
            'gmv' => (float) ($orders['gmv'] ?? 0),
            'orders_total' => (int) ($orders['total'] ?? 0),
            'orders_delivered' => (int) ($orders['delivered'] ?? 0),
            'couriers_total' => (int) ($couriers['total'] ?? 0),
            'couriers_online' => (int) ($couriers['online'] ?? 0),
            'today_orders' => (int) ($today['n'] ?? 0),
            'today_revenue' => (float) ($today['revenue'] ?? 0),
        ];
    }

    /** Lista todas as empresas com métricas resumidas. */
    public function companies(Request $request): mixed
    {
        $this->guard($request);
        $rows = Database::select(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM orders o WHERE o.company_id = c.id) AS orders_count,
                    (SELECT COUNT(*) FROM couriers k WHERE k.company_id = c.id) AS couriers_count,
                    (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id) AS users_count
             FROM companies c
             WHERE c.slug <> 'publish-go'
             ORDER BY c.created_at DESC"
        );
        return array_map([$this, 'castCompany'], $rows);
    }

    /** Cria uma nova empresa + usuário administrador do estabelecimento. */
    public function createCompany(Request $request): mixed
    {
        $this->guard($request);
        $data = Validator::validate($request->all(), [
            'company_name' => 'required|string|max:150',
            'owner_name' => 'required|string|max:150',
            'email' => 'required|email|max:150',
            'password' => 'required|string|min:6|max:100',
            'phone' => 'string|max:20',
            'plan' => 'in:free,pro,enterprise',
            'primary_color' => 'string|max:9',
            'accent_color' => 'string|max:9',
            'document' => 'string|max:20',
            'document_type' => 'in:cpf,cnpj',
            'address' => 'string|max:255',
        ]);

        if (User::findByEmail($data['email']) !== null) {
            throw HttpException::unprocessable('E-mail já cadastrado.', ['email' => ['Em uso.']]);
        }

        $slug = $this->uniqueSlug($data['company_name']);

        Database::beginTransaction();
        try {
            $companyId = Company::create([
                'name' => $data['company_name'],
                'slug' => $slug,
                'subdomain' => $slug,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'],
                'plan' => $data['plan'] ?? 'pro',
                'primary_color' => $data['primary_color'] ?? '#F5A623',
                'accent_color' => $data['accent_color'] ?? '#F2994A',
                'document' => isset($data['document']) ? preg_replace('/\D/', '', $data['document']) : null,
                'document_type' => $data['document_type'] ?? null,
                'address' => $data['address'] ?? null,
            ]);
            User::create([
                'company_id' => $companyId,
                'name' => $data['owner_name'],
                'email' => $data['email'],
                'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
                'role' => 'establishment',
            ]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        AuditLog::record($companyId, $this->userId($request), 'admin.company.create', 'company', $companyId, $request->ip());
        return $this->castCompany((array) Company::find($companyId));
    }

    /** Ativa ou suspende uma empresa. */
    public function toggleCompany(Request $request): mixed
    {
        $this->guard($request);
        $id = (int) $request->param('id');
        $company = Company::find($id);
        if ($company === null) {
            throw HttpException::notFound('Empresa não encontrada.');
        }
        $newState = (int) $company['is_active'] === 1 ? 0 : 1;
        Company::update($id, ['is_active' => $newState]);
        AuditLog::record($id, $this->userId($request), 'admin.company.toggle', 'company', $id, $request->ip(), ['active' => $newState]);
        return $this->castCompany((array) Company::find($id));
    }

    /** Atualiza plano / dados de uma empresa pelo admin. */
    public function updateCompany(Request $request): mixed
    {
        $this->guard($request);
        $id = (int) $request->param('id');
        if (Company::find($id) === null) {
            throw HttpException::notFound('Empresa não encontrada.');
        }
        $data = Validator::validate($request->all(), [
            'name' => 'string|max:150',
            'plan' => 'in:free,pro,enterprise',
            'phone' => 'string|max:20',
            'primary_color' => 'string|max:9',
            'accent_color' => 'string|max:9',
            'document' => 'string|max:20',
            'document_type' => 'in:cpf,cnpj',
            'address' => 'string|max:255',
        ]);
        if (isset($data['document'])) {
            $data['document'] = preg_replace('/\D/', '', $data['document']);
        }
        if ($data !== []) {
            Company::update($id, $data);
        }
        return $this->castCompany((array) Company::find($id));
    }

    /**
     * Emite um token de acesso do estabelecimento para que o super-admin
     * acesse o painel da empresa ("logar como o estabelecimento").
     * Mantém o registro de quem impersonou no claim 'impersonated_by'.
     */
    public function impersonate(Request $request): mixed
    {
        $this->guard($request);
        $id = (int) $request->param('id');
        $company = Company::find($id);
        if ($company === null) {
            throw HttpException::notFound('Empresa não encontrada.');
        }

        // Usuário de acesso da empresa: prioriza o dono (role 'establishment').
        $user = Database::first(
            "SELECT * FROM users
             WHERE company_id = :c AND is_active = 1
             ORDER BY (role = 'establishment') DESC, id ASC
             LIMIT 1",
            ['c' => $id]
        );
        if ($user === null) {
            throw HttpException::unprocessable('Esta empresa não possui usuário de acesso ativo.');
        }

        $adminId = $this->userId($request);
        AuditLog::record($id, $adminId, 'admin.company.impersonate', 'user', (int) $user['id'], $request->ip());

        $claims = [
            'sub' => (int) $user['id'],
            'company_id' => (int) $user['company_id'],
            'role' => $user['role'],
            'name' => $user['name'],
            'scope' => 'user',
            'impersonated_by' => $adminId,
        ];

        return [
            'access_token' => Jwt::issueAccess($claims),
            'refresh_token' => Jwt::issueRefresh(['sub' => (int) $user['id'], 'company_id' => (int) $user['company_id']]),
            'token_type' => 'Bearer',
            'expires_in' => Env::int('JWT_TTL', 3600),
            'user' => User::publicData($user),
            'company' => Company::publicTheme((int) $user['company_id']),
        ];
    }

    private function castCompany(array $c): array
    {
        $c['id'] = (int) $c['id'];
        $c['is_active'] = (int) $c['is_active'] === 1;
        foreach (['orders_count', 'couriers_count', 'users_count'] as $k) {
            if (isset($c[$k])) {
                $c[$k] = (int) $c[$k];
            }
        }
        $c['delivery_fee'] = (float) $c['delivery_fee'];
        $c['courier_commission'] = (float) $c['courier_commission'];
        return $c;
    }

    private function uniqueSlug(string $name): string
    {
        $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c'];
        $base = preg_replace('/[^a-z0-9]+/', '-', strtr(mb_strtolower($name), $map)) ?? 'empresa';
        $base = trim($base, '-') ?: 'empresa';
        $slug = $base;
        $i = 1;
        while (Company::findBySlug($slug) !== null) {
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }
}
