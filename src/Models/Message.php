<?php

declare(strict_types=1);

namespace PublishGo\Models;

use PublishGo\Core\Database;

final class Message extends Model
{
    protected static string $table = 'messages';

    /** @return array<int,array<string,mixed>> */
    public static function forDelivery(int $deliveryId, int $afterId = 0): array
    {
        $rows = Database::select(
            'SELECT id, sender, body, created_at FROM messages
             WHERE delivery_id = :d AND id > :after ORDER BY id ASC LIMIT 200',
            ['d' => $deliveryId, 'after' => $afterId]
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
        }
        return $rows;
    }

    public static function markRead(int $deliveryId, string $readerSide): void
    {
        // Marca como lidas as mensagens enviadas pela outra parte.
        $other = $readerSide === 'establishment' ? 'courier' : 'establishment';
        Database::execute(
            'UPDATE messages SET read_at = NOW() WHERE delivery_id = :d AND sender = :s AND read_at IS NULL',
            ['d' => $deliveryId, 's' => $other]
        );
    }
}
