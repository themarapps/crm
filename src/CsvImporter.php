<?php
declare(strict_types=1);

final class CsvImporter
{
    private const MAX_FILE_BYTES = 5242880;
    private const MAX_ROWS = 10000;
    private const CUSTOMER_FIELDS = [
        'external_id', 'name', 'email', 'phone', 'account_type', 'company_url', 'registered_at',
        'email_verified', 'plan', 'billing_cycle', 'subscription_status', 'payment_status',
        'last_amount', 'currency', 'source_stage', 'services', 'service_count',
        'last_activity_at', 'error_count',
    ];
    private const HEADERS = [
        'رقم العميل' => 'external_id', 'الاسم' => 'name', 'البريد' => 'email',
        'الهاتف' => 'phone', 'نوع الحساب' => 'account_type', 'رابط الشركة' => 'company_url',
        'تاريخ التسجيل' => 'registered_at', 'توثيق البريد' => 'email_verified',
        'الباقة' => 'plan', 'دورة الفوترة' => 'billing_cycle', 'الاشتراك' => 'subscription_status',
        'حالة الدفع' => 'payment_status', 'آخر مبلغ' => 'last_amount', 'العملة' => 'currency',
        'المرحلة' => 'source_stage', 'الخدمات المرتبطة' => 'services',
        'عدد الخدمات' => 'service_count', 'آخر نشاط' => 'last_activity_at', 'عدد الأخطاء' => 'error_count',
        'id' => 'external_id', 'customer_id' => 'external_id', 'external_id' => 'external_id',
        'name' => 'name', 'full_name' => 'name', 'email' => 'email', 'email_address' => 'email',
        'phone' => 'phone', 'phone_number' => 'phone', 'account_type' => 'account_type',
        'company_url' => 'company_url', 'website' => 'company_url', 'registered_at' => 'registered_at',
        'created_at' => 'registered_at', 'email_verified' => 'email_verified', 'plan' => 'plan',
        'subscription_plan' => 'plan', 'billing_cycle' => 'billing_cycle',
        'subscription_status' => 'subscription_status', 'payment_status' => 'payment_status',
        'last_amount' => 'last_amount', 'currency' => 'currency', 'source_stage' => 'source_stage',
        'services' => 'services', 'linked_services' => 'services', 'service_count' => 'service_count',
        'last_activity_at' => 'last_activity_at', 'last_active_at' => 'last_activity_at',
        'error_count' => 'error_count',
    ];

    public static function import(PDO $db, array $uploadedFile, int $actorId): never
    {
        $csvRows = self::readUploadedCsv($uploadedFile);
        $importReport = self::importRows($db, $csvRows, $actorId);
        Http::json($importReport);
    }

    public static function preview(PDO $db, array $uploadedFile): never
    {
        $csvRows = self::readUploadedCsv($uploadedFile);
        Http::json(self::previewRows($db, $csvRows));
    }

    public static function importSourceRecords(PDO $db, array $records, int $actorId): array
    {
        if (count($records) > self::MAX_ROWS) {
            throw new RuntimeException('تجاوز عدد العملاء الحد المسموح للاستيراد.');
        }
        $rows = [];
        foreach ($records as $index => $record) {
            if (is_array($record)) $rows[] = self::normalizeSourceRecord($record, $index + 1);
        }
        return self::importRows($db, $rows, $actorId, true);
    }

    private static function normalizeSourceRecord(array $record, int $line): array
    {
        $row = array_intersect_key($record, array_flip(self::CUSTOMER_FIELDS));
        $row['_line'] = $line;
        $row['name'] = trim((string) ($row['name'] ?? ''));
        $row['email'] = strtolower(trim((string) ($row['email'] ?? '')));
        $row['phone'] = self::normalizePhone(isset($row['phone']) ? (string) $row['phone'] : null);
        return $row;
    }

    private static function readUploadedCsv(array $uploadedFile): array
    {
        self::validateUploadedFile($uploadedFile);
        $csvHandle = fopen($uploadedFile['tmp_name'], 'rb');
        if (!$csvHandle) {
            Http::json(['error' => 'invalid_upload', 'message' => 'تعذر قراءة الملف المرفوع.'], 422);
        }
        try {
            $firstLine = self::firstLine($csvHandle);
            $delimiter = self::detectDelimiter($firstLine);
            rewind($csvHandle);
            $headerMap = self::readHeaderMap($csvHandle, $delimiter);
            return self::readRows($csvHandle, $delimiter, $headerMap);
        } finally {
            fclose($csvHandle);
        }
    }

    private static function validateUploadedFile(array $uploadedFile): void
    {
        $temporaryPath = $uploadedFile['tmp_name'] ?? null;
        if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
            Http::json(['error' => 'invalid_upload', 'message' => 'تعذر قراءة الملف المرفوع.'], 422);
        }
        if (($uploadedFile['size'] ?? 0) > self::MAX_FILE_BYTES) {
            Http::json(['error' => 'file_too_large', 'message' => 'الحد الأقصى لحجم الملف 5 ميجابايت.'], 413);
        }
    }

    private static function firstLine($csvHandle): string
    {
        $line = fgets($csvHandle);
        if ($line === false) {
            Http::json(['error' => 'empty_file', 'message' => 'الملف فارغ.'], 422);
        }
        return $line;
    }

    private static function detectDelimiter(string $firstLine): string
    {
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        return (string) array_key_first($counts);
    }

    private static function readHeaderMap($csvHandle, string $delimiter): array
    {
        $headerCells = fgetcsv($csvHandle, null, $delimiter, '"', '');
        if (!is_array($headerCells)) {
            Http::json(['error' => 'invalid_csv', 'message' => 'تعذر قراءة عناوين الأعمدة.'], 422);
        }
        $headerCells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headerCells[0]);
        $headerMap = self::mapHeaders($headerCells);
        if (!in_array('email', $headerMap, true) || !in_array('name', $headerMap, true)) {
            Http::json(['error' => 'unsupported_csv', 'message' => 'يجب أن يحتوي الملف على عمودي الاسم والبريد.'], 422);
        }
        return $headerMap;
    }

    private static function mapHeaders(array $headerCells): array
    {
        $headerMap = [];
        foreach ($headerCells as $index => $label) {
            $normalizedLabel = strtolower(trim((string) $label));
            $field = self::HEADERS[$normalizedLabel] ?? null;
            if ($field !== null) {
                if (in_array($field, $headerMap, true)) {
                    Http::json(['error' => 'duplicate_columns', 'message' => 'الملف يحتوي على أكثر من عمود لنفس الحقل.'], 422);
                }
                $headerMap[$index] = $field;
            }
        }
        return $headerMap;
    }

    private static function readRows($csvHandle, string $delimiter, array $headerMap): array
    {
        $csvRows = [];
        $lineNumber = 1;
        while (($csvCells = fgetcsv($csvHandle, null, $delimiter, '"', '')) !== false) {
            $lineNumber++;
            if (self::isBlankRow($csvCells)) {
                continue;
            }
            $csvRows[] = self::mapRow($csvCells, $headerMap, $lineNumber);
            if (count($csvRows) > self::MAX_ROWS) {
                Http::json(['error' => 'too_many_rows', 'message' => 'الحد الأقصى 10,000 صف في الملف الواحد.'], 413);
            }
        }
        return $csvRows;
    }

    private static function isBlankRow(array $csvCells): bool
    {
        return $csvCells === [null] || (count($csvCells) === 1 && trim((string) $csvCells[0]) === '');
    }

    private static function mapRow(array $csvCells, array $headerMap, int $lineNumber): array
    {
        $csvRow = [];
        foreach ($headerMap as $index => $field) {
            $csvRow[$field] = trim((string) ($csvCells[$index] ?? ''));
        }
        $csvRow['_line'] = $lineNumber;
        $csvRow['email'] = strtolower($csvRow['email'] ?? '');
        $csvRow['phone'] = self::normalizePhone($csvRow['phone'] ?? null);
        return $csvRow;
    }

    private static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || !str_starts_with($phone, "'")) {
            return $phone;
        }
        return substr($phone, 1);
    }

    private static function importRows(PDO $db, array $csvRows, int $actorId, bool $skipUnchanged = false): array
    {
        $emailCounts = self::emailCounts($csvRows);
        $importReport = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        if ($skipUnchanged) $importReport['unchanged'] = 0;
        $db->beginTransaction();
        try {
            foreach ($csvRows as $csvRow) {
                $rowOutcome = self::importRow($db, $csvRow, $emailCounts, $actorId, $skipUnchanged);
                $importReport = self::recordOutcome($importReport, $rowOutcome);
            }
            $db->commit();
            return $importReport;
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    private static function previewRows(PDO $db, array $csvRows): array
    {
        $emailCounts = self::emailCounts($csvRows);
        $report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'total' => count($csvRows), 'sample' => []];
        foreach ($csvRows as $csvRow) {
            $outcome = self::classifyRow($db, $csvRow, $emailCounts);
            $report = self::recordOutcome($report, $outcome);
            if (count($report['sample']) < 8) {
                $report['sample'][] = self::previewRow($csvRow, $outcome);
            }
        }
        return $report;
    }

    private static function previewRow(array $csvRow, array $outcome): array
    {
        return [
            'line' => $outcome['line'],
            'name' => $csvRow['name'] ?? '',
            'email' => $outcome['email'],
            'status' => $outcome['status'],
            'reason' => $outcome['reason'] ?? null,
        ];
    }

    private static function emailCounts(array $csvRows): array
    {
        $counts = [];
        foreach ($csvRows as $csvRow) {
            $email = $csvRow['email'] ?? '';
            if ($email !== '') {
                $counts[$email] = ($counts[$email] ?? 0) + 1;
            }
        }
        return $counts;
    }

    private static function importRow(PDO $db, array $csvRow, array $emailCounts, int $actorId, bool $skipUnchanged): array
    {
        $outcome = self::classifyRow($db, $csvRow, $emailCounts);
        if ($outcome['status'] === 'skipped') {
            return $outcome;
        }
        $customerFields = self::customerFields($csvRow);
        if ($skipUnchanged && $outcome['status'] === 'updated' && !self::hasImportedChanges($outcome['existing_customer'], $customerFields)) {
            return array_merge($outcome, ['status' => 'unchanged']);
        }
        $customerId = self::persistImportedCustomer($db, $customerFields, $outcome['customer_id']);
        ActivityLog::record($db, $customerId, $actorId, $outcome['status'] === 'created' ? 'created' : 'imported', $outcome['status'] === 'created' ? 'تمت إضافة العميل عبر الاستيراد.' : 'تم تحديث بيانات العميل عبر الاستيراد.');
        return $outcome;
    }

    private static function classifyRow(PDO $db, array $csvRow, array $emailCounts): array
    {
        $email = $csvRow['email'] ?? '';
        $line = (int) $csvRow['_line'];
        $rejection = self::rowRejection($csvRow, $emailCounts);
        if ($rejection !== null) {
            return ['status' => 'skipped', 'reason' => $rejection, 'email' => $email, 'line' => $line];
        }
        $existing = self::existingCustomer($db, $email);
        if ($existing === null) return ['status' => 'created', 'email' => $email, 'line' => $line, 'customer_id' => null];
        return ['status' => 'updated', 'email' => $email, 'line' => $line, 'customer_id' => (int) $existing['id'], 'existing_customer' => $existing];
    }

    private static function rowRejection(array $csvRow, array $emailCounts): ?string
    {
        $email = $csvRow['email'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'البريد مفقود أو غير صالح.';
        }
        if (($emailCounts[$email] ?? 0) > 1) {
            return 'البريد مكرر داخل الملف؛ لم يُستورد أي صف بهذا البريد.';
        }
        return trim((string) ($csvRow['name'] ?? '')) === '' ? 'اسم العميل مفقود.' : null;
    }

    private static function existingCustomer(PDO $db, string $email): ?array
    {
        $columns = implode(', ', array_merge(['id'], self::CUSTOMER_FIELDS));
        $customerQuery = $db->prepare('SELECT ' . $columns . ' FROM customers WHERE email = ?');
        $customerQuery->execute([$email]);
        return $customerQuery->fetch() ?: null;
    }

    private static function hasImportedChanges(array $existing, array $incoming): bool
    {
        foreach (self::CUSTOMER_FIELDS as $field) {
            $current = $existing[$field] === null ? null : self::normalizeImportedField((string) $existing[$field], $field);
            if ($current !== ($incoming[$field] ?? null)) return true;
        }
        return false;
    }

    private static function customerFields(array $csvRow): array
    {
        $customerFields = [];
        foreach (self::CUSTOMER_FIELDS as $field) {
            $customerFields[$field] = self::normalizeImportedField($csvRow[$field] ?? null, $field);
        }
        return $customerFields;
    }

    private static function normalizeImportedField(?string $fieldValue, string $field): mixed
    {
        if ($fieldValue === '') {
            $fieldValue = null;
        }
        if ($field === 'error_count') {
            return max(0, (int) ($fieldValue ?? 0));
        }
        if ($field === 'service_count' && $fieldValue !== null) {
            return max(0, (int) $fieldValue);
        }
        return $fieldValue;
    }

    private static function persistImportedCustomer(PDO $db, array $customerFields, ?int $existingCustomerId): int
    {
        if ($existingCustomerId === null) {
            return self::insertImportedCustomer($db, $customerFields);
        }
        self::updateImportedCustomer($db, $customerFields, $existingCustomerId);
        return $existingCustomerId;
    }

    private static function insertImportedCustomer(PDO $db, array $customerFields): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $columns = array_merge(self::CUSTOMER_FIELDS, ['crm_stage', 'created_at', 'updated_at']);
        $customerFields['crm_stage'] = 'new';
        $customerFields['created_at'] = $now;
        $customerFields['updated_at'] = $now;
        $query = 'INSERT INTO customers (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $db->prepare($query)->execute(array_map(fn($field) => $customerFields[$field] ?? null, $columns));
        return (int) $db->lastInsertId();
    }

    private static function updateImportedCustomer(PDO $db, array $customerFields, int $customerId): void
    {
        $setClauses = array_map(fn($field) => $field . ' = ?', self::CUSTOMER_FIELDS);
        $customerValues = array_merge(array_values($customerFields), [gmdate('Y-m-d H:i:s'), $customerId]);
        $query = 'UPDATE customers SET ' . implode(', ', $setClauses) . ', updated_at = ? WHERE id = ?';
        $db->prepare($query)->execute($customerValues);
    }

    private static function recordOutcome(array $importReport, array $rowOutcome): array
    {
        if ($rowOutcome['status'] === 'unchanged') {
            $importReport['unchanged']++;
            return $importReport;
        }
        if ($rowOutcome['status'] !== 'skipped') {
            $importReport[$rowOutcome['status']]++;
            return $importReport;
        }
        $importReport['skipped']++;
        $importReport['errors'][] = [
            'row' => $rowOutcome['line'],
            'email' => $rowOutcome['email'],
            'reason' => $rowOutcome['reason'],
        ];
        return $importReport;
    }
}
