<?php

declare(strict_types=1);

namespace PublishGo\Services;

use PublishGo\Core\Env;

/**
 * Publica eventos em tempo real para o servidor WebSocket (Workerman).
 *
 * A API HTTP é stateless; ela "empurra" eventos para o processo WS através de um
 * socket TCP interno protegido por segredo compartilhado. Se o WS estiver offline,
 * a publicação falha silenciosamente — o frontend continua via polling de fallback.
 */
final class RealtimeService
{
    /**
     * @param array<string,mixed> $payload
     */
    public static function publish(int $companyId, string $event, array $payload): void
    {
        $host = Env::get('WS_HOST', '127.0.0.1');
        if ($host === '0.0.0.0') {
            $host = '127.0.0.1';
        }
        $port = Env::int('WS_PORT', 8181);
        // O canal interno escuta na porta WS+1.
        $internalPort = $port + 1;

        $message = json_encode([
            'secret' => Env::get('WS_INTERNAL_SECRET', ''),
            'company_id' => $companyId,
            'event' => $event,
            'payload' => $payload,
            'ts' => time(),
        ], JSON_UNESCAPED_UNICODE);

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $internalPort, $errno, $errstr, 0.3);
        if ($socket === false) {
            return; // WS indisponível — fallback de polling cobre.
        }
        @fwrite($socket, $message . "\n");
        @fclose($socket);
    }
}
