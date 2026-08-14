<?php

declare(strict_types=1);

namespace PublishGo\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Conexão PDO singleton + helpers de query com prepared statements.
 * Todo acesso a dados passa por aqui — proteção contra SQL Injection por padrão.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::get('DB_DATABASE', 'publishgo');
        $charset = Env::get('DB_CHARSET', 'utf8mb4');
        $user = Env::get('DB_USERNAME', 'publishgo');
        $pass = Env::get('DB_PASSWORD', 'publishgo');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Falha ao conectar ao banco de dados: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    /** Permite injetar uma conexão alternativa (ex.: instalador com credenciais root). */
    public static function setConnection(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function select(string $sql, array $params = []): array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function first(string $sql, array $params = []): ?array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Executa um INSERT/UPDATE/DELETE e retorna o número de linhas afetadas.
     *
     * @param array<string,mixed> $params
     */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Insere e retorna o ID gerado.
     *
     * @param array<string,mixed> $params
     */
    public static function insert(string $sql, array $params = []): int
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return (int) self::connection()->lastInsertId();
    }

    public static function beginTransaction(): void
    {
        self::connection()->beginTransaction();
    }

    public static function commit(): void
    {
        self::connection()->commit();
    }

    public static function rollBack(): void
    {
        if (self::connection()->inTransaction()) {
            self::connection()->rollBack();
        }
    }
}
