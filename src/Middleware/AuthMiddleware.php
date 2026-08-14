<?php

declare(strict_types=1);

namespace PublishGo\Middleware;

use PublishGo\Core\HttpException;
use PublishGo\Core\Jwt;
use PublishGo\Core\Request;

/**
 * Valida o token JWT (Bearer) e injeta o contexto de autenticação na requisição,
 * incluindo o company_id para isolamento multi-tenant.
 */
final class AuthMiddleware implements Middleware
{
    public function handle(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw HttpException::unauthorized('Token de acesso ausente.');
        }

        $payload = Jwt::decode($token);
        if ($payload === null) {
            throw HttpException::unauthorized('Token inválido ou expirado.');
        }
        if (($payload['type'] ?? null) !== 'access') {
            throw HttpException::unauthorized('Tipo de token inválido.');
        }
        if (($payload['scope'] ?? 'user') === 'courier') {
            throw HttpException::forbidden('Use o aplicativo do motoboy.');
        }

        $request->auth = [
            'user_id' => (int) ($payload['sub'] ?? 0),
            'company_id' => (int) ($payload['company_id'] ?? 0),
            'role' => (string) ($payload['role'] ?? 'establishment'),
            'name' => (string) ($payload['name'] ?? ''),
        ];

        if ($request->auth['user_id'] <= 0 || $request->auth['company_id'] <= 0) {
            throw HttpException::unauthorized('Sessão inválida.');
        }
    }
}
