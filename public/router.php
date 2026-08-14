<?php

declare(strict_types=1);

/**
 * Router para o servidor embutido do PHP (php -S ... router.php).
 * Serve arquivos estáticos diretamente e direciona o resto para index.php (API)
 * ou para as páginas do painel.
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicDir = __DIR__;

// Arquivo estático existente → deixa o servidor embutido servir.
$file = realpath($publicDir . $uri);
if ($file !== false && is_file($file) && str_starts_with($file, $publicDir)) {
    return false;
}

// Rotas da API.
if (str_starts_with($uri, '/api')) {
    require __DIR__ . '/index.php';
    return true;
}

// Raiz → painel do estabelecimento.
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/app/index.html';
    return true;
}

// Caminhos /app/* sem extensão → tenta .html correspondente; senão dashboard (SPA-like).
if (str_starts_with($uri, '/app')) {
    $candidate = $publicDir . $uri;
    if (is_file($candidate)) {
        return false;
    }
    require __DIR__ . '/app/index.html';
    return true;
}

http_response_code(404);
echo 'Not found';
return true;
