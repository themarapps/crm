<?php
declare(strict_types=1);

final class Http
{
    public static function json(mixed $responsePayload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $requestFields = json_decode($raw, true);
        if (!is_array($requestFields)) {
            self::json(['error' => 'invalid_json', 'message' => 'صيغة الطلب غير صالحة.'], 400);
        }
        return $requestFields;
    }

    public static function requiredString(array $requestFields, string $key, int $max = 255): string
    {
        $input = $requestFields[$key] ?? null;
        if (!is_string($input)) {
            self::invalidRequiredField($key);
        }
        $normalizedInput = trim($input);
        if ($normalizedInput === '' || mb_strlen($normalizedInput) > $max) {
            self::invalidRequiredField($key);
        }
        return $normalizedInput;
    }

    private static function invalidRequiredField(string $key): never
    {
        self::json(['error' => 'validation_error', 'fields' => [$key => 'هذا الحقل مطلوب.']], 422);
    }

    public static function initializeCsrf(): void
    {
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    public static function csrf(): string
    {
        return $_SESSION['csrf'];
    }

    public static function requireCsrf(): void
    {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($sent) || !hash_equals(self::csrf(), $sent)) {
            self::json(['error' => 'csrf_failed', 'message' => 'انتهت صلاحية الطلب. حدّث الصفحة وحاول مرة أخرى.'], 419);
        }
    }
}
