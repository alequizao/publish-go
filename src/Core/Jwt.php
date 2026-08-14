<?php

declare(strict_types=1);

namespace PublishGo\Core;

/**
 * Implementação enxuta de JWT (HS256) sem dependências externas.
 */
final class Jwt
{
    private static function secret(): string
    {
        return (string) Env::get('JWT_SECRET', 'publishgo-dev-secret');
    }

    private static function b64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }

    /**
     * @param array<string,mixed> $claims
     */
    public static function encode(array $claims, int $ttl, string $type = 'access'): string
    {
        $now = time();
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload = array_merge($claims, [
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'type' => $type,
            'jti' => bin2hex(random_bytes(8)),
        ]);

        $segments = [
            self::b64UrlEncode((string) json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::b64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, self::secret(), true);
        $segments[] = self::b64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * Decodifica e valida assinatura + expiração.
     *
     * @return array<string,mixed>|null  Payload válido, ou null se inválido/expirado.
     */
    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$head64, $payload64, $sig64] = $parts;

        $signingInput = $head64 . '.' . $payload64;
        $expected = hash_hmac('sha256', $signingInput, self::secret(), true);
        $provided = self::b64UrlDecode($sig64);

        if (!hash_equals($expected, $provided)) {
            return null;
        }

        $payload = json_decode(self::b64UrlDecode($payload64), true);
        if (!is_array($payload)) {
            return null;
        }

        $now = time();
        if (isset($payload['nbf']) && $now < (int) $payload['nbf']) {
            return null;
        }
        if (isset($payload['exp']) && $now >= (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    /** @param array<string,mixed> $claims */
    public static function issueAccess(array $claims): string
    {
        return self::encode($claims, Env::int('JWT_TTL', 3600), 'access');
    }

    /** @param array<string,mixed> $claims */
    public static function issueRefresh(array $claims): string
    {
        return self::encode($claims, Env::int('JWT_REFRESH_TTL', 1209600), 'refresh');
    }
}
