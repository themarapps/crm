<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Schema.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Http.php';
require_once dirname(__DIR__) . '/src/CustomerAccess.php';
require_once dirname(__DIR__) . '/src/Auth.php';
require_once dirname(__DIR__) . '/src/CustomerService.php';
require_once dirname(__DIR__) . '/src/ActivityLog.php';
require_once dirname(__DIR__) . '/src/LeadIntakeService.php';
require_once dirname(__DIR__) . '/src/CsvImporter.php';
require_once dirname(__DIR__) . '/src/TaskService.php';
require_once dirname(__DIR__) . '/src/TeamService.php';
require_once dirname(__DIR__) . '/src/SecretCipher.php';
require_once dirname(__DIR__) . '/src/IntegrationSettingsService.php';
require_once dirname(__DIR__) . '/src/SourceCustomerClient.php';
require_once dirname(__DIR__) . '/src/SourceSyncService.php';
require_once dirname(__DIR__) . '/src/DashboardService.php';
require_once dirname(__DIR__) . '/src/Time.php';
require_once dirname(__DIR__) . '/src/PushNotificationService.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('crm_session');
session_set_cookie_params([
    'httponly' => true,
    'secure' => $https,
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();
Http::initializeCsrf();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($method === 'GET' && str_starts_with($path, '/assets/') && is_file(__DIR__ . $path)) {
    return false;
}
if ($method === 'GET' && $path === '/service-worker.js' && is_file(__DIR__ . $path)) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-cache');
    readfile(__DIR__ . $path);
    exit;
}
if ($method === 'GET' && $path === '/manifest.webmanifest' && is_file(__DIR__ . $path)) {
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: no-cache');
    readfile(__DIR__ . $path);
    exit;
}
if ($method === 'GET' && str_starts_with($path, '/icons/') && is_file(__DIR__ . $path)) {
    return false;
}
if ($method === 'GET' && ($path === '/' || preg_match('#^/(dashboard|customers(?:/\d+)?|tasks|import|team|reports|settings)$#', $path))) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    exit;
}

try {
    $db = Database::connection();

    if ($path === '/api/health' && $method === 'GET') {
        Http::json(['ok' => true]);
    }
    if ($path === '/api/csrf' && $method === 'GET') {
        Http::json(['csrf_token' => Http::csrf()]);
    }
    if ($path === '/api/auth/login' && $method === 'POST') {
        Http::requireCsrf();
        Auth::login($db, Http::body());
    }
    if ($path === '/api/auth/logout' && $method === 'POST') {
        $logoutUser = Auth::requireUser($db);
        Http::requireCsrf();
        $logoutFields = Http::body();
        $endpoint = $logoutFields['push_endpoint'] ?? null;
        if (is_string($endpoint) && $endpoint !== '') {
            PushNotificationService::removeForLogout($db, $endpoint, (int) $logoutUser['id']);
        }
        $_SESSION = [];
        session_regenerate_id(true);
        Http::json(['ok' => true]);
    }

    if ($path === '/api/leads/inbound' && $method === 'POST') {
        LeadIntakeService::receive($db);
    }

    $user = Auth::requireUser($db);
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        Http::requireCsrf();
    }
    if ($path === '/api/me' && $method === 'GET') {
        Http::json(['user' => $user, 'csrf_token' => Http::csrf()]);
    }
    if ($path === '/api/notifications/config' && $method === 'GET') {
        PushNotificationService::config();
    }
    if ($path === '/api/notifications/subscription' && $method === 'POST') {
        PushNotificationService::subscribe($db, Http::body(), $user);
    }
    if ($path === '/api/notifications/subscription' && $method === 'DELETE') {
        PushNotificationService::unsubscribe($db, Http::body(), $user);
    }
    if ($path === '/api/dashboard' && $method === 'GET') {
        DashboardService::summary($db, $user);
    }
    if ($path === '/api/reports/summary' && $method === 'GET') {
        CustomerService::report($db, $user);
    }
    if ($path === '/api/settings/source' && $method === 'GET') {
        Auth::requireAdmin($user);
        IntegrationSettingsService::show($db);
    }
    if ($path === '/api/settings/source' && $method === 'PUT') {
        Auth::requireAdmin($user);
        IntegrationSettingsService::save($db, Http::body());
    }
    if ($path === '/api/settings/source' && $method === 'DELETE') {
        Auth::requireAdmin($user);
        IntegrationSettingsService::delete($db);
    }
    if ($path === '/api/settings/source/sync' && $method === 'POST') {
        Auth::requireAdmin($user);
        SourceSyncService::sync($db, (int) $user['id']);
    }
    if ($path === '/api/customers' && $method === 'GET') {
        CustomerService::list($db, $_GET, $user);
    }
    if ($path === '/api/customers/options' && $method === 'GET') {
        CustomerService::filterOptions($db, $user);
    }
    if ($path === '/api/customers/export.csv' && $method === 'GET') {
        CustomerService::export($db, $_GET, $user);
    }
    if ($path === '/api/customers' && $method === 'POST') {
        CustomerService::save($db, Http::body(), $user);
    }
    if ($path === '/api/customers/bulk' && $method === 'POST') {
        CustomerService::bulkUpdate($db, Http::body(), $user);
    }
    if ($path === '/api/import/csv/preview' && $method === 'POST') {
        Auth::requireManager($user);
        CustomerAccess::requireImport($user);
        CsvImporter::preview($db, $_FILES['file'] ?? []);
    }
    if ($path === '/api/import/csv' && $method === 'POST') {
        Auth::requireManager($user);
        CustomerAccess::requireImport($user);
        CsvImporter::import($db, $_FILES['file'] ?? [], (int) $user['id']);
    }
    if (preg_match('#^/api/customers/(\d+)$#', $path, $matches)) {
        $id = (int) $matches[1];
        if ($method === 'GET') {
            CustomerService::show($db, $id, $user);
        }
        if ($method === 'PUT') {
            CustomerService::save($db, Http::body(), $user, $id);
        }
    }
    if (preg_match('#^/api/customers/(\d+)/notes$#', $path, $matches)) {
        $id = (int) $matches[1];
        if ($method === 'GET') {
            CustomerService::notes($db, $id, $user);
        }
        if ($method === 'POST') {
            CustomerService::addNote($db, $id, Http::body(), $user);
        }
    }
    if ($path === '/api/tasks' && $method === 'GET') {
        TaskService::list($db, $_GET, $user);
    }
    if ($path === '/api/tasks' && $method === 'POST') {
        TaskService::save($db, Http::body(), $user);
    }
    if (preg_match('#^/api/tasks/(\d+)$#', $path, $matches) && $method === 'PUT') {
        TaskService::save($db, Http::body(), $user, (int) $matches[1]);
    }
    if ($path === '/api/team' && $method === 'GET') {
        TeamService::list($db, $user);
    }
    if ($path === '/api/audit' && $method === 'GET') {
        TeamService::audit($db, $user);
    }
    if ($path === '/api/team' && $method === 'POST') {
        Auth::requireAdmin($user);
        TeamService::save($db, Http::body(), (int) $user['id']);
    }
    if (preg_match('#^/api/team/(\d+)$#', $path, $matches) && $method === 'PUT') {
        Auth::requireAdmin($user);
        TeamService::save($db, Http::body(), (int) $user['id'], (int) $matches[1]);
    }

    Http::json(['error' => 'not_found', 'message' => 'المسار غير موجود.'], 404);
} catch (Throwable $exception) {
    error_log('CRM request failed: ' . get_class($exception));
    Http::json(['error' => 'server_error', 'message' => 'حدث خطأ غير متوقع.'], 500);
}
