<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class Controller
{
    /** Empresa, sucursal y usuario de la petición autenticada. */
    protected function ctx(Request $r): array
    {
        return [$r->user()->company_id, $r->user()->branch_id, $r->user()->id];
    }

    /** Registra un evento en audit_events para la empresa del usuario autenticado. */
    protected function recordAudit(Request $r, string $event, string $entity, ?int $id, $after = null): void
    {
        DB::table('audit_events')->insert([
            'uuid' => Str::uuid(),
            'company_id' => $r->user()->company_id,
            'user_id' => $r->user()->id,
            'by_platform' => \App\Support\PlatformAccess::allows($r->user()),
            'event' => $event,
            'entity' => $entity,
            'entity_id' => $id,
            'after' => $after ? json_encode($after) : null,
            'ip' => $r->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** ¿El usuario tiene este permiso (p. ej. sales.discount)? */
    protected function userCan(Request $r, string $slug): bool
    {
        // El superadministrador tiene todos los permisos en cualquier empresa.
        if (\App\Support\PlatformAccess::allows($r->user())) return true;
        return DB::table('role_user as ru')->join('permission_role as pr', 'pr.role_id', '=', 'ru.role_id')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('ru.user_id', $r->user()->id)->where('p.slug', $slug)->exists();
    }

    /** Almacenes que puede usar una sucursal: los exclusivos suyos y los compartidos con ella. */
    protected function branchWarehousesQuery(int $company, int $branch)
    {
        return DB::table('warehouses as w')->where('w.company_id', $company)->where(fn ($q) => $q
            ->where('w.branch_id', $branch)
            ->orWhere(fn ($s) => $s->whereNull('w.branch_id')->whereExists(fn ($e) => $e->select(DB::raw(1))->from('branch_warehouse as bw')
                ->whereColumn('bw.warehouse_id', 'w.id')->where('bw.branch_id', $branch))));
    }

    /**
     * Almacén de venta de la sucursal: el que eligió (exclusivo o compartido) si sigue activo y a su
     * alcance; si no, su primer almacén activo disponible. 404 si no tiene ninguno.
     */
    protected function branchWarehouse(int $company, int $branch): object
    {
        $default = DB::table('branches')->where(['id' => $branch, 'company_id' => $company])->value('default_warehouse_id');
        $query = fn () => $this->branchWarehousesQuery($company, $branch)->where('w.status', 'active')->select('w.*');
        $warehouse = ($default ? $query()->where('w.id', $default)->first() : null)
            ?? $query()->orderByRaw('CASE WHEN w.branch_id IS NULL THEN 1 ELSE 0 END')->orderBy('w.id')->first();
        abort_unless($warehouse, 404, 'La sucursal no tiene un almacén activo para operar.');
        return $warehouse;
    }
}
