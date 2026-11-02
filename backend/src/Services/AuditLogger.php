<?php
declare(strict_types=1);
namespace SpiceMama\Services;
use PDO;

/** Writes inside the caller's transaction. Details are allowlisted summaries, never request bodies. */
final class AuditLogger
{
    public static function record(PDO $db, ?int $adminId, string $action, string $entityType, ?int $entityId, ?array $details, string $now): void
    {
        $db->prepare('INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,details_json,created_at) VALUES(?,?,?,?,?,?)')
            ->execute([$adminId, $action, $entityType, $entityId, $details === null ? null : json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $now]);
    }
}
