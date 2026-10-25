<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $pdo = self::openConnection();
        self::configureSqlite($pdo);
        self::$connection = $pdo;

        return $pdo;
    }

    private static function openConnection(): PDO
    {
        $dsn = getenv('DB_DSN') ?: 'sqlite:' . dirname(__DIR__) . '/storage/crm.sqlite';
        self::ensureSqliteDirectory($dsn);
        return new PDO($dsn, getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private static function ensureSqliteDirectory(string $dsn): void
    {
        if (!str_starts_with($dsn, 'sqlite:')) {
            return;
        }
        $path = substr($dsn, strlen('sqlite:'));
        if ($path === ':memory:') {
            return;
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create database directory.');
        }
    }

    private static function configureSqlite(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }
}
