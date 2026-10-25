<?php
declare(strict_types=1);

final class Auth
{
    private const LOGIN_WINDOW_SECONDS = 900;
    private const MAX_FAILED_LOGINS = 8;

    public static function user(PDO $db): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id) && !ctype_digit((string) $id)) {
            return null;
        }
        $userQuery = $db->prepare("SELECT u.id, u.name, u.email, u.role, u.is_active,
            CASE WHEN u.role = 'admin' THEN 1 ELSE COALESCE(p.view_all_customers, CASE WHEN u.role = 'manager' THEN 1 ELSE 0 END) END AS can_view_all_customers,
            CASE WHEN u.role = 'admin' THEN 1 ELSE COALESCE(p.edit_customers, 1) END AS can_edit_customers
            FROM users u LEFT JOIN user_customer_permissions p ON p.user_id = u.id WHERE u.id = ?");
        $userQuery->execute([(int) $id]);
        $user = $userQuery->fetch();
        if (!$user || (int) $user['is_active'] !== 1) {
            return null;
        }
        $user['id'] = (int) $user['id'];
        return $user;
    }

    public static function requireUser(PDO $db): array
    {
        $user = self::user($db);
        if (!$user) {
            Http::json(['error' => 'unauthenticated', 'message' => 'سجّل الدخول للمتابعة.'], 401);
        }
        return $user;
    }

    public static function requireAdmin(array $user): void
    {
        if ($user['role'] !== 'admin') {
            Http::json(['error' => 'forbidden', 'message' => 'لا تملك صلاحية تنفيذ هذا الإجراء.'], 403);
        }
    }

    public static function requireManager(array $user): void
    {
        if (!in_array($user['role'], ['admin', 'manager'], true)) {
            Http::json(['error' => 'forbidden', 'message' => 'لا تملك صلاحية تنفيذ هذا الإجراء.'], 403);
        }
    }

    public static function login(PDO $db, array $body): never
    {
        [$email, $password] = self::credentials($body);
        $identityHash = self::identityHash($email);
        self::pruneExpiredAttempts($db);
        self::enforceLoginLimit($db, $identityHash);
        $user = self::findActiveUser($db, $email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::recordFailedLogin($db, $identityHash);
        }
        $db->prepare('DELETE FROM login_attempts WHERE identity_hash = ?')->execute([$identityHash]);
        self::establishSession($user);
    }

    private static function credentials(array $requestFields): array
    {
        $email = $requestFields['email'] ?? null;
        $password = $requestFields['password'] ?? null;
        if (!is_string($email) || !is_string($password) || $password === '') {
            Http::json(['error' => 'invalid_credentials', 'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.'], 422);
        }
        $normalizedEmail = strtolower(trim($email));
        if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            Http::json(['error' => 'invalid_credentials', 'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.'], 422);
        }
        return [$normalizedEmail, $password];
    }

    private static function identityHash(string $email): string
    {
        $clientAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return hash('sha256', $clientAddress . '|' . $email);
    }

    private static function pruneExpiredAttempts(PDO $db): void
    {
        $expiredBefore = gmdate('Y-m-d H:i:s', time() - 86400);
        $db->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([$expiredBefore]);
    }

    private static function enforceLoginLimit(PDO $db, string $identityHash): void
    {
        $windowStart = gmdate('Y-m-d H:i:s', time() - self::LOGIN_WINDOW_SECONDS);
        $attempts = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE identity_hash = ? AND attempted_at >= ?');
        $attempts->execute([$identityHash, $windowStart]);
        if ((int) $attempts->fetchColumn() >= self::MAX_FAILED_LOGINS) {
            Http::json(['error' => 'rate_limited', 'message' => 'محاولات كثيرة. انتظر قليلًا ثم أعد المحاولة.'], 429);
        }
    }

    private static function findActiveUser(PDO $db, string $email): ?array
    {
        $query = $db->prepare("SELECT u.id, u.name, u.email, u.password_hash, u.role,
            CASE WHEN u.role = 'admin' THEN 1 ELSE COALESCE(p.view_all_customers, CASE WHEN u.role = 'manager' THEN 1 ELSE 0 END) END AS can_view_all_customers,
            CASE WHEN u.role = 'admin' THEN 1 ELSE COALESCE(p.edit_customers, 1) END AS can_edit_customers
            FROM users u LEFT JOIN user_customer_permissions p ON p.user_id = u.id WHERE u.email = ? AND u.is_active = 1");
        $query->execute([$email]);
        $user = $query->fetch();
        return $user ?: null;
    }

    private static function recordFailedLogin(PDO $db, string $identityHash): never
    {
        $db->prepare('INSERT INTO login_attempts (identity_hash, attempted_at) VALUES (?, ?)')
            ->execute([$identityHash, gmdate('Y-m-d H:i:s')]);
        Http::json(['error' => 'invalid_credentials', 'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.'], 401);
    }

    private static function establishSession(array $user): never
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        unset($user['password_hash']);
        $user['id'] = (int) $user['id'];
        Http::json(['user' => $user, 'csrf_token' => $_SESSION['csrf']]);
    }
}
