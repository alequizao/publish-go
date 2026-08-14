<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\HttpException;
use PublishGo\Core\Request;
use PublishGo\Core\Validator;
use PublishGo\Models\AuditLog;
use PublishGo\Models\Company;

final class CompanyController extends Controller
{
    /** Dados da empresa autenticada. */
    public function show(Request $request): mixed
    {
        $company = Company::find($this->companyId($request));
        if ($company === null) {
            throw HttpException::notFound('Empresa não encontrada.');
        }
        return $this->cast($company);
    }

    /** Atualiza identidade visual (whitelabel) e configuração operacional. */
    public function update(Request $request): mixed
    {
        $this->requireRole($request, 'admin', 'establishment');
        $companyId = $this->companyId($request);

        $data = Validator::validate($request->all(), [
            'name' => 'string|max:150',
            'phone' => 'string|max:20',
            'document' => 'string|max:20',
            'logo_url' => 'string|max:255',
            'primary_color' => 'string|max:9',
            'accent_color' => 'string|max:9',
            'theme' => 'in:light,dark,system',
            'address' => 'string|max:255',
            'lat' => 'latitude',
            'lng' => 'longitude',
            'delivery_fee' => 'numeric',
            'courier_commission' => 'numeric|min:0|max:100',
        ]);

        if ($data !== []) {
            Company::update($companyId, $data);
            AuditLog::record($companyId, $this->userId($request), 'company.update', 'company', $companyId, $request->ip());
        }

        return $this->cast((array) Company::find($companyId));
    }

    private function cast(array $c): array
    {
        $c['id'] = (int) $c['id'];
        $c['lat'] = $c['lat'] !== null ? (float) $c['lat'] : null;
        $c['lng'] = $c['lng'] !== null ? (float) $c['lng'] : null;
        $c['delivery_fee'] = (float) $c['delivery_fee'];
        $c['courier_commission'] = (float) $c['courier_commission'];
        $c['is_active'] = (int) $c['is_active'] === 1;
        return $c;
    }
}
