<?php
declare(strict_types=1);

final class IntegrationSettingsService
{
    public static function show(PDO $db): never
    {
        $settings = self::find($db);
        Http::json([
            'configured' => $settings !== null,
            'source_url' => $settings['source_url'] ?? '',
            'credentials_configured' => $settings !== null,
            'encryption_ready' => SecretCipher::isAvailable(),
            'updated_at' => $settings['updated_at'] ?? null,
        ]);
    }

    public static function save(PDO $db, array $requestFields): never
    {
        $sourceUrl = self::validatedSourceUrl($requestFields);
        $existing = self::find($db);
        $usernameInput = $requestFields['username'] ?? '';
        $passwordInput = $requestFields['password'] ?? '';
        if (!is_string($usernameInput) || mb_strlen($usernameInput) > 255 || !is_string($passwordInput) || mb_strlen($passwordInput) > 4096) {
            Http::json(['error' => 'validation_error', 'message' => 'بيانات تسجيل الدخول غير صالحة.'], 422);
        }
        if (!SecretCipher::isAvailable()) {
            Http::json(['error' => 'encryption_unavailable', 'message' => 'تشفير بيانات الربط غير مُعد على الخادم بعد.'], 503);
        }
        $replaceCredentials = trim($usernameInput) !== '' && $passwordInput !== '';
        $credentials = $replaceCredentials ? [] : self::previousCredentials($existing);
        $credentials['username'] = trim($usernameInput) !== '' ? trim($usernameInput) : ($credentials['username'] ?? '');
        $credentials['password'] = $passwordInput !== '' ? $passwordInput : ($credentials['password'] ?? '');
        if ($credentials['username'] === '' || $credentials['password'] === '') {
            Http::json(['error' => 'validation_error', 'fields' => ['username' => 'أدخل اسم المستخدم وكلمة المرور.']], 422);
        }
        $encrypted = SecretCipher::encrypt((string) json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::persist($db, $existing, $sourceUrl, $encrypted);
        Http::json(['configured' => true, 'source_url' => $sourceUrl, 'credentials_configured' => true]);
    }

    private static function validatedSourceUrl(array $requestFields): string
    {
        $sourceUrl = Http::requiredString($requestFields, 'source_url', 2048);
        $parts = parse_url($sourceUrl);
        if (!filter_var($sourceUrl, FILTER_VALIDATE_URL) || !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            Http::json(['error' => 'validation_error', 'fields' => ['source_url' => 'أدخل رابط HTTPS أساسيًا صالحًا دون بيانات دخول أو معاملات.']], 422);
        }
        return rtrim($sourceUrl, '/');
    }

    private static function previousCredentials(?array $existing): array
    {
        if ($existing === null) return [];
        try {
            $credentials = json_decode(SecretCipher::decrypt($existing['credentials_ciphertext']), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException | RuntimeException) {
            Http::json(['error' => 'credentials_unreadable', 'message' => 'تعذر قراءة بيانات الربط المحفوظة؛ أدخل اسم المستخدم وكلمة المرور مجددًا.'], 409);
        }
        return is_array($credentials) ? $credentials : [];
    }

    private static function find(PDO $db): ?array
    {
        $query = $db->query('SELECT source_url, credentials_ciphertext, updated_at FROM integration_settings WHERE id = 1');
        return $query->fetch() ?: null;
    }

    private static function persist(PDO $db, ?array $existing, string $sourceUrl, string $encrypted): void
    {
        $updatedAt = gmdate('Y-m-d H:i:s');
        if ($existing === null) {
            $db->prepare('INSERT INTO integration_settings (id, source_url, credentials_ciphertext, updated_at) VALUES (1, ?, ?, ?)')
                ->execute([$sourceUrl, $encrypted, $updatedAt]);
            return;
        }
        $db->prepare('UPDATE integration_settings SET source_url = ?, credentials_ciphertext = ?, updated_at = ? WHERE id = 1')
            ->execute([$sourceUrl, $encrypted, $updatedAt]);
    }
}
