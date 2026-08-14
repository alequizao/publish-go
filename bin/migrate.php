<?php

declare(strict_types=1);

/**
 * Runner de migrations do Publish Go.
 *
 *   php bin/migrate.php
 *
 * Se DB_ROOT_USERNAME estiver definido no .env, cria o banco e o usuário da aplicação
 * caso ainda não existam. Em seguida aplica todas as migrations pendentes.
 */

use PublishGo\Core\Database;
use PublishGo\Core\Env;
use PublishGo\Core\Migration;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$host = Env::get('DB_HOST', '127.0.0.1');
$port = Env::get('DB_PORT', '3306');
$name = Env::get('DB_DATABASE', 'publishgo');
$user = Env::get('DB_USERNAME', 'publishgo');
$pass = Env::get('DB_PASSWORD', 'publishgo');

$rootUser = Env::get('DB_ROOT_USERNAME', '');
$rootPass = Env::get('DB_ROOT_PASSWORD', '');

fwrite(STDOUT, "→ Publish Go — migrações\n");

// Passo opcional: provisionar banco + usuário com conta privilegiada.
if ($rootUser !== '' && $rootUser !== null) {
    try {
        $rootPdo = new PDO("mysql:host={$host};port={$port}", $rootUser, $rootPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $rootPdo->exec("CREATE USER IF NOT EXISTS '{$user}'@'%' IDENTIFIED BY '{$pass}'");
        $rootPdo->exec("CREATE USER IF NOT EXISTS '{$user}'@'localhost' IDENTIFIED BY '{$pass}'");
        $rootPdo->exec("GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$user}'@'%'");
        $rootPdo->exec("GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$user}'@'localhost'");
        $rootPdo->exec('FLUSH PRIVILEGES');
        fwrite(STDOUT, "✓ Banco e usuário provisionados.\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "⚠ Não foi possível provisionar via conta root: {$e->getMessage()}\n");
        fwrite(STDERR, "  Assumindo que o banco já existe e seguindo adiante.\n");
    }
}

try {
    Database::connection();
    fwrite(STDOUT, "✓ Conectado em {$name}@{$host}.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "✗ Falha de conexão: {$e->getMessage()}\n");
    exit(1);
}

try {
    $migration = new Migration(__DIR__ . '/../database/migrations');
    $log = $migration->run();
    foreach ($log as $line) {
        fwrite(STDOUT, "  {$line}\n");
    }
    fwrite(STDOUT, "✓ Migrações concluídas.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "✗ {$e->getMessage()}\n");
    exit(1);
}
