<?php

declare(strict_types=1);

namespace PublishGo\Controllers;

use PublishGo\Core\HttpException;
use PublishGo\Core\Request;

abstract class Controller
{
    /** Retorna o company_id autenticado (multi-tenant). */
    protected function companyId(Request $request): int
    {
        $id = $request->auth['company_id'] ?? 0;
        if ($id <= 0) {
            throw HttpException::unauthorized();
        }
        return (int) $id;
    }

    protected function userId(Request $request): int
    {
        return (int) ($request->auth['user_id'] ?? 0);
    }

    protected function requireRole(Request $request, string ...$roles): void
    {
        $role = $request->auth['role'] ?? '';
        if (!in_array($role, $roles, true)) {
            throw HttpException::forbidden('Você não tem permissão para esta ação.');
        }
    }
}
