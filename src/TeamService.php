<?php
declare(strict_types=1);

final class TeamService
{
    public static function list(PDO $db, array $user): never
    {
        $columns = $user['role'] === 'admin'
            ? "u.id, u.name, u.email, u.role, u.is_active, u.created_at, CASE WHEN u.role = 'admin' THEN 1 ELSE COALESCE(p.view_all_customers, CASE WHEN u.role = 'manager' THEN 1 ELSE 0 END) END AS can_view_all_customers, CASE WHEN u.role = 'admin' THEN 1 ELSE COALESCE(p.edit_customers, 1) END AS can_edit_customers"
            : 'u.id, u.name, u.role, u.is_active';
        $membersQuery = $db->query('SELECT ' . $columns . ' FROM users u LEFT JOIN user_customer_permissions p ON p.user_id = u.id ORDER BY u.name');
        $members = $membersQuery->fetchAll();
        Http::json(['data' => $members]);
    }

    public static function save(PDO $db, array $requestFields, int $actorId, ?int $memberId = null): never
    {
        $memberFields = self::validatedMemberFields($requestFields, $memberId);
        $change = [
            'member_fields' => $memberFields,
            'actor_id' => $actorId,
            'member_id' => $memberId,
            'previous_member' => $memberId === null ? null : self::findMember($db, $memberId),
        ];
        try {
            $savedMember = self::persistMemberChange($db, $change);
        } catch (PDOException $exception) {
            if ($db->inTransaction()) $db->rollBack();
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                Http::json(['error' => 'duplicate_email', 'fields' => ['email' => 'هذا البريد مستخدم بالفعل.']], 409);
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
        Http::json(['user' => $savedMember], $memberId === null ? 201 : 200);
    }

    private static function persistMemberChange(PDO $db, array $change): ?array
    {
        $db->beginTransaction();
        try {
            $fields = $change['member_fields'];
            $memberId = self::persistMember($db, $fields, $change['actor_id'], $change['member_id']);
            self::persistCustomerPermissions($db, $memberId, $fields, $change['actor_id']);
            $savedMember = self::findMember($db, $memberId);
            self::recordMemberAudit($db, [
                'actor_id' => $change['actor_id'], 'target_id' => $memberId,
                'previous' => $change['previous_member'], 'current' => $savedMember, 'fields' => $fields,
            ]);
            $db->commit();
            return $savedMember;
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    public static function audit(PDO $db, array $user): never
    {
        Auth::requireAdmin($user);
        $query = $db->query('SELECT a.id, a.action, a.summary, a.created_at, actor.name AS actor_name, target.name AS target_name FROM admin_audit_log a JOIN users actor ON actor.id = a.actor_id LEFT JOIN users target ON target.id = a.target_user_id ORDER BY a.id DESC LIMIT 100');
        Http::json(['data' => $query->fetchAll()]);
    }

    private static function recordMemberAudit(PDO $db, array $audit): void
    {
        $event = self::memberAuditEvent($audit);
        if (!$event) return;
        $db->prepare('INSERT INTO admin_audit_log (actor_id, target_user_id, action, summary, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$audit['actor_id'], $audit['target_id'], $event['action'], $event['summary'], gmdate('Y-m-d H:i:s')]);
    }

    private static function memberAuditEvent(array $audit): ?array
    {
        if (!$audit['previous']) return ['action' => 'team_member_created', 'summary' => 'تم إنشاء حساب عضو فريق.'];
        $changed = self::changedMemberFields($audit['previous'], $audit['current'], $audit['fields']);
        if (!$changed) return null;
        return ['action' => 'team_member_updated', 'summary' => 'تم تحديث: ' . implode('، ', $changed) . '.'];
    }

    private static function changedMemberFields(array $previous, array $current, array $fields): array
    {
        $labels = [
            'name' => 'الاسم', 'email' => 'البريد الإلكتروني', 'role' => 'الصلاحية',
            'is_active' => 'حالة الحساب', 'can_view_all_customers' => 'عرض كل العملاء',
            'can_edit_customers' => 'تعديل العملاء', 'password' => 'كلمة المرور',
        ];
        $changed = [];
        foreach ($labels as $field => $label) {
            if ($field === 'password' && $fields['password'] !== '') $changed[] = $label;
            elseif ($field !== 'password' && ($previous[$field] ?? null) != ($current[$field] ?? null)) $changed[] = $label;
        }
        return $changed;
    }

    private static function validatedMemberFields(array $requestFields, ?int $memberId): array
    {
        $email = self::validatedEmail($requestFields);
        $password = $memberId === null
            ? self::requiredNewPassword($requestFields)
            : self::optionalPasswordUpdate($requestFields);
        $role = self::validatedRole($requestFields);
        $active = self::validatedActiveFlag($requestFields);
        $viewAllCustomers = self::optionalPermission($requestFields, 'can_view_all_customers');
        $editCustomers = self::optionalPermission($requestFields, 'can_edit_customers');
        if (($viewAllCustomers === null) !== ($editCustomers === null)) {
            Http::json(['error' => 'validation_error', 'fields' => ['permissions' => 'أرسل صلاحية العرض والتعديل معًا.']], 422);
        }
        return [
            'name' => Http::requiredString($requestFields, 'name', 120),
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'is_active' => $active,
            'can_view_all_customers' => $viewAllCustomers,
            'can_edit_customers' => $editCustomers,
        ];
    }

    private static function optionalPermission(array $requestFields, string $field): ?int
    {
        if (!array_key_exists($field, $requestFields)) return null;
        $value = $requestFields[$field];
        if (is_string($value) && in_array($value, ['0', '1'], true)) $value = (int) $value;
        if (!is_bool($value) && !in_array($value, [0, 1], true)) {
            Http::json(['error' => 'validation_error', 'fields' => [$field => 'قيمة الصلاحية غير صالحة.']], 422);
        }
        return (int) $value;
    }

    private static function persistCustomerPermissions(PDO $db, int $memberId, array $memberFields, int $actorId): void
    {
        if ($memberFields['role'] === 'admin') {
            $db->prepare('DELETE FROM user_customer_permissions WHERE user_id = ?')->execute([$memberId]);
            return;
        }
        if ($memberFields['can_view_all_customers'] === null && $memberFields['can_edit_customers'] === null) return;
        $db->prepare('DELETE FROM user_customer_permissions WHERE user_id = ?')->execute([$memberId]);
        $db->prepare('INSERT INTO user_customer_permissions (user_id, view_all_customers, edit_customers, updated_by, updated_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$memberId, $memberFields['can_view_all_customers'], $memberFields['can_edit_customers'], $actorId, gmdate('Y-m-d H:i:s')]);
    }

    private static function validatedEmail(array $requestFields): string
    {
        $email = $requestFields['email'] ?? null;
        if (!is_string($email)) {
            Http::json(['error' => 'validation_error', 'fields' => ['email' => 'أدخل بريدًا إلكترونيًا صالحًا.']], 422);
        }
        $normalizedEmail = strtolower(trim($email));
        if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            Http::json(['error' => 'validation_error', 'fields' => ['email' => 'أدخل بريدًا إلكترونيًا صالحًا.']], 422);
        }
        return $normalizedEmail;
    }

    private static function requiredNewPassword(array $requestFields): string
    {
        $password = $requestFields['password'] ?? '';
        if (!is_string($password) || strlen($password) < 8) {
            Http::json(['error' => 'validation_error', 'fields' => ['password' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.']], 422);
        }
        return $password;
    }

    private static function optionalPasswordUpdate(array $requestFields): string
    {
        $password = $requestFields['password'] ?? '';
        if (!is_string($password) || ($password !== '' && strlen($password) < 8)) {
            Http::json(['error' => 'validation_error', 'fields' => ['password' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.']], 422);
        }
        return $password;
    }

    private static function validatedRole(array $requestFields): string
    {
        $role = $requestFields['role'] ?? 'member';
        if (!is_string($role) || !in_array($role, ['admin', 'manager', 'member'], true)) {
            Http::json(['error' => 'validation_error', 'fields' => ['role' => 'الدور غير صالح.']], 422);
        }
        return $role;
    }

    private static function validatedActiveFlag(array $requestFields): int
    {
        $active = $requestFields['is_active'] ?? true;
        if (!is_bool($active) && !in_array($active, [0, 1], true)) {
            Http::json(['error' => 'validation_error', 'fields' => ['is_active' => 'حالة الحساب غير صالحة.']], 422);
        }
        return (int) $active;
    }

    private static function persistMember(PDO $db, array $memberFields, int $actorId, ?int $memberId): int
    {
        return $memberId === null
            ? self::insertMember($db, $memberFields)
            : self::updateMember($db, $memberFields, $actorId, $memberId);
    }

    private static function insertMember(PDO $db, array $memberFields): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $db->prepare('INSERT INTO users (name, email, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $memberFields['name'], $memberFields['email'], password_hash($memberFields['password'], PASSWORD_DEFAULT),
                $memberFields['role'], $memberFields['is_active'], $now, $now,
            ]);
        return (int) $db->lastInsertId();
    }

    private static function updateMember(PDO $db, array $memberFields, int $actorId, int $memberId): int
    {
        $existingMember = self::findMemberSecurityFields($db, $memberId);
        if (!$existingMember) {
            Http::json(['error' => 'not_found'], 404);
        }
        self::preventSelfDeactivation($memberFields, $actorId, $memberId);
        self::preventLastAdminRemoval($db, $existingMember, $memberFields);
        self::runMemberUpdate($db, $memberFields, $memberId);
        return $memberId;
    }

    private static function findMemberSecurityFields(PDO $db, int $memberId): ?array
    {
        $memberQuery = $db->prepare('SELECT id, role, is_active FROM users WHERE id = ?');
        $memberQuery->execute([$memberId]);
        return $memberQuery->fetch() ?: null;
    }

    private static function preventSelfDeactivation(array $memberFields, int $actorId, int $memberId): void
    {
        if ($actorId === $memberId && $memberFields['is_active'] !== 1) {
            Http::json(['error' => 'validation_error', 'fields' => ['is_active' => 'لا يمكنك إيقاف حسابك الحالي.']], 422);
        }
    }

    private static function preventLastAdminRemoval(PDO $db, array $existingMember, array $memberFields): void
    {
        if (self::removesLastAdmin($db, $existingMember, $memberFields)) {
            Http::json(['error' => 'validation_error', 'fields' => ['role' => 'يجب إبقاء مسؤول نشط واحد على الأقل.']], 422);
        }
    }

    private static function removesLastAdmin(PDO $db, array $existingMember, array $memberFields): bool
    {
        $isActiveAdmin = $existingMember['role'] === 'admin' && (int) $existingMember['is_active'] === 1;
        $willRemainActiveAdmin = $memberFields['role'] === 'admin' && $memberFields['is_active'] === 1;
        if (!$isActiveAdmin || $willRemainActiveAdmin) {
            return false;
        }
        return (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn() <= 1;
    }

    private static function runMemberUpdate(PDO $db, array $memberFields, int $memberId): void
    {
        $sets = 'name = ?, email = ?, role = ?, is_active = ?, updated_at = ?';
        $params = [$memberFields['name'], $memberFields['email'], $memberFields['role'], $memberFields['is_active'], gmdate('Y-m-d H:i:s')];
        if ($memberFields['password'] !== '') {
            $sets .= ', password_hash = ?';
            $params[] = password_hash($memberFields['password'], PASSWORD_DEFAULT);
        }
        $params[] = $memberId;
        $db->prepare('UPDATE users SET ' . $sets . ' WHERE id = ?')->execute($params);
    }

    private static function findMember(PDO $db, int $memberId): ?array
    {
        $memberQuery = $db->prepare("SELECT u.id, u.name, u.email, u.role, u.is_active, u.created_at,
            CASE WHEN u.role = 'admin' THEN 1 ELSE COALESCE(p.view_all_customers, CASE WHEN u.role = 'manager' THEN 1 ELSE 0 END) END AS can_view_all_customers,
            CASE WHEN u.role = 'admin' THEN 1 ELSE COALESCE(p.edit_customers, 1) END AS can_edit_customers
            FROM users u LEFT JOIN user_customer_permissions p ON p.user_id = u.id WHERE u.id = ?");
        $memberQuery->execute([$memberId]);
        return $memberQuery->fetch() ?: null;
    }
}
