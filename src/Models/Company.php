<?php

declare(strict_types=1);

namespace PublishGo\Models;

use PublishGo\Core\Database;

final class Company extends Model
{
    protected static string $table = 'companies';

    /** @return array<string,mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        return Database::first('SELECT * FROM companies WHERE slug = :slug LIMIT 1', ['slug' => $slug]);
    }

    /** Dados públicos de tema (whitelabel) para o frontend. */
    public static function publicTheme(int $id): ?array
    {
        $row = self::find($id);
        if ($row === null) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'logo_url' => $row['logo_url'],
            'primary_color' => $row['primary_color'],
            'accent_color' => $row['accent_color'],
            'theme' => $row['theme'],
        ];
    }
}
