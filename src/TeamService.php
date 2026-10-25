<?php
declare(strict_types=1);

final class TeamService
{
    public static function list(PDO $db, array $user): never
    {
        $columns = $user['role'] === 'admin' ? 'id, name, email, role, is_active, created_at' : 'id, name, role, is_active';
        $members = $db->query('SELECT ' . $columns . ' FROM users ORDER BY name')->fetchAll();
        Http::json(['data' => $members]);
    }

    public static function save(PDO $db, array $requestFields, int $actorId, ?int $memberId = null): never
    {
        $memberFields = self::validatedMemberFields($requestFields, $memberId);
        try {
            $savedMemberId = self::persistMember($db, $memberFields, $actorId, $memberId);
        } catch (PDOException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                Http::json(['error' => 'duplicate_email', 'fields' => ['email' => 'هذا البريد مستخدم بالفعل.']], 409);
            }
            throw $exception;
        }
        $member = self::findMember($db, $savedMemberId);
        Http::json(['user' => $member], $memberId === null ? 201 : 200);
    }

    private static function validatedMemberFields(array $requestFields, ?int $memberId): array
    {
        $email = self::validatedEmail($requestFields);
        $password = $memberId === null
            ? self::requiredNewPassword($requestFields)
            : self::optionalPasswordUpdate($requestFields);
        $role = self::validatedRole($requestFields);
        $active = self::validatedActiveFlag($requestFields);
        return [
            'name' => Http::requiredString($requestFields, 'name', 120),
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'is_active' => $active,
        ];
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
        $memberQuery = $db->prepare('SELECT id, name, email, role, is_active, created_at FROM users WHERE id = ?');
        $memberQuery->execute([$memberId]);
        return $memberQuery->fetch() ?: null;
    }
}
