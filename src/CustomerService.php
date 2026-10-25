<?php
declare(strict_types=1);

final class CustomerService
{
    private const FIELDS = [
        'name', 'email', 'phone', 'account_type', 'company_url', 'registered_at',
        'email_verified', 'plan', 'billing_cycle', 'subscription_status',
        'payment_status', 'last_amount', 'currency', 'source_stage', 'services',
        'service_count', 'crm_stage', 'assigned_to', 'last_activity_at', 'error_count',
    ];

    private const STAGES = ['new', 'contacted', 'interested', 'not_interested'];

    public static function list(PDO $db, array $requestQuery): never
    {
        [$conditions, $queryParams] = self::listConditions($requestQuery);
        $whereClause = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $page = max(1, (int) ($requestQuery['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($requestQuery['per_page'] ?? 25)));
        $total = self::countCustomers($db, $whereClause, $queryParams);
        $customers = self::fetchCustomers($db, [
            'where' => $whereClause,
            'params' => $queryParams,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
        Http::json(['data' => $customers, 'page' => $page, 'per_page' => $pageSize, 'total' => $total]);
    }

    private static function listConditions(array $requestQuery): array
    {
        $conditions = [];
        $queryParams = [];
        [$searchConditions, $searchParams] = self::searchCondition($requestQuery);
        [$exactConditions, $exactParams] = self::exactConditions($requestQuery);
        $conditions = array_merge($searchConditions, $exactConditions);
        $queryParams = array_merge($searchParams, $exactParams);
        return [$conditions, $queryParams];
    }

    private static function searchCondition(array $requestQuery): array
    {
        $searchTerm = self::queryString($requestQuery, 'q');
        if ($searchTerm === '') {
            return [[], []];
        }
        $condition = '(name LIKE ? OR email LIKE ? OR phone LIKE ?)';
        $searchPattern = '%' . $searchTerm . '%';
        return [[$condition], [$searchPattern, $searchPattern, $searchPattern]];
    }

    private static function exactConditions(array $requestQuery): array
    {
        $conditions = [];
        $queryParams = [];
        foreach (['subscription_status' => 'subscription_status', 'plan' => 'plan', 'stage' => 'crm_stage', 'assigned_to' => 'assigned_to'] as $filter => $column) {
            $filterValue = self::queryString($requestQuery, $filter);
            if ($filterValue === '') {
                continue;
            }
            $conditions[] = $column . ' = ?';
            $queryParams[] = $filterValue;
        }
        return [$conditions, $queryParams];
    }

    public static function filterOptions(PDO $db): never
    {
        Http::json([
            'plans' => self::distinctValues($db, 'plan'),
            'subscription_statuses' => self::distinctValues($db, 'subscription_status'),
            'stages' => self::distinctValues($db, 'crm_stage'),
        ]);
    }

    private static function distinctValues(PDO $db, string $column): array
    {
        $query = $db->query('SELECT DISTINCT ' . $column . ' FROM customers WHERE ' . $column . ' IS NOT NULL AND ' . $column . " != '' ORDER BY " . $column . ' LIMIT 100');
        return array_column($query->fetchAll(), $column);
    }

    private static function queryString(array $requestQuery, string $key): string
    {
        $input = $requestQuery[$key] ?? '';
        return is_string($input) ? trim($input) : '';
    }

    private static function countCustomers(PDO $db, string $whereClause, array $queryParams): int
    {
        $countQuery = $db->prepare('SELECT COUNT(*) FROM customers' . $whereClause);
        $countQuery->execute($queryParams);
        return (int) $countQuery->fetchColumn();
    }

    private static function fetchCustomers(PDO $db, array $listQuery): array
    {
        $offset = ($listQuery['page'] - 1) * $listQuery['page_size'];
        $sql = 'SELECT * FROM customers' . $listQuery['where'] . ' ORDER BY updated_at DESC, id DESC LIMIT ' . $listQuery['page_size'] . ' OFFSET ' . $offset;
        $customerQuery = $db->prepare($sql);
        $customerQuery->execute($listQuery['params']);
        return $customerQuery->fetchAll();
    }

    public static function show(PDO $db, int $customerId): never
    {
        $customer = self::findCustomer($db, $customerId);
        if (!$customer) {
            Http::json(['error' => 'not_found'], 404);
        }
        Http::json([
            'customer' => $customer,
            'notes' => self::customerNotes($db, $customerId),
            'tasks' => self::customerTasks($db, $customerId),
        ]);
    }

    public static function notes(PDO $db, int $customerId): never
    {
        if (!self::customerExists($db, $customerId)) {
            Http::json(['error' => 'not_found'], 404);
        }
        Http::json(['data' => self::customerNotes($db, $customerId)]);
    }

    private static function findCustomer(PDO $db, int $customerId): ?array
    {
        $customerQuery = $db->prepare('SELECT * FROM customers WHERE id = ?');
        $customerQuery->execute([$customerId]);
        return $customerQuery->fetch() ?: null;
    }

    private static function customerNotes(PDO $db, int $customerId): array
    {
        $noteQuery = $db->prepare('SELECT n.id, n.body, n.created_at, u.name AS author FROM crm_notes n JOIN users u ON u.id = n.user_id WHERE n.customer_id = ? ORDER BY n.id DESC LIMIT 100');
        $noteQuery->execute([$customerId]);
        return $noteQuery->fetchAll();
    }

    private static function customerTasks(PDO $db, int $customerId): array
    {
        $taskQuery = $db->prepare('SELECT t.*, creator.name AS creator_name, assignee.name AS assignee_name FROM follow_up_tasks t JOIN users creator ON creator.id = t.created_by LEFT JOIN users assignee ON assignee.id = t.assignee_id WHERE t.customer_id = ? ORDER BY t.due_at ASC, t.id DESC');
        $taskQuery->execute([$customerId]);
        return $taskQuery->fetchAll();
    }

    public static function save(PDO $db, array $requestFields, ?int $customerId = null): never
    {
        $customerFields = self::validatedCustomerFields($db, $requestFields);
        try {
            $savedId = self::persistCustomer($db, $customerFields, $customerId);
        } catch (PDOException $exception) {
            if (self::isUniqueConstraintViolation($exception)) {
                Http::json(['error' => 'duplicate_email', 'fields' => ['email' => 'هذا البريد مسجل لعميل آخر.']], 409);
            }
            throw $exception;
        }
        $savedCustomer = self::findCustomer($db, $savedId);
        Http::json(['customer' => $savedCustomer], $customerId === null ? 201 : 200);
    }

    private static function validatedCustomerFields(PDO $db, array $requestFields): array
    {
        $customerFields = [];
        foreach (self::FIELDS as $field) {
            $customerFields[$field] = self::customerField($db, $requestFields, $field);
        }
        return $customerFields;
    }

    private static function customerField(PDO $db, array $requestFields, string $field): mixed
    {
        return match ($field) {
            'name' => Http::requiredString($requestFields, 'name', 200),
            'email' => self::validatedEmail($requestFields),
            'crm_stage' => self::validatedStage($requestFields),
            'assigned_to' => self::validatedAssignee($db, $requestFields),
            'service_count' => self::optionalInteger($requestFields, $field),
            'error_count' => self::optionalInteger($requestFields, $field) ?? 0,
            'services' => self::optionalText($requestFields, $field, 10000),
            default => self::optionalText($requestFields, $field, 255),
        };
    }

    private static function validatedEmail(array $requestFields): string
    {
        $email = self::optionalText($requestFields, 'email', 255);
        $normalizedEmail = strtolower((string) $email);
        if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            Http::json(['error' => 'validation_error', 'fields' => ['email' => 'أدخل بريدًا إلكترونيًا صالحًا.']], 422);
        }
        return $normalizedEmail;
    }

    private static function validatedStage(array $requestFields): string
    {
        $stage = self::optionalText($requestFields, 'crm_stage', 40) ?? 'new';
        if (!in_array($stage, self::STAGES, true)) {
            Http::json(['error' => 'validation_error', 'fields' => ['crm_stage' => 'مرحلة المتابعة غير صالحة.']], 422);
        }
        return $stage;
    }

    private static function validatedAssignee(PDO $db, array $requestFields): ?int
    {
        $assigneeId = self::optionalInteger($requestFields, 'assigned_to');
        if ($assigneeId === null) {
            return null;
        }
        $assigneeQuery = $db->prepare('SELECT 1 FROM users WHERE id = ? AND is_active = 1');
        $assigneeQuery->execute([$assigneeId]);
        if (!$assigneeQuery->fetchColumn()) {
            Http::json(['error' => 'validation_error', 'fields' => ['assigned_to' => 'عضو الفريق غير موجود.']], 422);
        }
        return $assigneeId;
    }

    private static function optionalInteger(array $requestFields, string $field): ?int
    {
        $input = $requestFields[$field] ?? null;
        if ($input === null || $input === '') {
            return null;
        }
        if (!is_int($input) && !(is_string($input) && ctype_digit($input))) {
            Http::json(['error' => 'validation_error', 'fields' => [$field => 'أدخل رقمًا صحيحًا غير سالب.']], 422);
        }
        return max(0, (int) $input);
    }

    private static function optionalText(array $requestFields, string $field, int $max): ?string
    {
        $rawFieldText = $requestFields[$field] ?? null;
        if ($rawFieldText === null || $rawFieldText === '') {
            return null;
        }
        if (!is_string($rawFieldText) || mb_strlen($rawFieldText) > $max) {
            Http::json(['error' => 'validation_error', 'fields' => [$field => 'قيمة الحقل غير صالحة.']], 422);
        }
        $normalizedInput = trim($rawFieldText);
        return $normalizedInput === '' ? null : $normalizedInput;
    }

    private static function persistCustomer(PDO $db, array $customerFields, ?int $customerId): int
    {
        if ($customerId === null) {
            return self::insertCustomer($db, $customerFields);
        }
        self::updateCustomer($db, $customerFields, $customerId);
        return $customerId;
    }

    private static function insertCustomer(PDO $db, array $customerFields): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $columns = array_merge(self::FIELDS, ['created_at', 'updated_at']);
        $customerValues = array_merge(array_values($customerFields), [$now, $now]);
        $sql = 'INSERT INTO customers (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $db->prepare($sql)->execute($customerValues);
        return (int) $db->lastInsertId();
    }

    private static function updateCustomer(PDO $db, array $customerFields, int $customerId): void
    {
        if (!self::customerExists($db, $customerId)) {
            Http::json(['error' => 'not_found'], 404);
        }
        $setClauses = array_map(fn($field) => $field . ' = ?', self::FIELDS);
        $customerValues = array_merge(array_values($customerFields), [gmdate('Y-m-d H:i:s'), $customerId]);
        $db->prepare('UPDATE customers SET ' . implode(', ', $setClauses) . ', updated_at = ? WHERE id = ?')->execute($customerValues);
    }

    private static function customerExists(PDO $db, int $customerId): bool
    {
        $existsQuery = $db->prepare('SELECT 1 FROM customers WHERE id = ?');
        $existsQuery->execute([$customerId]);
        return (bool) $existsQuery->fetchColumn();
    }

    private static function isUniqueConstraintViolation(PDOException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }

    public static function addNote(PDO $db, int $customerId, array $requestFields, int $userId): never
    {
        if (!self::customerExists($db, $customerId)) {
            Http::json(['error' => 'not_found'], 404);
        }
        $noteBody = Http::requiredString($requestFields, 'body', 10000);
        $createdAt = gmdate('Y-m-d H:i:s');
        $db->prepare('INSERT INTO crm_notes (customer_id, user_id, body, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$customerId, $userId, $noteBody, $createdAt]);
        self::markCustomerActivity($db, $customerId, $createdAt);
        Http::json(['id' => (int) $db->lastInsertId(), 'body' => $noteBody, 'created_at' => $createdAt], 201);
    }

    private static function markCustomerActivity(PDO $db, int $customerId, string $activityAt): void
    {
        $db->prepare('UPDATE customers SET last_activity_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$activityAt, $activityAt, $customerId]);
    }
}
