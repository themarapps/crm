<?php
declare(strict_types=1);

final class TaskService
{
    public static function list(PDO $db, array $requestQuery): never
    {
        $filters = self::listFilters($requestQuery);
        $sql = self::listSql($filters['conditions']);
        $taskQuery = $db->prepare($sql);
        $taskQuery->execute($filters['params']);
        Http::json(['data' => $taskQuery->fetchAll()]);
    }

    private static function listFilters(array $requestQuery): array
    {
        [$statusCondition, $statusParams] = self::statusFilter($requestQuery);
        [$dueCondition, $dueParams] = self::dueFilter($requestQuery);
        $conditions = array_filter([$statusCondition, $dueCondition]);
        return ['conditions' => $conditions, 'params' => array_merge($statusParams, $dueParams)];
    }

    private static function statusFilter(array $requestQuery): array
    {
        $status = $requestQuery['status'] ?? null;
        if (!is_string($status) || !in_array($status, ['open', 'done'], true)) {
            return [null, []];
        }
        return ['t.status = ?', [$status]];
    }

    private static function dueFilter(array $requestQuery): array
    {
        return match ($requestQuery['due'] ?? '') {
            'today' => self::todayFilter(),
            'overdue' => ['t.due_at < ? AND t.status = ?', [gmdate('Y-m-d H:i:s'), 'open']],
            'upcoming' => ['t.due_at >= ? AND t.status = ?', [gmdate('Y-m-d H:i:s'), 'open']],
            default => [null, []],
        };
    }

    private static function todayFilter(): array
    {
        [$start, $end] = Time::riyadhDayUtcBounds();
        return ['t.due_at BETWEEN ? AND ? AND t.status = ?', [$start, $end, 'open']];
    }

    private static function listSql(array $conditions): string
    {
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        return 'SELECT t.*, c.name AS customer_name, c.email AS customer_email, creator.name AS creator_name, assignee.name AS assignee_name FROM follow_up_tasks t JOIN customers c ON c.id = t.customer_id JOIN users creator ON creator.id = t.created_by LEFT JOIN users assignee ON assignee.id = t.assignee_id' . $where . " ORDER BY CASE WHEN t.status = 'open' THEN 0 ELSE 1 END, t.due_at ASC, t.id DESC LIMIT 500";
    }

    public static function save(PDO $db, array $requestFields, int $actorId, ?int $taskId = null): never
    {
        $taskFields = self::validatedTaskFields($db, $requestFields);
        $savedTaskId = self::persistTask($db, $taskFields, $actorId, $taskId);
        $taskQuery = $db->prepare('SELECT * FROM follow_up_tasks WHERE id = ?');
        $taskQuery->execute([$savedTaskId]);
        Http::json(['task' => $taskQuery->fetch()], $taskId === null ? 201 : 200);
    }

    private static function validatedTaskFields(PDO $db, array $requestFields): array
    {
        $customerId = self::validatedCustomerId($db, $requestFields);
        $assigneeId = self::validatedAssigneeId($db, $requestFields);
        $status = self::validatedStatus($requestFields);
        return [
            'customer_id' => $customerId,
            'assignee_id' => $assigneeId,
            'title' => Http::requiredString($requestFields, 'title', 200),
            'due_at' => self::validatedDueAt($requestFields),
            'status' => $status,
            'details' => self::optionalDetails($requestFields),
        ];
    }

    private static function validatedCustomerId(PDO $db, array $requestFields): int
    {
        $customerId = self::positiveId($requestFields, 'customer_id');
        $customerQuery = $db->prepare('SELECT 1 FROM customers WHERE id = ?');
        $customerQuery->execute([$customerId]);
        if (!$customerQuery->fetchColumn()) {
            Http::json(['error' => 'validation_error', 'fields' => ['customer_id' => 'العميل غير موجود.']], 422);
        }
        return $customerId;
    }

    private static function validatedAssigneeId(PDO $db, array $requestFields): ?int
    {
        $assigneeId = self::optionalId($requestFields, 'assignee_id');
        if ($assigneeId !== null && !self::activeUserExists($db, $assigneeId)) {
            Http::json(['error' => 'validation_error', 'fields' => ['assignee_id' => 'عضو الفريق غير موجود.']], 422);
        }
        return $assigneeId;
    }

    private static function positiveId(array $requestFields, string $field): int
    {
        $identifier = self::optionalId($requestFields, $field);
        if ($identifier === null || $identifier < 1) {
            Http::json(['error' => 'validation_error', 'fields' => [$field => 'اختر سجلًا صالحًا.']], 422);
        }
        return $identifier;
    }

    private static function optionalId(array $requestFields, string $field): ?int
    {
        $input = $requestFields[$field] ?? null;
        if ($input === null || $input === '') {
            return null;
        }
        if (!is_int($input) && !(is_string($input) && ctype_digit($input))) {
            Http::json(['error' => 'validation_error', 'fields' => [$field => 'المعرّف غير صالح.']], 422);
        }
        return (int) $input;
    }

    private static function activeUserExists(PDO $db, int $userId): bool
    {
        $query = $db->prepare('SELECT 1 FROM users WHERE id = ? AND is_active = 1');
        $query->execute([$userId]);
        return (bool) $query->fetchColumn();
    }

    private static function validatedDueAt(array $requestFields): string
    {
        $rawDueAt = $requestFields['due_at'] ?? null;
        if (!is_string($rawDueAt) || trim($rawDueAt) === '') {
            Http::json(['error' => 'validation_error', 'fields' => ['due_at' => 'أدخل موعدًا صالحًا.']], 422);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/', trim($rawDueAt))) {
            Http::json(['error' => 'validation_error', 'fields' => ['due_at' => 'أدخل موعدًا بصيغة ISO 8601.']], 422);
        }
        try {
            $dueDate = new DateTimeImmutable($rawDueAt, new DateTimeZone('Asia/Riyadh'));
        } catch (Exception) {
            Http::json(['error' => 'validation_error', 'fields' => ['due_at' => 'أدخل موعدًا صالحًا.']], 422);
        }
        if (DateTimeImmutable::getLastErrors()) {
            Http::json(['error' => 'validation_error', 'fields' => ['due_at' => 'أدخل موعدًا صالحًا.']], 422);
        }
        return $dueDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function validatedStatus(array $requestFields): string
    {
        $status = $requestFields['status'] ?? 'open';
        if (!is_string($status) || !in_array($status, ['open', 'done'], true)) {
            Http::json(['error' => 'validation_error', 'fields' => ['status' => 'حالة المهمة غير صالحة.']], 422);
        }
        return $status;
    }

    private static function optionalDetails(array $requestFields): ?string
    {
        $details = $requestFields['details'] ?? null;
        if ($details === null || $details === '') {
            return null;
        }
        if (!is_string($details) || mb_strlen($details) > 10000) {
            Http::json(['error' => 'validation_error', 'fields' => ['details' => 'تفاصيل المهمة طويلة أو غير صالحة.']], 422);
        }
        $normalizedDetails = trim($details);
        return $normalizedDetails === '' ? null : $normalizedDetails;
    }

    private static function persistTask(PDO $db, array $taskFields, int $actorId, ?int $taskId): int
    {
        return $taskId === null
            ? self::insertTask($db, $taskFields, $actorId)
            : self::updateTask($db, $taskFields, $taskId);
    }

    private static function insertTask(PDO $db, array $taskFields, int $actorId): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $completedAt = $taskFields['status'] === 'done' ? $now : null;
        $db->prepare('INSERT INTO follow_up_tasks (customer_id, created_by, assignee_id, title, due_at, status, details, completed_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $taskFields['customer_id'], $actorId, $taskFields['assignee_id'], $taskFields['title'],
                $taskFields['due_at'], $taskFields['status'], $taskFields['details'], $completedAt, $now, $now,
            ]);
        return (int) $db->lastInsertId();
    }

    private static function updateTask(PDO $db, array $taskFields, int $taskId): int
    {
        $previousTask = self::findTask($db, $taskId);
        if (!$previousTask) {
            Http::json(['error' => 'not_found'], 404);
        }
        $now = gmdate('Y-m-d H:i:s');
        $completedAt = self::completionTimestamp($taskFields['status'], $previousTask, $now);
        $taskValues = array_merge(array_values($taskFields), [$completedAt, $now, $taskId]);
        $db->prepare('UPDATE follow_up_tasks SET customer_id = ?, assignee_id = ?, title = ?, due_at = ?, status = ?, details = ?, completed_at = ?, updated_at = ? WHERE id = ?')->execute($taskValues);
        return $taskId;
    }

    private static function findTask(PDO $db, int $taskId): ?array
    {
        $taskQuery = $db->prepare('SELECT status, completed_at FROM follow_up_tasks WHERE id = ?');
        $taskQuery->execute([$taskId]);
        return $taskQuery->fetch() ?: null;
    }

    private static function completionTimestamp(string $status, array $previousTask, string $now): ?string
    {
        if ($status !== 'done') {
            return null;
        }
        if ($previousTask['status'] === 'done' && $previousTask['completed_at']) {
            return $previousTask['completed_at'];
        }
        return $now;
    }
}
