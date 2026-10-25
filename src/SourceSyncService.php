<?php
declare(strict_types=1);

final class SourceSyncBusyException extends RuntimeException
{
}

final class SourceSyncService
{
    private const LOCK_PATH = __DIR__ . '/../storage/source-sync.lock';

    public static function sync(PDO $db, int $actorId): never
    {
        try {
            Http::json(self::run($db, $actorId));
        } catch (SourceSyncBusyException $exception) {
            Http::json(['error' => 'sync_in_progress', 'message' => $exception->getMessage()], 409);
        } catch (IntegrationSettingsException $exception) {
            Http::json(['error' => 'integration_not_configured', 'message' => $exception->getMessage()], 409);
        } catch (SourceSyncException $exception) {
            Http::json(['error' => 'source_sync_failed', 'message' => $exception->getMessage()], 502);
        }
    }

    public static function run(PDO $db, int $actorId): array
    {
        $lock = self::acquireLock();
        try {
            return self::runSync($db, $actorId);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function acquireLock(): mixed
    {
        $lock = fopen(self::LOCK_PATH, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new SourceSyncBusyException('المزامنة تعمل بالفعل؛ انتظر قليلًا ثم حاول مجددًا.');
        }
        return $lock;
    }

    private static function runSync(PDO $db, int $actorId): array
    {
        $connection = IntegrationSettingsService::connectionDetails($db);
        $client = new SourceCustomerClient($connection['source_url'], $connection['username'], $connection['password']);
        $records = $client->fetchCustomers();
        $report = CsvImporter::importSourceRecords($db, $records, $actorId);
        return [
            'total' => count($records), 'created' => $report['created'], 'updated' => $report['updated'],
            'unchanged' => $report['unchanged'],
            'skipped' => $report['skipped'], 'errors' => array_values(array_unique(array_column($report['errors'], 'reason'))),
            'synced_at' => gmdate('c'),
        ];
    }
}
