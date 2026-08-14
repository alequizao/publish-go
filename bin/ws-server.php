<?php

declare(strict_types=1);

/**
 * Servidor WebSocket do Publish Go (Workerman).
 *
 * Um único processo expõe dois sockets para compartilhar o estado das conexões:
 *   1) WebSocket público (WS_PORT)              → navegadores conectam e se inscrevem por empresa.
 *   2) TCP interno (WS_PORT + 1, só 127.0.0.1)  → a API HTTP publica eventos (protegido por segredo).
 *
 * Uso:
 *   php bin/ws-server.php start          (primeiro plano)
 *   php bin/ws-server.php start -d       (daemon)
 *   php bin/ws-server.php stop|restart|status
 *
 * Protocolo do cliente (JSON via WebSocket):
 *   { "type": "auth", "token": "<JWT access>" }   → inscreve o socket no canal da empresa
 *   { "type": "ping" }                            → servidor responde { "type": "pong" }
 *
 * Mensagens enviadas pelo servidor:
 *   { "type": "event", "event": "...", "payload": {...}, "ts": 0 }
 */

use PublishGo\Core\Env;
use PublishGo\Core\Jwt;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

require __DIR__ . '/../vendor/autoload.php';
Env::load(__DIR__ . '/../.env');

$wsHost = Env::get('WS_HOST', '0.0.0.0');
$wsPort = Env::int('WS_PORT', 8181);
$internalPort = $wsPort + 1;
$internalSecret = (string) Env::get('WS_INTERNAL_SECRET', '');

$ws = new Worker("websocket://{$wsHost}:{$wsPort}");
$ws->name = 'PublishGo-WS';
$ws->count = 1; // processo único: o socket interno compartilha as conexões em memória.

$ws->onConnect = static function (TcpConnection $connection) {
    $connection->companyId = 0;
};

$ws->onMessage = static function (TcpConnection $connection, $data) {
    $msg = json_decode((string) $data, true);
    if (!is_array($msg)) {
        return;
    }
    $type = $msg['type'] ?? '';

    if ($type === 'auth') {
        $payload = Jwt::decode((string) ($msg['token'] ?? ''));
        if ($payload === null || ($payload['type'] ?? '') !== 'access') {
            $connection->send(json_encode(['type' => 'auth', 'ok' => false, 'error' => 'invalid_token']));
            $connection->close();
            return;
        }
        $connection->companyId = (int) ($payload['company_id'] ?? 0);
        $connection->send(json_encode(['type' => 'auth', 'ok' => true, 'company_id' => $connection->companyId]));
        return;
    }

    if ($type === 'ping') {
        $connection->send(json_encode(['type' => 'pong', 'ts' => time()]));
    }
};

// Sobe o listener interno dentro do mesmo processo do worker WS.
$ws->onWorkerStart = static function (Worker $worker) use ($internalPort, $internalSecret) {
    $internal = new Worker("tcp://127.0.0.1:{$internalPort}");
    $internal->onMessage = static function (TcpConnection $conn, $data) use ($worker, $internalSecret) {
        foreach (explode("\n", (string) $data) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $msg = json_decode($line, true);
            if (!is_array($msg) || !hash_equals($internalSecret, (string) ($msg['secret'] ?? ''))) {
                continue;
            }

            $companyId = (int) ($msg['company_id'] ?? 0);
            $frame = json_encode([
                'type' => 'event',
                'event' => $msg['event'] ?? 'unknown',
                'payload' => $msg['payload'] ?? [],
                'ts' => $msg['ts'] ?? time(),
            ], JSON_UNESCAPED_UNICODE);

            $delivered = 0;
            foreach ($worker->connections as $client) {
                if (($client->companyId ?? 0) === $companyId) {
                    $client->send($frame);
                    $delivered++;
                }
            }
            $conn->send(json_encode(['ok' => true, 'delivered' => $delivered]));
        }
        $conn->close();
    };
    $internal->listen();
};

Worker::runAll();
