#!/usr/bin/env bash
set -euo pipefail

app_root=${CRM_APP_ROOT:-/var/www/crm}
pool_config=${CRM_FPM_POOL_CONFIG:-/etc/php/8.3/fpm/pool.d/crm.conf}
php_cli=${CRM_PHP_CLI:-/usr/bin/php8.3}

if [[ ! -r "$pool_config" ]]; then
  echo "source-sync failed: PHP-FPM pool configuration is unreadable." >&2
  exit 1
fi

pool_value() {
  awk -v name="$1" '$0 ~ "^[[:space:]]*env\\[" name "\\][[:space:]]*=" {
    sub(/^[^=]*=[[:space:]]*"/, "")
    sub(/".*$/, "")
    print
    exit
  }' "$pool_config"
}

DB_DSN=$(pool_value DB_DSN)
DB_USER=$(pool_value DB_USER)
DB_PASSWORD=$(pool_value DB_PASSWORD)
if [[ -z "$DB_DSN" || -z "$DB_USER" || -z "$DB_PASSWORD" ]]; then
  echo "source-sync failed: CRM database environment is incomplete." >&2
  exit 1
fi
export DB_DSN DB_USER DB_PASSWORD CRM_FPM_POOL_CONFIG="$pool_config"

setpriv --reuid=www-data --regid=www-data --init-groups \
  "$php_cli" "$app_root/bin/sync-source.php" 2>&1 | logger -t crm-source-sync
