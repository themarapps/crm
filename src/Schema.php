<?php
declare(strict_types=1);

final class Schema
{
    public static function ensure(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        self::assertSupportedDriver($driver);
        $schemaTypes = self::schemaTypes($driver);
        self::createTables($db, $schemaTypes);
        if ($driver === 'sqlite') {
            self::createSqliteIndexes($db);
        }
        self::createInitialAdmin($db);
    }

    private static function assertSupportedDriver(string $driver): void
    {
        if (!in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Only SQLite and MySQL are supported.');
        }
    }

    private static function schemaTypes(string $driver): array
    {
        $mysql = $driver === 'mysql';
        return [
            'id' => $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'text' => $mysql ? 'VARCHAR(255)' : 'TEXT',
            'long_text' => $mysql ? 'LONGTEXT' : 'TEXT',
            'engine' => $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '',
            'indexes' => $mysql,
        ];
    }

    private static function createTables(PDO $db, array $types): void
    {
        self::createUsersTable($db, $types);
        self::createCustomersTable($db, $types);
        self::createNotesTable($db, $types);
        self::createTasksTable($db, $types);
        self::createLoginAttemptsTable($db, $types);
    }

    private static function createUsersTable(PDO $db, array $types): void
    {
        $id = $types['id']; $text = $types['text']; $engine = $types['engine'];
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id {$id}, name {$text} NOT NULL, email {$text} NOT NULL UNIQUE,
            password_hash {$text} NOT NULL, role {$text} NOT NULL DEFAULT 'member',
            is_active INTEGER NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
        ){$engine}");
    }

    private static function createCustomersTable(PDO $db, array $types): void
    {
        $id = $types['id']; $text = $types['text']; $longText = $types['long_text']; $engine = $types['engine'];
        $indexes = $types['indexes'] ? ', INDEX customers_stage_idx (crm_stage), INDEX customers_subscription_idx (subscription_status)' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS customers (
            id {$id}, external_id {$text} NULL, name {$text} NOT NULL, email {$text} NOT NULL UNIQUE,
            phone {$text} NULL, account_type {$text} NULL, company_url {$text} NULL,
            registered_at {$text} NULL, email_verified {$text} NULL, plan {$text} NULL,
            billing_cycle {$text} NULL, subscription_status {$text} NULL, payment_status {$text} NULL,
            last_amount {$text} NULL, currency {$text} NULL, source_stage {$text} NULL,
            services {$longText} NULL, service_count INTEGER NULL, crm_stage {$text} NOT NULL DEFAULT 'new',
            assigned_to BIGINT NULL, last_activity_at {$text} NULL, error_count INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL{$indexes}
        ){$engine}");
    }

    private static function createNotesTable(PDO $db, array $types): void
    {
        $id = $types['id']; $longText = $types['long_text']; $engine = $types['engine'];
        $indexes = $types['indexes'] ? ', INDEX crm_notes_customer_idx (customer_id)' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS crm_notes (
            id {$id}, customer_id BIGINT NOT NULL, user_id BIGINT NOT NULL,
            body {$longText} NOT NULL, created_at DATETIME NOT NULL{$indexes}
        ){$engine}");
    }

    private static function createTasksTable(PDO $db, array $types): void
    {
        $id = $types['id']; $text = $types['text']; $longText = $types['long_text']; $engine = $types['engine'];
        $indexes = $types['indexes'] ? ', INDEX follow_up_customer_idx (customer_id), INDEX follow_up_due_idx (due_at), INDEX follow_up_assignee_idx (assignee_id)' : '';
        $db->exec("CREATE TABLE IF NOT EXISTS follow_up_tasks (
            id {$id}, customer_id BIGINT NOT NULL, created_by BIGINT NOT NULL, assignee_id BIGINT NULL,
            title {$text} NOT NULL, due_at {$text} NOT NULL, status {$text} NOT NULL DEFAULT 'open',
            details {$longText} NULL, completed_at {$text} NULL,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL{$indexes}
        ){$engine}");
    }

    private static function createLoginAttemptsTable(PDO $db, array $types): void
    {
        $id = $types['id']; $indexes = $types['indexes'] ? ', INDEX login_attempt_identity_idx (identity_hash, attempted_at)' : '';
        $engine = $types['engine'];
        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id {$id}, identity_hash CHAR(64) NOT NULL, attempted_at DATETIME NOT NULL{$indexes}
        ){$engine}");
    }

    private static function createSqliteIndexes(PDO $db): void
    {
        $db->exec('CREATE INDEX IF NOT EXISTS customers_stage_idx ON customers (crm_stage)');
        $db->exec('CREATE INDEX IF NOT EXISTS customers_subscription_idx ON customers (subscription_status)');
        $db->exec('CREATE INDEX IF NOT EXISTS crm_notes_customer_idx ON crm_notes (customer_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS follow_up_customer_idx ON follow_up_tasks (customer_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS follow_up_due_idx ON follow_up_tasks (due_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS follow_up_assignee_idx ON follow_up_tasks (assignee_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS login_attempt_identity_idx ON login_attempts (identity_hash, attempted_at)');
    }

    private static function createInitialAdmin(PDO $db): void
    {
        if ((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            return;
        }

        $email = strtolower(trim((string) getenv('APP_ADMIN_EMAIL')));
        $password = (string) getenv('APP_ADMIN_PASSWORD');
        if ($email === '' || $password === '') {
            throw new RuntimeException('Set APP_ADMIN_EMAIL and APP_ADMIN_PASSWORD before initializing the CRM database.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            throw new RuntimeException('Initial admin credentials are invalid; use a valid email and an 8+ character password.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $adminInsert = $db->prepare('INSERT INTO users (name, email, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)');
        $adminInsert->execute(['Administrator', $email, password_hash($password, PASSWORD_DEFAULT), 'admin', $now, $now]);
    }
}
