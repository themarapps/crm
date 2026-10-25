<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$path = dirname(__DIR__) . '/storage/web-push-vapid.php';
if (is_file($path)) {
    fwrite(STDOUT, "Web Push keys are already provisioned; existing keys were preserved.\n");
    exit(0);
}

if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0750, true) && !is_dir(dirname($path))) {
    fwrite(STDERR, "Unable to create the private storage directory.\n");
    exit(1);
}

try {
    $keys = VAPID::createVapidKeys();
    $contents = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($keys, true) . ";\n";
    $temporaryPath = $path . '.tmp';
    if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false || !rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Unable to securely write the key file.');
    }
    chmod($path, 0640);
    fwrite(STDOUT, "Web Push keys provisioned at storage/web-push-vapid.php. Private key contents were not displayed.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Key provisioning failed: ' . get_class($exception) . "\n");
    exit(1);
}
