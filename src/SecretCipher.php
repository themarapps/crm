<?php
declare(strict_types=1);

final class SecretCipher
{
    private const KEY_PATH = __DIR__ . '/../storage/integration-encryption.key';
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    public static function isAvailable(): bool
    {
        return function_exists('openssl_encrypt') && is_readable(self::KEY_PATH)
            && strlen((string) file_get_contents(self::KEY_PATH)) === 32;
    }

    public static function encrypt(string $value): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($value, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('Secret encryption failed.');
        }
        return base64_encode($nonce . $tag . $ciphertext);
    }

    public static function decrypt(string $value): string
    {
        $encoded = base64_decode($value, true);
        if ($encoded === false || strlen($encoded) <= self::NONCE_BYTES + self::TAG_BYTES) {
            throw new RuntimeException('Stored credentials are invalid.');
        }
        $plaintext = openssl_decrypt(
            substr($encoded, self::NONCE_BYTES + self::TAG_BYTES),
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            substr($encoded, 0, self::NONCE_BYTES),
            substr($encoded, self::NONCE_BYTES, self::TAG_BYTES),
        );
        if ($plaintext === false) {
            throw new RuntimeException('Stored credentials could not be decrypted.');
        }
        return $plaintext;
    }

    private static function key(): string
    {
        $key = is_readable(self::KEY_PATH) ? file_get_contents(self::KEY_PATH) : false;
        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('Integration encryption key is unavailable.');
        }
        return $key;
    }
}
