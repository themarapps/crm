<?php
declare(strict_types=1);

final class LeadIntakeService
{
    public static function receive(PDO $db): never
    {
        self::authorizeRequest();
        $lead = self::leadFields(self::requestFields());
        try {
            [$customerId, $created] = self::saveLead($db, $lead);
        } catch (PDOException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                Http::json(['error' => 'duplicate_email'], 409);
            }
            throw $exception;
        }
        Http::json(['id' => $customerId, 'created' => $created], $created ? 201 : 200);
    }

    private static function authorizeRequest(): void
    {
        $expectedToken = getenv('CRM_LEAD_WEBHOOK_TOKEN');
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $providedToken = is_string($authorization) && preg_match('/^Bearer ([A-Za-z0-9._~-]{32,512})$/', $authorization, $matches)
            ? $matches[1]
            : '';
        if (!is_string($expectedToken) || strlen($expectedToken) < 32) Http::json(['error' => 'lead_intake_unavailable'], 503);
        if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) Http::json(['error' => 'unauthorized'], 401);
    }

    private static function requestFields(): array
    {
        $rawRequest = file_get_contents('php://input');
        if (!is_string($rawRequest) || strlen($rawRequest) > 16384) Http::json(['error' => 'payload_too_large'], 413);
        $fields = json_decode($rawRequest, true);
        if (!is_array($fields)) Http::json(['error' => 'invalid_json'], 400);
        return $fields;
    }

    private static function leadFields(array $fields): array
    {
        $email = strtolower(self::requiredText($fields, 'email', 255));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Http::json(['error' => 'validation_error', 'field' => 'email'], 422);
        return [
            'name' => self::requiredText($fields, 'name', 200),
            'email' => $email,
            'phone' => self::optionalText($fields, 'phone', 255),
            'has_phone' => array_key_exists('phone', $fields),
            'lead_source' => self::optionalText($fields, 'lead_source', 120),
            'campaign' => self::optionalText($fields, 'campaign', 120),
            'received_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    private static function saveLead(PDO $db, array $lead): array
    {
        $db->beginTransaction();
        try {
            [$customerId, $created] = self::upsertCustomer($db, $lead);
            self::persistAttribution($db, $customerId, $lead, $created);
            ActivityLog::record($db, $customerId, null, $created ? 'lead_received' : 'lead_refreshed', $created ? 'وصل عميل محتمل عبر نموذج خارجي.' : 'تم تحديث بيانات تواصل من مصدر خارجي.');
            $db->commit();
            return [$customerId, $created];
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    private static function upsertCustomer(PDO $db, array $lead): array
    {
        $find = $db->prepare('SELECT id, phone FROM customers WHERE LOWER(email) = ? LIMIT 1');
        $find->execute([$lead['email']]);
        $existing = $find->fetch();
        $created = !$existing;
        if ($created) {
            $db->prepare("INSERT INTO customers (name, email, phone, crm_stage, last_activity_at, created_at, updated_at) VALUES (?, ?, ?, 'new', ?, ?, ?)")
                ->execute([$lead['name'], $lead['email'], $lead['phone'], $lead['received_at'], $lead['received_at'], $lead['received_at']]);
            return [(int) $db->lastInsertId(), true];
        }

        $phone = $lead['has_phone'] ? $lead['phone'] : $existing['phone'];
        $db->prepare('UPDATE customers SET name = ?, phone = ?, last_activity_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$lead['name'], $phone, $lead['received_at'], $lead['received_at'], (int) $existing['id']]);
        return [(int) $existing['id'], false];
    }

    private static function persistAttribution(PDO $db, int $customerId, array $lead, bool $created): void
    {
        if (!$created && $lead['lead_source'] === null && $lead['campaign'] === null) return;
        $query = $db->prepare('SELECT lead_source, campaign FROM customer_attribution WHERE customer_id = ?');
        $query->execute([$customerId]);
        $existing = $query->fetch() ?: ['lead_source' => null, 'campaign' => null];
        $leadSource = $lead['lead_source'] ?? ($created ? 'website_form' : $existing['lead_source']);
        $campaign = $lead['campaign'] ?? $existing['campaign'];
        $db->prepare('DELETE FROM customer_attribution WHERE customer_id = ?')->execute([$customerId]);
        if ($leadSource !== null || $campaign !== null) {
            $db->prepare('INSERT INTO customer_attribution (customer_id, lead_source, campaign) VALUES (?, ?, ?)')
                ->execute([$customerId, $leadSource, $campaign]);
        }
    }

    private static function requiredText(array $fields, string $key, int $max): string
    {
        $value = $fields[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max) {
            Http::json(['error' => 'validation_error', 'field' => $key], 422);
        }
        return trim($value);
    }

    private static function optionalText(array $fields, string $key, int $max): ?string
    {
        if (!array_key_exists($key, $fields) || $fields[$key] === null || $fields[$key] === '') return null;
        if (!is_string($fields[$key]) || mb_strlen(trim($fields[$key])) > $max) {
            Http::json(['error' => 'validation_error', 'field' => $key], 422);
        }
        $value = trim($fields[$key]);
        return $value === '' ? null : $value;
    }
}
