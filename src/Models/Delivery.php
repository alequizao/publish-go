<?php

declare(strict_types=1);

namespace PublishGo\Models;

use PublishGo\Core\Database;

final class Delivery extends Model
{
    protected static string $table = 'deliveries';

    /** @return array<string,mixed>|null Entrega ativa de um pedido. */
    public static function activeForOrder(int $orderId): ?array
    {
        return Database::first(
            "SELECT * FROM deliveries WHERE order_id = :o
             AND status NOT IN ('delivered','canceled','rejected')
             ORDER BY id DESC LIMIT 1",
            ['o' => $orderId]
        );
    }

    /** Entrega com dados do pedido e do motoboy (para tracking). */
    public static function withContext(int $id, int $companyId): ?array
    {
        return Database::first(
            'SELECT d.*,
                    o.code AS order_code, o.customer_name, o.customer_phone, o.address, o.lat AS order_lat, o.lng AS order_lng,
                    c.name AS courier_name, c.phone AS courier_phone, c.lat AS courier_lat,
                    c.lng AS courier_lng, c.heading AS courier_heading
             FROM deliveries d
             JOIN orders o ON o.id = d.order_id
             LEFT JOIN couriers c ON c.id = d.courier_id
             WHERE d.id = :id AND d.company_id = :c LIMIT 1',
            ['id' => $id, 'c' => $companyId]
        );
    }
}
