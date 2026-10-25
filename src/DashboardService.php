<?php
declare(strict_types=1);

final class DashboardService
{
    public static function summary(PDO $db, array $user): never
    {
        Http::json([
            'counts' => self::counts($db, $user),
            'upcoming_tasks' => self::tasks($db, $user, false),
            'overdue_tasks' => self::tasks($db, $user, true),
            'inactive_customers' => self::inactiveCustomers($db, $user),
        ]);
    }

    private static function counts(PDO $db, array $user): array
    {
        $member = !CustomerAccess::canViewAll($user);
        $customerWhere = $member ? ' WHERE assigned_to = ?' : '';
        $customerParams = $member ? [(int) $user['id']] : [];
        $customersQuery = $db->prepare('SELECT COUNT(*) FROM customers' . $customerWhere);
        $customersQuery->execute($customerParams);
        $activeQuery = $db->prepare("SELECT COUNT(*) FROM customers WHERE LOWER(COALESCE(subscription_status, '')) = 'active'" . ($member ? ' AND assigned_to = ?' : ''));
        $activeQuery->execute($customerParams);
        $newQuery = $db->prepare("SELECT COUNT(*) FROM customers WHERE crm_stage = 'new'" . ($member ? ' AND assigned_to = ?' : ''));
        $newQuery->execute($customerParams);
        $customers = (int) $customersQuery->fetchColumn();
        $activeSubscriptions = (int) $activeQuery->fetchColumn();
        $newCustomers = (int) $newQuery->fetchColumn();
        return [
            'customers' => $customers,
            'active_subscriptions' => $activeSubscriptions,
            'new_customers' => $newCustomers,
            'overdue_tasks' => self::countTasksBeforeNow($db, $user),
            'today_tasks' => self::countTodayTasks($db, $user),
        ];
    }

    private static function taskScope(array $user, string $alias = ''): array
    {
        if (CustomerAccess::canViewAll($user)) return ['', []];
        $prefix = $alias === '' ? '' : $alias . '.';
        if ($user['role'] !== 'member') {
            return [' AND ' . $prefix . 'customer_id IN (SELECT id FROM customers WHERE assigned_to = ?)', [(int) $user['id']]];
        }
        return [' AND (' . $prefix . 'assignee_id = ? OR ' . $prefix . 'customer_id IN (SELECT id FROM customers WHERE assigned_to = ?))', [(int) $user['id'], (int) $user['id']]];
    }

    private static function countTasksBeforeNow(PDO $db, array $user): int
    {
        [$scope, $scopeParams] = self::taskScope($user);
        $query = $db->prepare("SELECT COUNT(*) FROM follow_up_tasks WHERE status = 'open' AND due_at < ?" . $scope);
        $query->execute(array_merge([gmdate('Y-m-d H:i:s')], $scopeParams));
        return (int) $query->fetchColumn();
    }

    private static function countTodayTasks(PDO $db, array $user): int
    {
        [$todayStart, $todayEnd] = Time::riyadhDayUtcBounds();
        [$scope, $scopeParams] = self::taskScope($user);
        $query = $db->prepare("SELECT COUNT(*) FROM follow_up_tasks WHERE status = 'open' AND due_at BETWEEN ? AND ?" . $scope);
        $query->execute(array_merge([$todayStart, $todayEnd], $scopeParams));
        return (int) $query->fetchColumn();
    }

    private static function tasks(PDO $db, array $user, bool $overdue): array
    {
        [$scope, $scopeParams] = self::taskScope($user, 't');
        $due = $overdue ? ' AND t.due_at < ?' : ' AND t.due_at >= ?';
        $params = array_merge([gmdate('Y-m-d H:i:s')], $scopeParams);
        $taskQuery = $db->prepare("SELECT t.id, t.customer_id, t.title, t.due_at, t.status, c.name AS customer_name, u.name AS assignee_name FROM follow_up_tasks t JOIN customers c ON c.id = t.customer_id LEFT JOIN users u ON u.id = t.assignee_id WHERE t.status = 'open'" . $due . $scope . ' ORDER BY t.due_at ASC LIMIT 8');
        $taskQuery->execute($params);
        return $taskQuery->fetchAll();
    }

    private static function inactiveCustomers(PDO $db, array $user): array
    {
        $scope = CustomerAccess::canViewAll($user) ? '' : ' AND c.assigned_to = ?';
        $params = [gmdate('Y-m-d H:i:s', time() - 14 * 86400)];
        if (!CustomerAccess::canViewAll($user)) $params[] = (int) $user['id'];
        $query = $db->prepare("SELECT c.id, c.name, c.crm_stage, c.assigned_to, COALESCE(c.last_activity_at, c.registered_at, c.created_at) AS last_contact_at FROM customers c WHERE COALESCE(c.last_activity_at, c.registered_at, c.created_at) < ?" . $scope . ' ORDER BY last_contact_at ASC LIMIT 8');
        $query->execute($params);
        return $query->fetchAll();
    }
}
