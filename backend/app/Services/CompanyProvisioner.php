<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Deja una empresa o sucursal lista para operar. Sin almacén, caja y series, cambiar a esa
 * sucursal rompía productos, POS y ventas.
 */
final class CompanyProvisioner
{
    /** Prefijo y dígitos de cada serie: T001, B001, F001, NC01, ND01, C001. */
    private const SERIES = ['ticket' => ['T', 3], 'receipt' => ['B', 3], 'invoice' => ['F', 3], 'credit_note' => ['NC', 2], 'debit_note' => ['ND', 2], 'purchase' => ['C', 3]];

    /** Empresa nueva: sucursal principal con su estructura, medios de pago, IGV y unidades. */
    public function provisionCompany(int $company): int
    {
        $branch = DB::table('branches')->insertGetId(['company_id' => $company, 'code' => 'PRINCIPAL', 'name' => 'Sede principal', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->provisionBranch($company, $branch, 'PRINCIPAL', 'Sede principal');
        foreach ([['Efectivo', 'cash', 0, 1], ['Yape', 'yape', 1, 0], ['Tarjeta', 'card', 1, 0], ['Transferencia', 'transfer', 1, 0]] as [$name, $code, $reference, $cash]) {
            DB::table('payment_methods')->insertOrIgnore(['company_id' => $company, 'name' => $name, 'code' => $code, 'requires_reference' => $reference, 'affects_cash' => $cash, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('tax_rates')->insert(['company_id' => $company, 'name' => 'IGV', 'code' => 'IGV18', 'rate' => 18, 'included' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('categories')->insert(['company_id' => $company, 'name' => 'General', 'slug' => 'general', 'status' => 'active', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        (new UnitCatalog())->ensureFor($company);
        return $branch;
    }

    /** Rol Administrador con todos los permisos, salvo los sensibles que se conceden a mano. */
    public function adminRole(int $company): int
    {
        $role = DB::table('roles')->insertGetId(['company_id' => $company, 'name' => 'Administrador', 'slug' => 'admin', 'description' => 'Control de la empresa', 'system' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $permissions = DB::table('permissions')->where('slug', '!=', 'sales.override_price')->pluck('id');
        DB::table('permission_role')->insert($permissions->map(fn ($p) => ['role_id' => $role, 'permission_id' => $p])->all());
        return $role;
    }

    /** Primer usuario de la empresa: administrador de la sede principal. */
    public function firstAdmin(int $company, int $branch, string $name, string $email, string $password): int
    {
        $role = $this->adminRole($company);
        $user = \App\Models\User::create(['name' => $name, 'email' => $email, 'password' => $password, 'company_id' => $company, 'branch_id' => $branch, 'role' => 'admin', 'status' => 'active', 'email_verified_at' => now()]);
        DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $user->id]);
        return $user->id;
    }

    /** Sucursal nueva: almacén de venta, caja y series propias. */
    public function provisionBranch(int $company, int $branch, string $code, string $name): void
    {
        $warehouse = DB::table('warehouses')->insertGetId(['company_id' => $company, 'branch_id' => $branch, 'code' => mb_substr($code, 0, 16).'-ALM', 'name' => "Almacén {$name}", 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->where('id', $branch)->update(['default_warehouse_id' => $warehouse]);
        DB::table('cash_registers')->insert(['company_id' => $company, 'branch_id' => $branch, 'code' => 'CAJA-01', 'name' => 'Caja principal', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach (self::SERIES as $type => [$prefix, $digits]) {
            DB::table('document_series')->insert(['company_id' => $company, 'branch_id' => $branch, 'document_type' => $type, 'series' => $this->nextSeries($company, $type, $prefix, $digits), 'current_number' => 0, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Siguiente serie libre en la empresa: un RUC no puede emitir la misma serie en dos locales. */
    private function nextSeries(int $company, string $type, string $prefix, int $digits): string
    {
        $used = DB::table('document_series')->where(['company_id' => $company, 'document_type' => $type])->pluck('series')
            ->map(fn ($s) => preg_match('/^'.$prefix.'(\d+)$/', $s, $m) ? (int) $m[1] : 0)->max() ?? 0;
        return $prefix.str_pad((string) ($used + 1), $digits, '0', STR_PAD_LEFT);
    }
}
