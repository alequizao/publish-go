<?php

declare(strict_types=1);

namespace PublishGo\Models;

use PublishGo\Core\Database;

final class AuditLog extends Model
{
    protected static string $table = 'audit_logs';

    public static function record(
        ?int $companyId,
        ?int $userId,
        string $action,
        ?string $entity = null,
        ?int $entityId = null,
        ?string $ip = null,
        array $meta = []
    ): void {
        Database::insert(
            'INSERT INTO audit_logs (company_id, user_id, action, entity, entity_id, ip, meta)
             VALUES (:company_id, :user_id, :action, :entity, :entity_id, :ip, :meta)',
            [
                'company_id' => $companyId,
                'user_id' => $userId,
                'action' => $action,
                'entity' => $entity,
                'entity_id' => $entityId,
                'ip' => $ip,
                'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]
        );
    }
}
