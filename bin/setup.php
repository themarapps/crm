<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Schema.php';
require_once dirname(__DIR__) . '/src/Database.php';

try {
    Schema::ensure(Database::connection());
    fwrite(STDOUT, "CRM schema is ready.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Setup failed: ' . get_class($exception) . "\n");
    exit(1);
}
