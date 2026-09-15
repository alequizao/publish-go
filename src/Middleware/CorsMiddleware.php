<?php

declare(strict_types=1);

/*
 * Publish Go · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
namespace PublishGo\Middleware;

use PublishGo\Core\Env;
use PublishGo\Core\Request;

final class CorsMiddleware implements Middleware
{
    public function handle(Request $request): void
    {
        $origin = Env::get('CORS_ALLOW_ORIGIN', '*');
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
        header('Access-Control-Max-Age: 86400');
        header('Vary: Origin');
    }
}
