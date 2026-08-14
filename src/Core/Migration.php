<?php

declare(strict_types=1);

namespace PublishGo\Core;

use PDO;

/**
 * Runner de migrations baseado em arquivos .sql versionados.
 * Mantém o controle das migrations já aplicadas na tabela `migrations`.
 */
final class Migration
{
    public function __construct(private readonly string $migrationsPath)
    {
    }

    public function run(): array
    {
        $pdo = Database::connection();
        $this->ensureMigrationsTable($pdo);

        $applied = $this->appliedMigrations($pdo);
        $files = glob($this->migrationsPath . '/*.sql') ?: [];
        sort($files);

        $log = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                $log[] = "↷ pulada: {$name}";
                continue;
            }

            $sql = file_get_contents($file) ?: '';
            $statements = $this->splitStatements($sql);

            // DDL no MySQL faz commit implícito — não envolvemos em transação.
            try {
                foreach ($statements as $statement) {
                    if (trim($statement) === '') {
                        continue;
                    }
                    $pdo->exec($statement);
                }
                $stmt = $pdo->prepare('INSERT INTO migrations (name, applied_at) VALUES (?, NOW())');
                $stmt->execute([$name]);
                $log[] = "✓ aplicada: {$name}";
            } catch (\Throwable $e) {
                $log[] = "✗ erro em {$name}: " . $e->getMessage();
                throw new \RuntimeException("Falha na migration {$name}: " . $e->getMessage(), 0, $e);
            }
        }

        return $log;
    }

    private function ensureMigrationsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(191) NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_migration_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return string[] */
    private function appliedMigrations(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_map('strval', $rows);
    }

    /**
     * Divide o arquivo SQL em statements respeitando strings e comentários simples.
     *
     * @return string[]
     */
    private function splitStatements(string $sql): array
    {
        // Remove comentários de linha (-- ...) preservando conteúdo de string seria complexo;
        // como as migrations são controladas, usamos divisão por ';' em fim de linha.
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*[\r\n]/', $sql) ?: [];
        return array_map('trim', $parts);
    }
}
