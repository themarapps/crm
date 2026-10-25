<?php
declare(strict_types=1);

final class ActivityLog
{
    public static function record(PDO $db, int $customerId, ?int $userId, string $eventType, string $summary): void
    {
        $db->prepare('INSERT INTO customer_activity (customer_id, user_id, event_type, summary, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$customerId, $userId, $eventType, $summary, gmdate('Y-m-d H:i:s')]);
    }

    public static function forCustomer(PDO $db, int $customerId): array
    {
        $query = $db->prepare('SELECT a.id, a.event_type, a.summary, a.created_at, u.name AS actor_name FROM customer_activity a LEFT JOIN users u ON u.id = a.user_id WHERE a.customer_id = ? ORDER BY a.id DESC LIMIT 100');
        $query->execute([$customerId]);
        return $query->fetchAll();
    }
}
