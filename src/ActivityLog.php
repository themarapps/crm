<?php
declare(strict_types=1);

final class ActivityLog
{
    private const CRM_ACTIVITY_EVENTS = ['updated', 'bulk_updated', 'tags_updated', 'note', 'task_created', 'task_updated'];

    public static function record(PDO $db, int $customerId, ?int $userId, string $eventType, string $summary): void
    {
        $createdAt = gmdate('Y-m-d H:i:s');
        $db->prepare('INSERT INTO customer_activity (customer_id, user_id, event_type, summary, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$customerId, $userId, $eventType, $summary, $createdAt]);
        if (in_array($eventType, self::CRM_ACTIVITY_EVENTS, true)) {
            $db->prepare('UPDATE customers SET crm_last_activity_at = ?, updated_at = ? WHERE id = ?')->execute([$createdAt, $createdAt, $customerId]);
        }
    }

    public static function forCustomer(PDO $db, int $customerId): array
    {
        $query = $db->prepare('SELECT a.id, a.event_type, a.summary, a.created_at, u.name AS actor_name FROM customer_activity a LEFT JOIN users u ON u.id = a.user_id WHERE a.customer_id = ? ORDER BY a.id DESC LIMIT 100');
        $query->execute([$customerId]);
        return $query->fetchAll();
    }
}
