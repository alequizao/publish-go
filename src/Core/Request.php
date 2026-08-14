<?php

declare(strict_types=1);

namespace PublishGo\Core;

/**
 * Abstração imutável da requisição HTTP de entrada.
 */
final class Request
{
    /** @var array<string,mixed> */
    private array $body;
    /** @var array<string,string> */
    private array $query;
    /** @var array<string,string> */
    private array $headers;
    /** @var array<string,string> */
    private array $params = [];

    /** Contexto de autenticação preenchido pelo AuthMiddleware. */
    public ?array $auth = null;

    /** Prefixo de base da URL (ex.: /publishgo) removido das rotas. */
    private static string $basePath = '';

    public static function setBasePath(string $base): void
    {
        self::$basePath = '/' . trim($base, '/');
        if (self::$basePath === '/') {
            self::$basePath = '';
        }
    }

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        array $body,
        array $query,
        array $headers,
    ) {
        $this->body = $body;
        $this->query = $query;
        $this->headers = $headers;
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        // Remove o prefixo de base (deploy em subpasta, ex.: /publishgo).
        if (self::$basePath !== '' && str_starts_with($path, self::$basePath)) {
            $path = substr($path, strlen(self::$basePath));
        }
        $path = '/' . trim($path, '/');

        $raw = file_get_contents('php://input') ?: '';
        $body = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }
        if (empty($body) && !empty($_POST)) {
            $body = $_POST;
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        // Authorization pode ser repassado de formas diferentes conforme o SAPI/proxy.
        if (!isset($headers['Authorization'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? null;
            if ($auth === null && function_exists('apache_request_headers')) {
                foreach (apache_request_headers() as $k => $v) {
                    if (strcasecmp($k, 'Authorization') === 0) {
                        $auth = $v;
                        break;
                    }
                }
            }
            if ($auth !== null) {
                $headers['Authorization'] = (string) $auth;
            }
        }

        return new self($method, $path, $body, $_GET ?? [], $headers);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->body;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function header(string $key, ?string $default = null): ?string
    {
        foreach ($this->headers as $name => $value) {
            if (strcasecmp($name, $key) === 0) {
                return $value;
            }
        }
        return $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization');
        if ($auth !== null && preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /** @param array<string,string> $params */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->params[$key] ?? $default;
    }

    public function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = explode(',', (string) $_SERVER[$key])[0];
                return trim($ip);
            }
        }
        return '0.0.0.0';
    }
}
