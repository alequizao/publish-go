<?php

declare(strict_types=1);

namespace PublishGo\Core;

/**
 * Respostas JSON padronizadas: { ok, data, error }.
 */
final class Response
{
    public static function json(mixed $data, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function success(mixed $data = null, int $status = 200, array $meta = []): void
    {
        $payload = ['ok' => true, 'data' => $data, 'error' => null];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        self::json($payload, $status);
    }

    public static function error(string $message, int $status = 400, array $details = []): void
    {
        $error = ['message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        self::json(['ok' => false, 'data' => null, 'error' => $error], $status);
    }

    public static function noContent(): void
    {
        http_response_code(204);
    }
}
