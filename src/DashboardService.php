<?php
declare(strict_types=1);

final class DashboardService
{
    public static function summary(PDO $db): never
    {
        Http::json([
            'counts' => self::counts($db),
            'upcoming_tasks' => self::upcomingTasks($db),
        ]);
    }

    private static function counts(PDO $db): array
    {
        $customers = (int) $db->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        $activeSubscriptions = (int) $db->query("SELECT COUNT(*) FROM customers WHERE LOWER(COALESCE(subscription_status, '')) = 'active'")->fetchColumn();
        $newCustomers = (int) $db->query("SELECT COUNT(*) FROM customers WHERE crm_stage = 'new'")->fetchColumn();
        return [
            'customers' => $customers,
            'active_subscriptions' => $activeSubscriptions,
            'new_customers' => $newCustomers,
            'overdue_tasks' => self::countTasksBeforeNow($db),
            'today_tasks' => self::countTodayTasks($db),
        ];
    }

    private static function countTasksBeforeNow(PDO $db): int
    {
        $query = $db->prepare("SELECT COUNT(*) FROM follow_up_tasks WHERE status = 'open' AND due_at < ?");
        $query->execute([gmdate('Y-m-d H:i:s')]);
        return (int) $query->fetchColumn();
    }

    private static function countTodayTasks(PDO $db): int
    {
        [$todayStart, $todayEnd] = Time::riyadhDayUtcBounds();
        $query = $db->prepare("SELECT COUNT(*) FROM follow_up_tasks WHERE status = 'open' AND due_at BETWEEN ? AND ?");
        $query->execute([$todayStart, $todayEnd]);
        return (int) $query->fetchColumn();
    }

    private static function upcomingTasks(PDO $db): array
    {
        $taskQuery = $db->prepare("SELECT t.id, t.customer_id, t.title, t.due_at, t.status, c.name AS customer_name, u.name AS assignee_name FROM follow_up_tasks t JOIN customers c ON c.id = t.customer_id LEFT JOIN users u ON u.id = t.assignee_id WHERE t.status = 'open' ORDER BY t.due_at ASC LIMIT 8");
        $taskQuery->execute();
        return $taskQuery->fetchAll();
    }
}
