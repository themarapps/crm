<?php
declare(strict_types=1);

try {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    require_once dirname(__DIR__) . '/src/Database.php';
    require_once dirname(__DIR__) . '/src/Http.php';
    require_once dirname(__DIR__) . '/src/PushNotificationService.php';
    $db = Database::connection();
    $taskCounts = PushNotificationService::sendDueTasks($db);
    $customerCounts = PushNotificationService::sendNewCustomerNotifications($db);
    fwrite(STDOUT, sprintf(
        'push tasks_notified=%d new_customers_notified=%d expired=%d failed=%d' . PHP_EOL,
        $taskCounts['tasks_notified'], $customerCounts['customers_notified'],
        $taskCounts['expired'] + $customerCounts['expired'], $taskCounts['failed'] + $customerCounts['failed'],
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, 'push failed: internal error (' . get_class($exception) . ').' . PHP_EOL);
    exit(1);
}
