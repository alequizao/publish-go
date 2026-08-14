<?php

declare(strict_types=1);

namespace PublishGo\Models;

use PublishGo\Core\Database;

final class User extends Model
{
    protected static string $table = 'users';

    /** @return array<string,mixed>|null */
    public static function findByEmail(string $email): ?array
    {
        return Database::first('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
    }

    public static function touchLogin(int $id): void
    {
        Database::execute('UPDATE users SET last_login_at = NOW() WHERE id = :id', ['id' => $id]);
    }

    /** Remove campos sensíveis para exposição via API. */
    public static function publicData(array $row): array
    {
        unset($row['password_hash']);
        $row['id'] = (int) $row['id'];
        $row['company_id'] = (int) $row['company_id'];
        return $row;
    }
}
