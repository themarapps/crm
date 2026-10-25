<?php
declare(strict_types=1);

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

final class PushNotificationService
{
    private const DUE_WINDOW_SECONDS = 900;
    private const MAX_BATCH_SIZE = 500;
    private const ALLOWED_ENDPOINT_HOSTS = [
        'fcm.googleapis.com',
        'web.push.apple.com',
        'updates.push.services.mozilla.com',
    ];

    public static function config(): never
    {
        $keys = self::vapidKeys();
        Http::json(['enabled' => $keys !== null, 'public_key' => $keys['publicKey'] ?? null]);
    }

    public static function subscribe(PDO $db, array $requestFields, array $user): never
    {
        if (self::vapidKeys() === null) Http::json(['error' => 'push_unavailable', 'message' => 'إشعارات المتصفح غير مهيأة على الخادم.'], 503);
        $subscription = self::validatedSubscription($requestFields);
        $now = gmdate('Y-m-d H:i:s');
        $sql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INSERT INTO web_push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth_token, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth_token = VALUES(auth_token), updated_at = VALUES(updated_at)'
            : 'INSERT INTO web_push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth_token, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT(endpoint_hash) DO UPDATE SET user_id = excluded.user_id, endpoint = excluded.endpoint, p256dh = excluded.p256dh, auth_token = excluded.auth_token, updated_at = excluded.updated_at';
        $db->prepare($sql)->execute([(int) $user['id'], hash('sha256', $subscription['endpoint']), $subscription['endpoint'], $subscription['p256dh'], $subscription['auth'], $now, $now]);
        Http::json(['saved' => true], 201);
    }

    public static function unsubscribe(PDO $db, array $requestFields, array $user): never
    {
        $endpoint = self::validatedEndpoint($requestFields['endpoint'] ?? null);
        $db->prepare('DELETE FROM web_push_subscriptions WHERE user_id = ? AND endpoint_hash = ?')
            ->execute([(int) $user['id'], hash('sha256', $endpoint)]);
        Http::json(['deleted' => true]);
    }

    public static function removeForLogout(PDO $db, string $endpoint, int $userId): void
    {
        if (strlen($endpoint) > 4096) return;
        $db->prepare('DELETE FROM web_push_subscriptions WHERE user_id = ? AND endpoint_hash = ?')
            ->execute([$userId, hash('sha256', $endpoint)]);
    }

    public static function sendDueTasks(PDO $db): array
    {
        $keys = self::vapidKeys();
        if ($keys === null) throw new RuntimeException('Web Push keys are not configured.');
        $groups = self::dueTaskGroups($db);
        if (!$groups) return ['subscriptions' => 0, 'tasks_notified' => 0, 'expired' => 0, 'failed' => 0];
        $webPush = self::webPushClient($keys);
        self::queueDueTaskNotifications($webPush, $groups);
        return self::processPushReports($db, $webPush, $groups);
    }

    public static function sendNewCustomerNotifications(PDO $db): array
    {
        $keys = self::vapidKeys();
        if ($keys === null) throw new RuntimeException('Web Push keys are not configured.');
        $groups = self::newCustomerGroups($db);
        if (!$groups) return ['subscriptions' => 0, 'customers_notified' => 0, 'expired' => 0, 'failed' => 0];
        $webPush = self::webPushClient($keys);
        self::queueNewCustomerNotifications($webPush, $groups);
        return self::processNewCustomerReports($db, $webPush, $groups);
    }

    private static function webPushClient(array $keys): WebPush
    {
        $webPush = new WebPush(['VAPID' => [
            'subject' => 'https://crm.y7ytech.com',
            'publicKey' => $keys['publicKey'],
            'privateKey' => $keys['privateKey'],
        ]], ['TTL' => 300, 'urgency' => 'high']);
        $webPush->setReuseVAPIDHeaders(true);
        return $webPush;
    }

    private static function queueDueTaskNotifications(WebPush $webPush, array $groups): void
    {
        foreach ($groups as $endpointHash => $group) {
            $taskIds = $group['task_ids'];
            $payload = json_encode([
                'title' => 'موعد متابعة العميل',
                'body' => 'لديك ' . count($taskIds) . ' متابعة مستحقة. افتح التطبيق لمراجعتها.',
                'url' => '/#/tasks',
                'tag' => 'crm-due-tasks',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $group['endpoint'],
                'keys' => ['p256dh' => $group['p256dh'], 'auth' => $group['auth_token']],
            ]), $payload, ['topic' => 'crm' . substr(hash('sha256', implode(',', $taskIds)), 0, 24)]);
        }
    }

    private static function newCustomerGroups(PDO $db): array
    {
        $now = time();
        $query = $db->prepare("SELECT c.id AS customer_id, s.id AS subscription_id, s.endpoint_hash, s.endpoint, s.p256dh, s.auth_token FROM customers c JOIN users u ON u.is_active = 1 JOIN web_push_subscriptions s ON s.user_id = u.id LEFT JOIN customer_push_deliveries d ON d.customer_id = c.id AND d.subscription_id = s.id WHERE c.created_at > ? AND c.created_at <= ? AND d.id IS NULL AND (u.role = 'admin' OR c.assigned_to = u.id) ORDER BY c.created_at ASC, c.id ASC LIMIT " . self::MAX_BATCH_SIZE);
        $query->execute([gmdate('Y-m-d H:i:s', $now - self::DUE_WINDOW_SECONDS), gmdate('Y-m-d H:i:s', $now)]);
        $groups = [];
        foreach ($query->fetchAll() as $row) {
            $endpointHash = $row['endpoint_hash'];
            if (!isset($groups[$endpointHash])) $groups[$endpointHash] = [
                'subscription_id' => (int) $row['subscription_id'], 'endpoint' => $row['endpoint'],
                'p256dh' => $row['p256dh'], 'auth_token' => $row['auth_token'], 'customer_ids' => [],
            ];
            $groups[$endpointHash]['customer_ids'][] = (int) $row['customer_id'];
        }
        return $groups;
    }

    private static function queueNewCustomerNotifications(WebPush $webPush, array $groups): void
    {
        foreach ($groups as $endpointHash => $group) {
            $customerIds = $group['customer_ids'];
            $payload = json_encode([
                'title' => 'وصل عميل جديد',
                'body' => count($customerIds) === 1 ? 'تمت إضافة عميل جديد إلى CRM.' : 'تمت إضافة ' . count($customerIds) . ' عملاء جدد إلى CRM.',
                'url' => '/#/customers',
                'tag' => 'crm-new-customers',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $group['endpoint'],
                'keys' => ['p256dh' => $group['p256dh'], 'auth' => $group['auth_token']],
            ]), $payload, ['topic' => 'crm' . substr(hash('sha256', implode(',', $customerIds)), 0, 24)]);
        }
    }

    private static function processNewCustomerReports(PDO $db, WebPush $webPush, array $groups): array
    {
        $counts = ['subscriptions' => count($groups), 'customers_notified' => 0, 'expired' => 0, 'failed' => 0];
        foreach ($webPush->flush() as $report) {
            $endpointHash = hash('sha256', $report->getEndpoint());
            if (!isset($groups[$endpointHash])) {
                $counts['failed']++;
            } elseif ($report->isSuccess()) {
                $group = $groups[$endpointHash];
                self::recordCustomerDeliveries($db, $group['subscription_id'], $group['customer_ids']);
                $counts['customers_notified'] += count($group['customer_ids']);
            } elseif ($report->isSubscriptionExpired()) {
                $db->prepare('DELETE FROM web_push_subscriptions WHERE endpoint_hash = ?')->execute([$endpointHash]);
                $counts['expired']++;
            } else {
                $counts['failed']++;
            }
        }
        return $counts;
    }

    private static function processPushReports(PDO $db, WebPush $webPush, array $groups): array
    {
        $counts = ['subscriptions' => count($groups), 'tasks_notified' => 0, 'expired' => 0, 'failed' => 0];
        foreach ($webPush->flush() as $report) {
            $endpointHash = hash('sha256', $report->getEndpoint());
            if (!isset($groups[$endpointHash])) {
                $counts['failed']++;
            } elseif ($report->isSuccess()) {
                $group = $groups[$endpointHash];
                self::recordDeliveries($db, $group['subscription_id'], $group['task_ids']);
                $counts['tasks_notified'] += count($group['task_ids']);
            } elseif ($report->isSubscriptionExpired()) {
                $db->prepare('DELETE FROM web_push_subscriptions WHERE endpoint_hash = ?')->execute([$endpointHash]);
                $counts['expired']++;
            } else {
                $counts['failed']++;
            }
        }
        return $counts;
    }

    private static function dueTaskGroups(PDO $db): array
    {
        $now = time();
        $query = $db->prepare("SELECT t.id AS task_id, s.id AS subscription_id, s.endpoint_hash, s.endpoint, s.p256dh, s.auth_token FROM follow_up_tasks t JOIN customers c ON c.id = t.customer_id JOIN web_push_subscriptions s ON s.user_id = COALESCE(t.assignee_id, c.assigned_to, t.created_by) JOIN users u ON u.id = s.user_id AND u.is_active = 1 LEFT JOIN task_push_deliveries d ON d.task_id = t.id AND d.subscription_id = s.id WHERE t.status = 'open' AND t.due_at > ? AND t.due_at <= ? AND d.id IS NULL ORDER BY t.due_at ASC, t.id ASC LIMIT " . self::MAX_BATCH_SIZE);
        $query->execute([gmdate('Y-m-d H:i:s', $now - self::DUE_WINDOW_SECONDS), gmdate('Y-m-d H:i:s', $now)]);
        $groups = [];
        foreach ($query->fetchAll() as $row) {
            $hash = $row['endpoint_hash'];
            if (!isset($groups[$hash])) $groups[$hash] = [
                'subscription_id' => (int) $row['subscription_id'], 'endpoint' => $row['endpoint'],
                'p256dh' => $row['p256dh'], 'auth_token' => $row['auth_token'], 'tasks' => [],
            ];
            $groups[$hash]['tasks'][] = ['task_id' => (int) $row['task_id']];
        }
        foreach ($groups as &$group) {
            $group['task_ids'] = array_column($group['tasks'], 'task_id');
        }
        unset($group);
        return $groups;
    }

    private static function recordDeliveries(PDO $db, int $subscriptionId, array $taskIds): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $prefix = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $insert = $db->prepare($prefix . ' INTO task_push_deliveries (task_id, subscription_id, delivered_at) VALUES (?, ?, ?)');
        foreach ($taskIds as $taskId) $insert->execute([$taskId, $subscriptionId, $now]);
    }

    private static function recordCustomerDeliveries(PDO $db, int $subscriptionId, array $customerIds): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $prefix = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $insert = $db->prepare($prefix . ' INTO customer_push_deliveries (customer_id, subscription_id, delivered_at) VALUES (?, ?, ?)');
        foreach ($customerIds as $customerId) $insert->execute([$customerId, $subscriptionId, $now]);
    }

    private static function validatedSubscription(array $requestFields): array
    {
        $endpoint = self::validatedEndpoint($requestFields['endpoint'] ?? null);
        $keys = $requestFields['keys'] ?? null;
        $p256dh = is_array($keys) ? ($keys['p256dh'] ?? null) : null;
        $auth = is_array($keys) ? ($keys['auth'] ?? null) : null;
        if (!self::isEncodedKey($p256dh, 40, 180) || !self::isEncodedKey($auth, 16, 100)) {
            Http::json(['error' => 'validation_error', 'fields' => ['keys' => 'مفاتيح إشعار المتصفح غير صالحة.']], 422);
        }
        return ['endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth];
    }

    private static function isEncodedKey(mixed $key, int $minimum, int $maximum): bool
    {
        return is_string($key) && strlen($key) >= $minimum && strlen($key) <= $maximum
            && preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $key) === 1;
    }

    private static function validatedEndpoint(mixed $endpoint): string
    {
        if (!is_string($endpoint) || strlen($endpoint) > 4096) {
            Http::json(['error' => 'validation_error', 'fields' => ['endpoint' => 'عنوان اشتراك الإشعارات غير صالح.']], 422);
        }
        $parts = parse_url($endpoint);
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $allowed = in_array($host, self::ALLOWED_ENDPOINT_HOSTS, true)
            || str_ends_with($host, '.push.services.mozilla.com')
            || str_ends_with($host, '.notify.windows.com');
        if (($parts['scheme'] ?? '') !== 'https' || !$allowed) {
            Http::json(['error' => 'validation_error', 'fields' => ['endpoint' => 'خدمة الإشعارات غير مدعومة.']], 422);
        }
        return $endpoint;
    }

    private static function vapidKeys(): ?array
    {
        $path = dirname(__DIR__) . '/storage/web-push-vapid.php';
        if (!is_file($path)) return null;
        $keys = require $path;
        if (!is_array($keys) || !isset($keys['publicKey'], $keys['privateKey'])) return null;
        return $keys;
    }
}
