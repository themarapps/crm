<?php
declare(strict_types=1);

final class SourceSyncService
{
    private const LOCK_PATH = __DIR__ . '/../storage/source-sync.lock';

    public static function sync(PDO $db, int $actorId): never
    {
        $lock = self::acquireLock();
        $result = null;
        $error = null;
        try {
            $result = self::runSync($db, $actorId);
        } catch (SourceSyncException $exception) {
            $error = $exception->getMessage();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if ($error !== null) Http::json(['error' => 'source_sync_failed', 'message' => $error], 502);
        Http::json($result);
    }

    private static function acquireLock(): mixed
    {
        $lock = fopen(self::LOCK_PATH, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            Http::json(['error' => 'sync_in_progress', 'message' => 'المزامنة تعمل بالفعل؛ انتظر قليلًا ثم حاول مجددًا.'], 409);
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
            'skipped' => $report['skipped'], 'errors' => array_values(array_unique(array_column($report['errors'], 'reason'))),
            'synced_at' => gmdate('c'),
        ];
    }
}
