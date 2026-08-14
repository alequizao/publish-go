<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\Env;
use PublishGo\Core\HttpException;
use PublishGo\Core\Jwt;
use PublishGo\Core\Request;
use PublishGo\Core\Response;
use PublishGo\Core\Validator;
use PublishGo\Models\AuditLog;
use PublishGo\Models\Company;
use PublishGo\Models\User;

final class AuthController extends Controller
{
    /** Cadastro de nova empresa + usuário administrador do estabelecimento. */
    public function register(Request $request): mixed
    {
        $data = Validator::validate($request->all(), [
            'company_name' => 'required|string|max:150',
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:150',
            'password' => 'required|string|min:6|max:100',
        ]);

        if (User::findByEmail($data['email']) !== null) {
            throw HttpException::unprocessable('Este e-mail já está em uso.', ['email' => ['E-mail já cadastrado.']]);
        }

        $slug = $this->uniqueSlug($data['company_name']);

        $companyId = Company::create([
            'name' => $data['company_name'],
            'slug' => $slug,
            'email' => $data['email'],
        ]);

        $userId = User::create([
            'company_id' => $companyId,
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
            'role' => 'establishment',
        ]);

        AuditLog::record($companyId, $userId, 'auth.register', 'company', $companyId, $request->ip());

        $user = User::find($userId);
        Response::success($this->tokenResponse($user), 201);
        return null;
    }

    /** Login por e-mail + senha. */
    public function login(Request $request): mixed
    {
        $data = Validator::validate($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::findByEmail($data['email']);
        if ($user === null || !password_verify($data['password'], $user['password_hash'])) {
            // Mensagem genérica — não revela se o e-mail existe.
            throw HttpException::unauthorized('Credenciais inválidas.');
        }
        if ((int) $user['is_active'] !== 1) {
            throw HttpException::forbidden('Usuário desativado.');
        }

        User::touchLogin((int) $user['id']);
        AuditLog::record((int) $user['company_id'], (int) $user['id'], 'auth.login', 'user', (int) $user['id'], $request->ip());

        return $this->tokenResponse($user);
    }

    /** Emite novo access token a partir de um refresh token válido. */
    public function refresh(Request $request): mixed
    {
        $token = (string) $request->input('refresh_token', '');
        if ($token === '') {
            throw HttpException::unprocessable('refresh_token é obrigatório.');
        }
        $payload = Jwt::decode($token);
        if ($payload === null || ($payload['type'] ?? '') !== 'refresh') {
            throw HttpException::unauthorized('Refresh token inválido ou expirado.');
        }
        $user = User::find((int) ($payload['sub'] ?? 0));
        if ($user === null) {
            throw HttpException::unauthorized('Usuário não encontrado.');
        }
        return $this->tokenResponse($user);
    }

    /** Dados do usuário autenticado. */
    public function me(Request $request): mixed
    {
        $user = User::find($this->userId($request));
        if ($user === null) {
            throw HttpException::notFound('Usuário não encontrado.');
        }
        $company = Company::publicTheme((int) $user['company_id']);
        return [
            'user' => User::publicData($user),
            'company' => $company,
        ];
    }

    /** @param array<string,mixed> $user */
    private function tokenResponse(array $user): array
    {
        $claims = [
            'sub' => (int) $user['id'],
            'company_id' => (int) $user['company_id'],
            'role' => $user['role'],
            'name' => $user['name'],
            'scope' => 'user',
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

    private function uniqueSlug(string $name): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($this->stripAccents($name))) ?? 'empresa';
        $base = trim($base, '-') ?: 'empresa';
        $slug = $base;
        $i = 1;
        while (Company::findBySlug($slug) !== null) {
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }

    private function stripAccents(string $str): string
    {
        $map = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ì' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ];
        return strtr(mb_strtolower($str), $map);
    }
}
