<?php

declare(strict_types=1);

namespace PublishGo\Models;

use PublishGo\Core\Database;

final class Courier extends Model
{
    protected static string $table = 'couriers';

    /** Busca motoboy por telefone ou e-mail (login do app). @return array<string,mixed>|null */
    public static function findByLogin(string $login): ?array
    {
        $digits = preg_replace('/\D+/', '', $login) ?? '';
        return Database::first(
            "SELECT * FROM couriers
             WHERE email = :login_email
                OR phone = :login_phone
                OR REPLACE(REPLACE(REPLACE(REPLACE(phone,'(',''),')',''),'-',''),' ','') = :digits
             LIMIT 1",
            ['login_email' => $login, 'login_phone' => $login, 'digits' => $digits]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function forCompany(int $companyId): array
    {
        return Database::select(
            'SELECT * FROM couriers WHERE company_id = :c ORDER BY status = \'online\' DESC, name ASC',
            ['c' => $companyId]
        );
    }

    /** @return array<int,array<string,mixed>> Motoboys disponíveis (online, verificados). */
    public static function online(int $companyId): array
    {
        return Database::select(
            "SELECT * FROM couriers
             WHERE company_id = :c AND status = 'online' AND lat IS NOT NULL AND lng IS NOT NULL
             ORDER BY last_seen_at DESC",
            ['c' => $companyId]
        );
    }

    public static function updateLocation(int $id, int $companyId, float $lat, float $lng, ?int $heading): int
    {
        return Database::execute(
            'UPDATE couriers SET lat = :lat, lng = :lng, heading = :heading, last_seen_at = NOW()
             WHERE id = :id AND company_id = :c',
            ['lat' => $lat, 'lng' => $lng, 'heading' => $heading, 'id' => $id, 'c' => $companyId]
        );
    }

    public static function setStatus(int $id, int $companyId, string $status): int
    {
        return Database::execute(
            'UPDATE couriers SET status = :s, last_seen_at = NOW() WHERE id = :id AND company_id = :c',
            ['s' => $status, 'id' => $id, 'c' => $companyId]
        );
    }
}
