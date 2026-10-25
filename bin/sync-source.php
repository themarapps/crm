<?php
declare(strict_types=1);

function loadDatabaseEnvironment(): void
{
    $names = ['DB_DSN', 'DB_USER', 'DB_PASSWORD'];
    if (count(array_filter($names, static fn(string $name): bool => getenv($name) !== false && getenv($name) !== '')) === count($names)) return;
    $poolPath = getenv('CRM_FPM_POOL_CONFIG') ?: '/etc/php/8.3/fpm/pool.d/crm.conf';
    $lines = @file($poolPath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) throw new RuntimeException('تعذر تحميل إعداد اتصال قاعدة البيانات.');
    foreach ($lines as $line) {
        if (preg_match('/^\s*env\[(DB_DSN|DB_USER|DB_PASSWORD)\]\s*=\s*"([^"]*)"\s*$/', $line, $match)) {
            putenv($match[1] . '=' . $match[2]);
        }
    }
    foreach ($names as $name) {
        if (getenv($name) === false || getenv($name) === '') throw new RuntimeException('إعداد اتصال قاعدة البيانات غير مكتمل.');
    }
}

function writeSummary(string $message, int $status): never
{
    fwrite($status === 0 ? STDOUT : STDERR, $message . PHP_EOL);
    exit($status);
}

try {
    loadDatabaseEnvironment();
    require_once dirname(__DIR__) . '/src/Database.php';
    require_once dirname(__DIR__) . '/src/ActivityLog.php';
    require_once dirname(__DIR__) . '/src/CsvImporter.php';
    require_once dirname(__DIR__) . '/src/SecretCipher.php';
    require_once dirname(__DIR__) . '/src/IntegrationSettingsService.php';
    require_once dirname(__DIR__) . '/src/SourceCustomerClient.php';
    require_once dirname(__DIR__) . '/src/SourceSyncService.php';
    $db = Database::connection();
    if (!(int) $db->query('SELECT COUNT(*) FROM integration_settings WHERE id = 1')->fetchColumn()) {
        writeSummary('source-sync skipped: connection is not configured.', 0);
    }
    $actorId = (int) $db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    if ($actorId < 1) throw new RuntimeException('لا يوجد مسؤول نشط لتسجيل المزامنة.');
    $result = SourceSyncService::run($db, $actorId);
    writeSummary(sprintf(
        'source-sync total=%d created=%d updated=%d unchanged=%d skipped=%d errors=%d',
        $result['total'], $result['created'], $result['updated'], $result['unchanged'], $result['skipped'], count($result['errors']),
    ), 0);
} catch (SourceSyncBusyException) {
    writeSummary('source-sync skipped: another sync is running.', 0);
} catch (IntegrationSettingsException | SourceSyncException $exception) {
    writeSummary('source-sync failed: ' . $exception->getMessage(), 1);
} catch (Throwable $exception) {
    writeSummary('source-sync failed: internal error (' . get_class($exception) . ').', 1);
}
