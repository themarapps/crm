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

    public static function list(PDO $db, array $requestQuery, array $user): never
    {
        [$conditions, $queryParams] = self::listConditions($requestQuery, $user);
        $whereClause = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $page = max(1, (int) ($requestQuery['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($requestQuery['per_page'] ?? 25)));
        $total = self::countCustomers($db, $whereClause, $queryParams);
        $customers = self::fetchCustomers($db, [
            'where' => $whereClause,
            'params' => $queryParams,
            'page' => $page,
            'page_size' => $pageSize,
            'sort' => self::sortOrder($requestQuery),
        ]);
        Http::json(['data' => $customers, 'page' => $page, 'per_page' => $pageSize, 'total' => $total]);
    }

    private static function sortOrder(array $requestQuery): string
    {
        $columns = [
            'name' => 'name', 'phone' => 'phone', 'plan' => 'plan', 'subscription_status' => 'subscription_status',
            'crm_stage' => 'crm_stage', 'assigned_to' => 'assigned_to', 'registered_at' => 'registered_at',
            'updated_at' => 'updated_at',
        ];
        $column = $columns[self::queryString($requestQuery, 'sort')] ?? 'updated_at';
        $direction = strtolower(self::queryString($requestQuery, 'direction')) === 'asc' ? 'ASC' : 'DESC';
        return $column . ' ' . $direction . ', id DESC';
    }

    private static function listConditions(array $requestQuery, array $user): array
    {
        $conditions = [];
        $queryParams = [];
        [$searchConditions, $searchParams] = self::searchCondition($requestQuery);
        [$exactConditions, $exactParams] = self::exactConditions($requestQuery);
        $conditions = array_merge($searchConditions, $exactConditions);
        $queryParams = array_merge($searchParams, $exactParams);
        if (!CustomerAccess::canViewAll($user)) {
            $conditions[] = 'assigned_to = ?';
            $queryParams[] = (int) $user['id'];
        }
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
        foreach (['subscription_status' => 'subscription_status', 'payment_status' => 'payment_status', 'plan' => 'plan', 'stage' => 'crm_stage', 'assigned_to' => 'assigned_to'] as $filter => $column) {
            $filterValue = self::queryString($requestQuery, $filter);
            if ($filterValue === '') {
                continue;
            }
            $conditions[] = $column . ' = ?';
            $queryParams[] = $filterValue;
        }
        foreach (['lead_source' => 'lead_source', 'campaign' => 'campaign'] as $filter => $column) {
            $filterValue = self::queryString($requestQuery, $filter);
            if ($filterValue === '') continue;
            $conditions[] = '(SELECT ca.' . $column . ' FROM customer_attribution ca WHERE ca.customer_id = customers.id) = ?';
            $queryParams[] = $filterValue;
        }
        $tagId = self::queryString($requestQuery, 'tag');
        if ($tagId !== '') {
            if (ctype_digit($tagId) && (int) $tagId > 0) {
                $conditions[] = 'EXISTS (SELECT 1 FROM customer_tags ct WHERE ct.customer_id = customers.id AND ct.tag_id = ?)';
                $queryParams[] = (int) $tagId;
            } else {
                $conditions[] = '1 = 0';
            }
        }
        return [$conditions, $queryParams];
    }

    public static function filterOptions(PDO $db, array $user): never
    {
        Http::json([
            'plans' => self::distinctValues($db, 'plan', $user),
            'subscription_statuses' => self::distinctValues($db, 'subscription_status', $user),
            'payment_statuses' => self::distinctValues($db, 'payment_status', $user),
            'stages' => self::distinctValues($db, 'crm_stage', $user),
            'lead_sources' => self::attributionOptions($db, 'lead_source', $user),
            'campaigns' => self::attributionOptions($db, 'campaign', $user),
            'tags' => self::tagOptions($db, $user),
        ]);
    }

    private static function attributionOptions(PDO $db, string $column, array $user): array
    {
        if (!in_array($column, ['lead_source', 'campaign'], true)) return [];
        $scope = CustomerAccess::canViewAll($user) ? '' : ' AND c.assigned_to = ?';
        $query = $db->prepare('SELECT DISTINCT ca.' . $column . ' AS value FROM customer_attribution ca JOIN customers c ON c.id = ca.customer_id WHERE ca.' . $column . " IS NOT NULL AND ca." . $column . " != ''" . $scope . ' ORDER BY ca.' . $column . ' LIMIT 100');
        $query->execute(CustomerAccess::canViewAll($user) ? [] : [(int) $user['id']]);
        return array_column($query->fetchAll(), 'value');
    }

    private static function tagOptions(PDO $db, array $user): array
    {
        $scope = CustomerAccess::canViewAll($user) ? '' : ' AND c.assigned_to = ?';
        $query = $db->prepare('SELECT DISTINCT t.id, t.name FROM crm_tags t JOIN customer_tags ct ON ct.tag_id = t.id JOIN customers c ON c.id = ct.customer_id WHERE 1 = 1' . $scope . ' ORDER BY t.name LIMIT 100');
        $query->execute(CustomerAccess::canViewAll($user) ? [] : [(int) $user['id']]);
        return $query->fetchAll();
    }

    private static function distinctValues(PDO $db, string $column, array $user): array
    {
        $scope = CustomerAccess::canViewAll($user) ? '' : ' AND assigned_to = ' . (int) $user['id'];
        $query = $db->query('SELECT DISTINCT ' . $column . ' FROM customers WHERE ' . $column . ' IS NOT NULL AND ' . $column . " != ''" . $scope . ' ORDER BY ' . $column . ' LIMIT 100');
        return array_column($query->fetchAll(), $column);
    }

    public static function export(PDO $db, array $requestQuery, array $user): never
    {
        if (!in_array($user['role'], ['admin', 'manager'], true)) {
            Http::json(['error' => 'forbidden', 'message' => 'لا تملك صلاحية تصدير العملاء.'], 403);
        }
        [$conditions, $params] = self::listConditions($requestQuery, $user);
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $query = $db->prepare('SELECT customers.name, customers.email, customers.phone, customers.plan, customers.subscription_status, customers.payment_status, customers.crm_stage, customers.assigned_to, customers.registered_at, (SELECT ca.lead_source FROM customer_attribution ca WHERE ca.customer_id = customers.id), (SELECT ca.campaign FROM customer_attribution ca WHERE ca.customer_id = customers.id) FROM customers' . $where . ' ORDER BY customers.updated_at DESC, customers.id DESC LIMIT 10000');
        $query->execute($params);
        self::writeCustomerExport($query);
        exit;
    }

    private static function writeCustomerExport(PDOStatement $query): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="crm-customers.csv"');
        header('Cache-Control: no-store');
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['name', 'email', 'phone', 'plan', 'subscription_status', 'payment_status', 'crm_stage', 'assigned_to', 'registered_at', 'lead_source', 'campaign']);
        while ($customer = $query->fetch(PDO::FETCH_NUM)) {
            foreach ($customer as &$value) {
                if (is_string($value) && preg_match('/^[\x00-\x20]*[=+@\-]/', $value)) $value = "'" . $value;
            }
            unset($value);
            fputcsv($output, $customer);
        }
        fclose($output);
    }

    public static function report(PDO $db, array $user): never
    {
        if (!in_array($user['role'], ['admin', 'manager'], true)) {
            Http::json(['error' => 'forbidden', 'message' => 'لا تملك صلاحية عرض التقارير.'], 403);
        }
        Http::json([
            'total' => self::countCustomersForReport($db, $user),
            'active_subscriptions' => self::countActiveForReport($db, $user),
            'stages' => self::groupCounts($db, 'crm_stage', $user),
            'subscriptions' => self::groupCounts($db, 'subscription_status', $user),
            'plans' => self::groupCounts($db, 'plan', $user),
            'team_performance' => self::teamPerformance($db, $user),
        ]);
    }

    private static function teamPerformance(PDO $db, array $user): array
    {
        $scope = CustomerAccess::canViewAll($user) ? '' : ' WHERE c.assigned_to = ?';
        $params = [gmdate('Y-m-d H:i:s')];
        if (!CustomerAccess::canViewAll($user)) $params[] = (int) $user['id'];
        $query = $db->prepare("SELECT c.assigned_to, COALESCE(u.name, 'غير معين') AS owner_name, COUNT(*) AS customers, SUM(CASE WHEN c.crm_stage IN ('contacted', 'interested') THEN 1 ELSE 0 END) AS advanced_customers, SUM(CASE WHEN EXISTS (SELECT 1 FROM follow_up_tasks t WHERE t.customer_id = c.id AND t.status = 'open' AND t.due_at < ?) THEN 1 ELSE 0 END) AS overdue_tasks FROM customers c LEFT JOIN users u ON u.id = c.assigned_to" . $scope . ' GROUP BY c.assigned_to, u.name ORDER BY customers DESC, owner_name');
        $query->execute($params);
        $rows = $query->fetchAll();
        foreach ($rows as &$row) {
            $total = (int) $row['customers'];
            $row['conversion_rate'] = $total > 0 ? round(((int) $row['advanced_customers'] / $total) * 100, 1) : 0;
        }
        unset($row);
        return $rows;
    }

    private static function countCustomersForReport(PDO $db, array $user): int
    {
        if (CustomerAccess::canViewAll($user)) return (int) $db->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        $query = $db->prepare('SELECT COUNT(*) FROM customers WHERE assigned_to = ?');
        $query->execute([(int) $user['id']]);
        return (int) $query->fetchColumn();
    }

    private static function countActiveForReport(PDO $db, array $user): int
    {
        $scope = CustomerAccess::canViewAll($user) ? '' : ' AND assigned_to = ?';
        $query = $db->prepare("SELECT COUNT(*) FROM customers WHERE LOWER(COALESCE(subscription_status, '')) = 'active'" . $scope);
        $query->execute(CustomerAccess::canViewAll($user) ? [] : [(int) $user['id']]);
        return (int) $query->fetchColumn();
    }

    private static function groupCounts(PDO $db, string $column, array $user): array
    {
        $scope = CustomerAccess::canViewAll($user) ? '' : ' WHERE assigned_to = ?';
        $query = $db->prepare('SELECT ' . $column . ' AS label, COUNT(*) AS total FROM customers' . $scope . ' GROUP BY ' . $column . ' ORDER BY total DESC, label LIMIT 50');
        $query->execute(CustomerAccess::canViewAll($user) ? [] : [(int) $user['id']]);
        return $query->fetchAll();
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
        $sql = 'SELECT customers.*, (SELECT ca.lead_source FROM customer_attribution ca WHERE ca.customer_id = customers.id) AS lead_source, (SELECT ca.campaign FROM customer_attribution ca WHERE ca.customer_id = customers.id) AS campaign FROM customers' . $listQuery['where'] . ' ORDER BY ' . $listQuery['sort'] . ' LIMIT ' . $listQuery['page_size'] . ' OFFSET ' . $offset;
        $customerQuery = $db->prepare($sql);
        $customerQuery->execute($listQuery['params']);
        return $customerQuery->fetchAll();
    }

    public static function bulkUpdate(PDO $db, array $requestFields, array $user): never
    {
        CustomerAccess::requireEdit($user);
        $batch = self::validatedBulkBatch($db, $requestFields, $user);
        $updated = self::persistBulkUpdates($db, $batch, $user);
        Http::json(['updated' => $updated]);
    }

    private static function validatedBulkBatch(PDO $db, array $requestFields, array $user): array
    {
        return array_merge(
            ['ids' => self::validatedBulkIds($requestFields['ids'] ?? null)],
            self::validatedBulkFields($db, $requestFields, $user)
        );
    }

    private static function validatedBulkIds(mixed $requestedIds): array
    {
        if (!is_array($requestedIds) || count($requestedIds) < 1 || count($requestedIds) > 100) {
            Http::json(['error' => 'validation_error', 'fields' => ['ids' => 'حدد من 1 إلى 100 عميل.']], 422);
        }
        $ids = [];
        foreach ($requestedIds as $requestedId) {
            if ((!is_int($requestedId) && !(is_string($requestedId) && ctype_digit($requestedId))) || (int) $requestedId < 1) {
                Http::json(['error' => 'validation_error', 'fields' => ['ids' => 'معرّفات العملاء غير صالحة.']], 422);
            }
            $ids[(int) $requestedId] = (int) $requestedId;
        }
        return array_values($ids);
    }

    private static function validatedBulkFields(PDO $db, array $requestFields, array $user): array
    {
        $hasStage = array_key_exists('crm_stage', $requestFields);
        $hasAssignee = array_key_exists('assigned_to', $requestFields);
        if (!$hasStage && !$hasAssignee) Http::json(['error' => 'validation_error', 'message' => 'اختر مرحلة أو مسؤولًا للتحديث.'], 422);
        if ($hasAssignee && !CustomerAccess::canViewAll($user)) Http::json(['error' => 'forbidden'], 403);
        return [
            'stage' => $hasStage ? self::validatedStage($requestFields) : null,
            'assignee' => $hasAssignee ? self::validatedAssignee($db, $requestFields) : null,
            'has_stage' => $hasStage,
            'has_assignee' => $hasAssignee,
        ];
    }

    private static function accessibleCustomers(PDO $db, array $ids, array $user): array
    {
        $sql = 'SELECT id, assigned_to, crm_stage FROM customers WHERE id = ?';
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $sql .= ' FOR UPDATE';
        $find = $db->prepare($sql);
        $customers = [];
        foreach ($ids as $id) {
            $find->execute([$id]);
            $customer = $find->fetch();
            if (!$customer || !self::canAccessCustomer($customer, $user)) {
                $db->rollBack();
                Http::json(['error' => 'not_found'], 404);
            }
            $customers[] = $customer;
        }
        return $customers;
    }

    private static function persistBulkUpdates(PDO $db, array $batch, array $user): int
    {
        $db->beginTransaction();
        try {
            $customers = self::accessibleCustomers($db, $batch['ids'], $user);
            foreach ($customers as $customer) self::updateBulkCustomer($db, $customer, $batch, (int) $user['id']);
            $db->commit();
            return count($customers);
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    private static function updateBulkCustomer(PDO $db, array $customer, array $batch, int $actorId): void
    {
        $set = [];
        $values = [];
        $changedLabels = [];
        if ($batch['has_stage'] && $batch['stage'] !== $customer['crm_stage']) {
            $set[] = 'crm_stage = ?';
            $values[] = $batch['stage'];
            $changedLabels[] = 'مرحلة المتابعة';
        }
        if ($batch['has_assignee'] && (string) ($customer['assigned_to'] ?? '') !== (string) ($batch['assignee'] ?? '')) {
            $set[] = 'assigned_to = ?';
            $values[] = $batch['assignee'];
            $changedLabels[] = 'المسؤول';
        }
        if (!$set) return;
        $set[] = 'updated_at = ?';
        $values[] = gmdate('Y-m-d H:i:s');
        $values[] = (int) $customer['id'];
        $db->prepare('UPDATE customers SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($values);
        ActivityLog::record($db, (int) $customer['id'], $actorId, 'bulk_updated', 'تم تحديث الحقول: ' . implode('، ', $changedLabels) . '.');
    }

    public static function show(PDO $db, int $customerId, array $user): never
    {
        $customer = self::findCustomer($db, $customerId);
        if (!$customer || !self::canAccessCustomer($customer, $user)) {
            Http::json(['error' => 'not_found'], 404);
        }
        Http::json([
            'customer' => $customer,
            'notes' => self::customerNotes($db, $customerId),
            'tasks' => self::customerTasks($db, $customerId),
            'tags' => self::customerTags($db, $customerId),
            'activity' => ActivityLog::forCustomer($db, $customerId),
        ]);
    }

    public static function notes(PDO $db, int $customerId, array $user): never
    {
        $customer = self::findCustomer($db, $customerId);
        if (!$customer || !self::canAccessCustomer($customer, $user)) {
            Http::json(['error' => 'not_found'], 404);
        }
        Http::json(['data' => self::customerNotes($db, $customerId)]);
    }

    private static function findCustomer(PDO $db, int $customerId): ?array
    {
        $customerQuery = $db->prepare('SELECT customers.*, ca.lead_source, ca.campaign FROM customers LEFT JOIN customer_attribution ca ON ca.customer_id = customers.id WHERE customers.id = ?');
        $customerQuery->execute([$customerId]);
        return $customerQuery->fetch() ?: null;
    }

    private static function customerTags(PDO $db, int $customerId): array
    {
        $query = $db->prepare('SELECT t.id, t.name FROM crm_tags t JOIN customer_tags ct ON ct.tag_id = t.id WHERE ct.customer_id = ? ORDER BY t.name');
        $query->execute([$customerId]);
        return $query->fetchAll();
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

    public static function save(PDO $db, array $requestFields, array $user, ?int $customerId = null): never
    {
        CustomerAccess::requireEdit($user);
        $existing = null;
        if ($customerId !== null) {
            $existing = self::findCustomer($db, $customerId);
            if (!$existing || !self::canAccessCustomer($existing, $user)) Http::json(['error' => 'not_found'], 404);
        }
        if (!CustomerAccess::canViewAll($user)) {
            $requestFields['assigned_to'] = $customerId === null ? (int) $user['id'] : ($existing['assigned_to'] ?? null);
        }
        $customerFields = self::validatedCustomerFields($db, $requestFields);
        $hasTags = array_key_exists('tags', $requestFields);
        $hasAttribution = array_key_exists('lead_source', $requestFields) || array_key_exists('campaign', $requestFields);
        $tagNames = $hasTags ? self::validatedTags($requestFields['tags']) : [];
        $leadSource = self::optionalText($requestFields, 'lead_source', 120);
        $campaign = self::optionalText($requestFields, 'campaign', 120);
        try {
            $db->beginTransaction();
            $savedId = self::persistCustomer($db, $customerFields, $customerId);
            if ($hasAttribution) self::persistAttribution($db, $savedId, $leadSource, $campaign);
            if ($hasTags) self::persistCustomerTags($db, $savedId, $tagNames, (int) $user['id']);
            $db->commit();
        } catch (PDOException $exception) {
            if ($db->inTransaction()) $db->rollBack();
            if (self::isUniqueConstraintViolation($exception)) {
                Http::json(['error' => 'duplicate_email', 'fields' => ['email' => 'هذا البريد مسجل لعميل آخر.']], 409);
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        $savedCustomer = self::findCustomer($db, $savedId);
        $summary = $customerId === null ? 'تمت إضافة العميل.' : self::customerUpdateSummary($existing, [
            'customer_fields' => $customerFields,
            'has_attribution' => $hasAttribution,
            'lead_source' => $leadSource,
            'campaign' => $campaign,
        ]);
        ActivityLog::record($db, $savedId, (int) $user['id'], $customerId === null ? 'created' : 'updated', $summary);
        Http::json(['customer' => $savedCustomer], $customerId === null ? 201 : 200);
    }

    private static function customerUpdateSummary(array $existing, array $changes): string
    {
        $labels = [
            'name' => 'الاسم', 'email' => 'البريد الإلكتروني', 'phone' => 'الهاتف',
            'plan' => 'الباقة', 'subscription_status' => 'حالة الاشتراك', 'payment_status' => 'حالة الدفع',
            'crm_stage' => 'مرحلة المتابعة', 'assigned_to' => 'المسؤول', 'lead_source' => 'مصدر العميل', 'campaign' => 'الحملة',
        ];
        $changed = [];
        foreach ($changes['customer_fields'] as $field => $value) {
            if (($existing[$field] ?? null) !== $value && (string) ($existing[$field] ?? '') !== (string) ($value ?? '')) $changed[$field] = true;
        }
        if ($changes['has_attribution']) {
            if (($existing['lead_source'] ?? null) !== $changes['lead_source']) $changed['lead_source'] = true;
            if (($existing['campaign'] ?? null) !== $changes['campaign']) $changed['campaign'] = true;
        }
        $changedLabels = array_map(fn($field) => $labels[$field] ?? $field, array_keys($changed));
        return $changedLabels ? 'تم تحديث الحقول: ' . implode('، ', $changedLabels) . '.' : 'تم تحديث بيانات العميل.';
    }

    private static function validatedTags(mixed $input): array
    {
        if (!is_array($input) || count($input) > 20) {
            Http::json(['error' => 'validation_error', 'fields' => ['tags' => 'أدخل حتى 20 وسمًا صالحًا.']], 422);
        }
        $tags = [];
        foreach ($input as $value) {
            if (!is_string($value)) Http::json(['error' => 'validation_error', 'fields' => ['tags' => 'الوسوم غير صالحة.']], 422);
            $name = trim($value);
            if ($name === '') continue;
            if (mb_strlen($name) > 40) Http::json(['error' => 'validation_error', 'fields' => ['tags' => 'يجب ألا يتجاوز كل وسم 40 حرفًا.']], 422);
            $normalized = mb_strtolower($name, 'UTF-8');
            $tags[$normalized] = $name;
        }
        return array_values($tags);
    }

    private static function persistAttribution(PDO $db, int $customerId, ?string $leadSource, ?string $campaign): void
    {
        $db->prepare('DELETE FROM customer_attribution WHERE customer_id = ?')->execute([$customerId]);
        if ($leadSource === null && $campaign === null) return;
        $db->prepare('INSERT INTO customer_attribution (customer_id, lead_source, campaign) VALUES (?, ?, ?)')->execute([$customerId, $leadSource, $campaign]);
    }

    private static function persistCustomerTags(PDO $db, int $customerId, array $tagNames, int $actorId): void
    {
        $previousTags = self::customerTags($db, $customerId);
        $db->prepare('DELETE FROM customer_tags WHERE customer_id = ?')->execute([$customerId]);
        foreach ($tagNames as $name) {
            $normalized = mb_strtolower($name, 'UTF-8');
            $query = $db->prepare('SELECT id FROM crm_tags WHERE normalized_name = ?');
            $query->execute([$normalized]);
            $tagId = $query->fetchColumn();
            if ($tagId === false) {
                try {
                    $db->prepare('INSERT INTO crm_tags (name, normalized_name) VALUES (?, ?)')->execute([$name, $normalized]);
                    $tagId = $db->lastInsertId();
                } catch (PDOException $exception) {
                    if (!self::isUniqueConstraintViolation($exception)) throw $exception;
                    $query->execute([$normalized]);
                    $tagId = $query->fetchColumn();
                    if ($tagId === false) throw $exception;
                }
            }
            $db->prepare('INSERT INTO customer_tags (customer_id, tag_id) VALUES (?, ?)')->execute([$customerId, (int) $tagId]);
        }
        $previousNames = array_column($previousTags, 'name');
        sort($previousNames);
        $newNames = $tagNames;
        sort($newNames);
        if ($previousNames !== $newNames) ActivityLog::record($db, $customerId, $actorId, 'tags_updated', 'تم تحديث وسوم العميل.');
    }

    private static function canAccessCustomer(array $customer, array $user): bool
    {
        return CustomerAccess::canAccess($customer, $user);
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

    public static function addNote(PDO $db, int $customerId, array $requestFields, array $user): never
    {
        CustomerAccess::requireEdit($user);
        $customer = self::findCustomer($db, $customerId);
        if (!$customer || !self::canAccessCustomer($customer, $user)) {
            Http::json(['error' => 'not_found'], 404);
        }
        $noteBody = Http::requiredString($requestFields, 'body', 10000);
        $createdAt = gmdate('Y-m-d H:i:s');
        $db->prepare('INSERT INTO crm_notes (customer_id, user_id, body, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$customerId, (int) $user['id'], $noteBody, $createdAt]);
        $noteId = (int) $db->lastInsertId();
        ActivityLog::record($db, $customerId, (int) $user['id'], 'note', 'أُضيفت ملاحظة متابعة.');
        self::markCustomerActivity($db, $customerId, $createdAt);
        Http::json(['id' => $noteId, 'body' => $noteBody, 'created_at' => $createdAt], 201);
    }

    private static function markCustomerActivity(PDO $db, int $customerId, string $activityAt): void
    {
        $db->prepare('UPDATE customers SET last_activity_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$activityAt, $activityAt, $customerId]);
    }
}
