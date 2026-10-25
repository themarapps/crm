<?php
declare(strict_types=1);

final class CustomerAccess
{
    public static function canViewAll(array $user): bool
    {
        if (($user['role'] ?? '') === 'admin') return true;
        return (bool) ($user['can_view_all_customers'] ?? in_array($user['role'] ?? '', ['admin', 'manager'], true));
    }

    public static function canEdit(array $user): bool
    {
        if (($user['role'] ?? '') === 'admin') return true;
        return (bool) ($user['can_edit_customers'] ?? true);
    }

    public static function canAccess(array $customer, array $user): bool
    {
        return self::canViewAll($user) || (int) ($customer['assigned_to'] ?? 0) === (int) ($user['id'] ?? 0);
    }

    public static function requireEdit(array $user): void
    {
        if (!self::canEdit($user)) {
            Http::json(['error' => 'forbidden', 'message' => 'لا تملك صلاحية تعديل العملاء.'], 403);
        }
    }

    public static function requireImport(array $user): void
    {
        if (!self::canViewAll($user) || !self::canEdit($user)) {
            Http::json(['error' => 'forbidden', 'message' => 'لا تملك صلاحية استيراد العملاء.'], 403);
        }
    }
}
