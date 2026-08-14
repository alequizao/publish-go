<?php

declare(strict_types=1);

namespace PublishGo\Middleware;

use PublishGo\Core\HttpException;
use PublishGo\Core\Jwt;
use PublishGo\Core\Request;

/**
 * Autentica o token do aplicativo do motoboy (scope 'courier').
 * Injeta courier_id e company_id na requisição.
 */
final class CourierAuthMiddleware implements Middleware
{
    public function handle(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw HttpException::unauthorized('Token de acesso ausente.');
        }
        $payload = Jwt::decode($token);
        if ($payload === null || ($payload['type'] ?? '') !== 'access') {
            throw HttpException::unauthorized('Token inválido ou expirado.');
        }
        if (($payload['scope'] ?? '') !== 'courier') {
            throw HttpException::forbidden('Token não pertence a um motoboy.');
        }

        $request->auth = [
            'courier_id' => (int) ($payload['sub'] ?? 0),
            'company_id' => (int) ($payload['company_id'] ?? 0),
            'scope' => 'courier',
            'name' => (string) ($payload['name'] ?? ''),
        ];

        if ($request->auth['courier_id'] <= 0 || $request->auth['company_id'] <= 0) {
            throw HttpException::unauthorized('Sessão inválida.');
        }
    }
}
