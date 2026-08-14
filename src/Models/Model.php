<?php

declare(strict_types=1);

namespace PublishGo\Models;

use PublishGo\Core\Database;

/**
 * Base de modelos: helpers de acesso a dados escopados por empresa (multi-tenant).
 * Sempre via prepared statements.
 */
abstract class Model
{
    protected static string $table = '';

    /** @return array<string,mixed>|null */
    public static function find(int $id, ?int $companyId = null): ?array
    {
        $sql = 'SELECT * FROM ' . static::$table . ' WHERE id = :id';
        $params = ['id' => $id];
        if ($companyId !== null) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        $sql .= ' LIMIT 1';
        return Database::first($sql, $params);
    }

    /** @return array<int,array<string,mixed>> */
    public static function where(string $column, mixed $value): array
    {
        return Database::select(
            'SELECT * FROM ' . static::$table . " WHERE {$column} = :value",
            ['value' => $value]
        );
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn ($c) => ':' . $c, $columns);
        $sql = 'INSERT INTO ' . static::$table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        return Database::insert($sql, $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data, ?int $companyId = null): int
    {
        $sets = array_map(static fn ($c) => "{$c} = :{$c}", array_keys($data));
        $sql = 'UPDATE ' . static::$table . ' SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $data['id'] = $id;
        if ($companyId !== null) {
            $sql .= ' AND company_id = :company_id';
            $data['company_id'] = $companyId;
        }
        return Database::execute($sql, $data);
    }

    public static function delete(int $id, ?int $companyId = null): int
    {
        $sql = 'DELETE FROM ' . static::$table . ' WHERE id = :id';
        $params = ['id' => $id];
        if ($companyId !== null) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        return Database::execute($sql, $params);
    }
}
