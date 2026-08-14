<?php

declare(strict_types=1);

namespace PublishGo\Models;

use PublishGo\Core\Database;

final class Order extends Model
{
    protected static string $table = 'orders';

    public const ACTIVE_STATUSES = ['received', 'preparing', 'ready', 'dispatched', 'picked'];

    /**
     * Lista pedidos com filtros opcionais.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function list(int $companyId, array $filters = []): array
    {
        $sql = 'SELECT * FROM orders WHERE company_id = :c';
        $params = ['c' => $companyId];

        if (!empty($filters['status'])) {
            $sql .= ' AND status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['active'])) {
            $in = "'" . implode("','", self::ACTIVE_STATUSES) . "'";
            $sql .= " AND status IN ({$in})";
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (customer_name LIKE :search OR code LIKE :search OR address LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $sql .= " ORDER BY FIELD(priority,'urgent','high','normal','low'), created_at DESC LIMIT 200";
        return Database::select($sql, $params);
    }

    /** Gera um código sequencial legível por empresa (ex.: #1042). */
    public static function nextCode(int $companyId): string
    {
        $row = Database::first(
            'SELECT COUNT(*) AS total FROM orders WHERE company_id = :c',
            ['c' => $companyId]
        );
        $n = (int) ($row['total'] ?? 0) + 1001;
        return (string) $n;
    }

    /** @return array<int,array<string,mixed>> */
    public static function items(int $orderId): array
    {
        return Database::select('SELECT * FROM order_items WHERE order_id = :o', ['o' => $orderId]);
    }

    public static function setStatus(int $id, int $companyId, string $status): int
    {
        $extra = '';
        if ($status === 'delivered') {
            $extra = ', delivered_at = NOW()';
        } elseif ($status === 'canceled') {
            $extra = ', canceled_at = NOW()';
        } elseif ($status === 'ready') {
            $extra = ', prepared_at = NOW()';
        }
        return Database::execute(
            "UPDATE orders SET status = :s {$extra} WHERE id = :id AND company_id = :c",
            ['s' => $status, 'id' => $id, 'c' => $companyId]
        );
    }
}
