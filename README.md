# CRM

Small, dependency-free PHP CRM with an Arabic, responsive interface and same-origin API. It supports customer records, notes, follow-up tasks, team accounts, filters, and repeat CSV imports. Serve `public/` as the document root and keep `storage/` outside the public directory.

## Requirements

- PHP 8.2 or newer with PDO and either `pdo_sqlite` or `pdo_mysql`.
- HTTPS on any shared server.
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
- `GET /api/dashboard` — counts and upcoming/overdue follow-ups scoped to the signed-in member
- `GET /api/reports/summary` — customer totals grouped by stage, subscription status, and plan (manager/admin)
- `GET|PUT /api/settings/source` — administrator-only external source connection settings; credentials are encrypted at rest
- `POST /api/settings/source/sync` — administrator-only customer sync from the configured source, matched by normalized email
- `GET|POST /api/customers`
- `GET /api/customers/export.csv` — exports matching filters (manager/admin; maximum 10,000 rows)
- `GET /api/customers/options` — distinct filter options from saved customers
- `GET|PUT /api/customers/{id}`
- `GET|POST /api/customers/{id}/notes`
- `GET|POST /api/tasks`, `PUT /api/tasks/{id}`
- `GET|POST /api/team`, `PUT /api/team/{id}` (team changes require an administrator)
- `POST /api/import/csv/preview` — checks rows and estimates inserts, updates, and skips without writing
- `POST /api/import/csv` — imports the reviewed file

Customer create and update use JSON field names matching the CSV columns in
snake_case, plus `crm_stage` (`new`, `contacted`, `interested`,
`not_interested`) and `assigned_to`. `PUT /api/customers/{id}` is a full update:
send the fields the form intends to keep. `GET /api/customers/{id}` returns
`customer`, `notes`, and `tasks`. Notes use `{ "body": "..." }`. Tasks use
`customer_id`, `title`, `due_at` (ISO 8601), optional `assignee_id`, `details`,
and `status` (`open` or `done`).

Supported customer fields are `external_id`, `name`, `email`, `phone`,
`account_type`, `company_url`, `registered_at`, `email_verified`, `plan`,
`billing_cycle`, `subscription_status`, `payment_status`, `last_amount`,
`currency`, `source_stage`, `services`, `service_count`, `last_activity_at`,
`error_count`, `crm_stage`, and `assigned_to`. Customer list responses use
`{ "data": [...], "page": 1, "per_page": 25, "total": 3 }`. Dashboard counts
and upcoming tasks are returned by `/api/dashboard`.

Customer list filters: `q`, `subscription_status`, `plan`, `stage`, `assigned_to`,
`page`, `per_page`. Task list filters: `status`, `due` (`today`, `overdue`, `upcoming`).
Roles are `admin` (full access and team management), `manager` (all customer, task,
report, import, and export access), and `member` (customers assigned to them and
their related follow-ups). Customer notes, edits, tasks, and CSV imports are recorded
in the customer activity history.

The external source settings page stores the source base URL and encrypted login
credentials. The 32-byte key at `storage/integration-encryption.key` must be provisioned
outside Git as a root-owned `0640` file with the CRM PHP-FPM group, and backed up
separately from the database dump. The administrator can trigger a paginated source sync
from this page. Existing customers are matched by normalized email and only imported
account fields are updated; CRM stages, assignments, notes, and follow-up tasks remain.

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
