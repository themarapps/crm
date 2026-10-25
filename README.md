# CRM

Small PHP CRM with an Arabic, responsive interface and same-origin API. It supports customer records, notes, follow-up tasks, team accounts, filters, repeat CSV imports, browser push notifications, and installable PWA support. Serve `public/` as the document root and keep `storage/` outside the public directory.

## Requirements

- PHP 8.2 or newer with PDO and either `pdo_sqlite` or `pdo_mysql`.
- HTTPS on any shared server.
- Composer for the Web Push dependency (`minishlink/web-push`).
- A database DSN and an initial administrator email and password.

SQLite is the local default. MySQL can be selected by setting `DB_DSN`, for
example `mysql:host=127.0.0.1;dbname=crm;charset=utf8mb4`, and setting
`DB_USER` / `DB_PASSWORD`.

## Configure

Copy `.env.example` values into the host's environment (the app deliberately
does not load `.env` files). Set `APP_ADMIN_EMAIL` and a strong
`APP_ADMIN_PASSWORD`, then run `php bin/setup.php` once. The initial
administrator is created only when the users table is empty. Change or remove
those bootstrap variables after initialization. The public API does not run
schema changes on normal requests.

For local development, create `storage/` writable by PHP and run:

```sh
APP_ADMIN_EMAIL=admin@example.test APP_ADMIN_PASSWORD='use-a-long-password' \
php bin/setup.php

php -S 127.0.0.1:8080 -t public public/index.php
```

Do not expose PHP's development server to the public internet.

## API

All endpoints use JSON except `POST /api/import/csv`, which accepts multipart
form data with a `file` field. Authenticated write requests require the CSRF
token returned by `GET /api/csrf` in the `X-CSRF-Token` header.

- `GET /api/csrf`
- `POST /api/auth/login` — `{ "email": "...", "password": "..." }`
- `POST /api/auth/logout`
- `GET /api/me`
- `GET /api/dashboard` — counts, upcoming/overdue follow-ups, and customers with no recorded activity for 14 days, scoped to customer visibility
- `GET /api/reports/summary` — customer totals grouped by stage, subscription status, and plan, plus team performance (manager/admin)
- `GET|PUT|DELETE /api/settings/source` — administrator-only external source connection settings; credentials are encrypted at rest, and delete removes the saved connection without deleting CRM customer records
- `POST /api/settings/source/sync` — administrator-only customer sync from the configured source, matched by normalized email
- `GET|POST /api/customers`
- `POST /api/customers/bulk` — atomically updates stage and/or assignment for up to 100 accessible customers (edit access required; assignment requires view-all)
- `GET /api/customers/export.csv` — exports matching filters (manager/admin; maximum 10,000 rows)
- `GET /api/customers/options` — distinct filter options from saved customers
- `GET|PUT /api/customers/{id}`
- `GET|POST /api/customers/{id}/notes`
- `GET /api/tasks?from=YYYY-MM-DD&to=YYYY-MM-DD` — tasks within Riyadh calendar dates (maximum 42 days); existing status and due filters also apply
- `GET|POST /api/tasks`, `PUT /api/tasks/{id}`
- `GET|POST /api/team`, `PUT /api/team/{id}` (team changes require an administrator)
- `GET /api/audit` — latest team and customer-permission changes (administrator)
- `POST /api/import/csv/preview` — checks rows and estimates inserts, updates, and skips without writing
- `POST /api/import/csv` — imports the reviewed file
- `POST /api/leads/inbound` — server-to-server lead intake, authenticated with `Authorization: Bearer <token>`

Customer create and update use JSON field names matching the CSV columns in
snake_case, plus `crm_stage` (`new`, `contacted`, `appointment_scheduled`, `interested`,
`not_interested`) and `assigned_to`. Optional CRM metadata includes `lead_source`,
`campaign`, and `tags` (an array of up to 20 tag names). Omitting optional metadata
from an update preserves its current value. `PUT /api/customers/{id}` is a full update:
send the fields the form intends to keep. `GET /api/customers/{id}` returns
`customer`, `notes`, `tasks`, `tags`, and `activity`. Notes use `{ "body": "..." }`. Tasks use
`customer_id`, `title`, `due_at` (ISO 8601), optional `assignee_id`, `details`,
and `status` (`open` or `done`).

Supported customer fields are `external_id`, `name`, `email`, `phone`,
`account_type`, `company_url`, `registered_at`, `email_verified`, `plan`,
`billing_cycle`, `subscription_status`, `payment_status`, `last_amount`,
`currency`, `source_stage`, `services`, `service_count`, `last_activity_at`,
`error_count`, `crm_stage`, and `assigned_to`. Customer list responses use
`{ "data": [...], "page": 1, "per_page": 25, "total": 3 }`. Dashboard counts
and upcoming tasks are returned by `/api/dashboard`. `/api/customers/options`
also returns lead sources, campaigns, and tags visible to the signed-in member.

Customer list filters: `q`, `subscription_status`, `payment_status`, `plan`, `stage`, `assigned_to`, `lead_source`, `campaign`, `tag`,
`sort` (`name`, `phone`, `plan`, `subscription_status`, `crm_stage`, `assigned_to`, `registered_at`, `updated_at`), `direction` (`asc`, `desc`), `page`, `per_page`. Users can save named filter views in this browser; views are stored locally per signed-in user and do not sync across devices. Bulk updates accept `{ "ids": [1, 2], "crm_stage": "contacted", "assigned_to": 4 }`; either update field may be omitted, and use `null` for an unassigned owner. Task list filters: `status`, `due` (`today`, `overdue`, `upcoming`). The tasks page calendar shows a six-week month grid in `Asia/Riyadh`, task counts by day, and the selected day's task list; the existing overdue/today/upcoming task sections remain available below it.
Dashboard inactivity reminders use the last recorded customer activity, then registration/creation time when no activity exists. Team performance counts customers and overdue tasks by assigned owner. Its conversion rate is the share of that owner's customers currently in `contacted`, `appointment_scheduled`, or `interested` stage; it is a current-stage share, not a historical cohort conversion rate.
Roles are `admin` (full access and team management), `manager` (all customer, task,
report, import, and export access), and `member` (customers assigned to them and
their related follow-ups). Administrators can override each non-admin member's
customer visibility (all customers or assigned customers) and editing access. The edit
permission also controls customer notes and follow-up task changes. Customer notes,
edits, tag changes, tasks, and CSV imports are recorded in customer activity history;
team account and permission changes are recorded in the administrator audit log.

The external source settings page stores the source base URL and encrypted login
credentials. The 32-byte key at `storage/integration-encryption.key` must be provisioned
outside Git as a root-owned `0640` file with the CRM PHP-FPM group, and backed up
separately from the database dump. The administrator can trigger a paginated source sync
from this page. Existing customers are matched by normalized email and only changed
imported fields are written; unchanged rows are not updated or logged. CRM stages,
assignments, notes, tags, lead attribution, and follow-up tasks remain. `bin/sync-source.php` runs the same
sync in scheduled jobs using the database environment, and reports counts only. The
10-minute cron template is `deploy/crm-source-sync.cron`; its root-owned wrapper reads
the PHP-FPM pool settings, then runs the sync process as `www-data`. Runs are logged with
the `crm-source-sync` syslog tag using counts only.

Website forms and server-side integrations can post canonical JSON to
`/api/leads/inbound`. Configure `CRM_LEAD_WEBHOOK_TOKEN` in the PHP-FPM/server
environment as a random URL-safe value of at least 32 characters; do not place it
in browser JavaScript or commit it to Git. The endpoint accepts `name`, `email`,
optional `phone`, `lead_source`, and `campaign`. It normalizes email and updates a
matching customer's name and supplied phone while preserving CRM stage, owner,
tags, notes, and tasks. New leads start in `new`; their source defaults to
`website_form`. The endpoint supports server-to-server submissions and does not
configure direct connections to any specific form or messaging provider.

After installing a CRM update that adds schema objects or columns, run `php bin/setup.php`
once against the configured database before serving requests. It creates additive
schema objects; normal API requests do not run schema setup.

## Browser notifications and PWA

The CRM can be installed from supported browsers and sends push reminders for open follow-up tasks to the task assignee. When a task has no assignee, it uses the customer's assigned owner, then the task creator. New customer notifications go to administrators and the assigned customer owner; imports or sync updates to existing customers do not count as new. The server checks every minute and sends generic reminders for tasks or customers created within the last 15 minutes; successful deliveries are recorded to avoid repeats. The browser must grant notification permission. On iPhone and iPad, install the web app to the Home Screen and open it there before enabling notifications.

Install dependencies with `composer install --no-dev --classmap-authoritative`, run `php bin/setup.php` once to create the additive push tables, then provision stable VAPID keys once with `sudo -u www-data php bin/setup-web-push.php`. The private key stays in ignored `storage/web-push-vapid.php`; back it up securely and do not rotate it while subscriptions are active. Configure the root cron entry from `deploy/crm-push-notifications.cron` and install `deploy/crm-push-notifications-wrapper.sh` as `/usr/local/sbin/crm-push-notifications`, adjusting its app root, PHP CLI, and PHP-FPM pool path if needed. The wrapper runs as `www-data` and logs delivery counts only. The cron is independent from the existing 10-minute customer source sync.

CSV rows are matched by normalized email. The interface previews a file before
the user confirms the import. Arabic headers from the supplied export
and generic English headers such as `name`, `email`, `phone`, `plan`, and
`subscription_status` are supported. A unique matching row updates imported
account fields while preserving CRM stage, assignment, notes, and tasks. A new
email creates a customer. Missing or duplicate emails are skipped and returned
in the import report. One leading apostrophe is removed from phone values to
support spreadsheet text markers.
The import response is `{ "created": N, "updated": N, "skipped": N,
"errors": [{ "row": N, "email": "...", "reason": "..." }] }`. Task due dates
are stored in UTC; `due=today` and the dashboard's today counts use
`Asia/Riyadh` calendar boundaries.

## Production notes

Use a real HTTPS web server and a persistent database. Keep PHP session files
and the database directory writable only by the application account, and back
up the database. This repository does not contain deployment
credentials and does not deploy the application.
# crm
