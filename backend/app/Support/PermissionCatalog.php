<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Permisos del sistema (módulo.acción). Se crean al instalar y en la semilla de demostración. */
final class PermissionCatalog
{
    public const MODULES = ['companies', 'branches', 'users', 'roles', 'customers', 'suppliers', 'categories', 'brands', 'units', 'products', 'warehouses', 'inventory', 'purchases', 'sales', 'cash', 'promotions', 'expenses', 'reports', 'audit', 'settings', 'ai', 'portal', 'orders', 'notifications', 'files', 'backups', 'integrations', 'maintenance'];
    public const ACTIONS = ['view', 'create', 'update', 'delete', 'export'];
    /** Permisos sensibles que se conceden a mano. */
    public const SPECIAL = [['sales', 'discount'], ['sales', 'override_price']];

    public static function ensure(): void
    {
        $now = now();
        $rows = [];
        foreach (self::MODULES as $module) foreach (self::ACTIONS as $action) $rows[] = [$module, $action];
        foreach ([...$rows, ...self::SPECIAL] as [$module, $action]) {
            DB::table('permissions')->insertOrIgnore(['module' => $module, 'action' => $action, 'slug' => "{$module}.{$action}", 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
