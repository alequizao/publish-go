<?php

declare(strict_types=1);

namespace PublishGo\Middleware;

use PublishGo\Core\HttpException;
use PublishGo\Core\Request;

/**
 * Proteção CSRF para fluxos baseados em cookie/sessão.
 *
 * A API principal do Publish Go é stateless e autenticada por Bearer JWT
 * (imune a CSRF, pois o token não é enviado automaticamente pelo navegador).
 * Este middleware fica disponível para rotas que venham a usar sessão/cookies:
 * valida o header X-CSRF-Token contra o token armazenado na sessão.
 */
final class CsrfMiddleware implements Middleware
{
    public function handle(Request $request): void
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $sessionToken = $_SESSION['csrf_token'] ?? null;
        $provided = $request->header('X-CSRF-Token');

        if ($sessionToken === null || $provided === null || !hash_equals((string) $sessionToken, $provided)) {
            throw HttpException::forbidden('Token CSRF inválido.');
        }
    }

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }
}
