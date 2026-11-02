<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jerarquía empresa → sucursal → almacén garantizada por la base de datos. Las claves compuestas
 * impiden que una sucursal, almacén, stock, usuario, caja, serie o lote quede enlazado a un padre
 * de otra empresa (o, en el stock, a una sucursal distinta de la de su almacén), aunque un error
 * de código lo intentara.
 */
return new class extends Migration
{
    private const CHECKS = [
        'almacenes con sucursal de otra empresa' => 'SELECT COUNT(*) n FROM warehouses w JOIN branches b ON b.id=w.branch_id WHERE w.company_id<>b.company_id',
        'stock con empresa o sucursal distinta de su almacén' => 'SELECT COUNT(*) n FROM stocks s JOIN warehouses w ON w.id=s.warehouse_id WHERE s.company_id<>w.company_id OR s.branch_id<>w.branch_id',
        'usuarios con sucursal de otra empresa' => 'SELECT COUNT(*) n FROM users u JOIN branches b ON b.id=u.branch_id WHERE u.company_id<>b.company_id',
        'cajas con sucursal de otra empresa' => 'SELECT COUNT(*) n FROM cash_registers c JOIN branches b ON b.id=c.branch_id WHERE c.company_id<>b.company_id',
        'series con sucursal de otra empresa' => 'SELECT COUNT(*) n FROM document_series d JOIN branches b ON b.id=d.branch_id WHERE d.company_id<>b.company_id',
        'lotes con almacén de otra empresa' => 'SELECT COUNT(*) n FROM lots l JOIN warehouses w ON w.id=l.warehouse_id WHERE l.company_id<>w.company_id',
    ];

    public function up(): void
    {
        // No se corrigen datos a ciegas: si hay registros mezclados, se detiene y los informa.
        $problems = collect(self::CHECKS)->map(fn ($sql) => (int) DB::selectOne($sql)->n)->filter();
        if ($problems->isNotEmpty()) {
            throw new RuntimeException('Datos con empresas mezcladas, corrígelos antes de migrar: '.$problems->map(fn ($n, $k) => "$n $k")->implode('; '));
        }

        Schema::table('branches', fn (Blueprint $t) => $t->unique(['id', 'company_id'], 'branches_id_company_unique'));
        Schema::table('warehouses', function (Blueprint $t) {
            $t->unique(['id', 'company_id'], 'warehouses_id_company_unique');
            $t->unique(['id', 'branch_id'], 'warehouses_id_branch_unique');
            $t->foreign(['branch_id', 'company_id'], 'warehouses_branch_company_fk')->references(['id', 'company_id'])->on('branches');
        });
        Schema::table('stocks', function (Blueprint $t) {
            $t->foreign(['warehouse_id', 'company_id'], 'stocks_warehouse_company_fk')->references(['id', 'company_id'])->on('warehouses');
            // Si un almacén vacío cambia de sucursal, sus filas de stock lo acompañan.
            $t->foreign(['warehouse_id', 'branch_id'], 'stocks_warehouse_branch_fk')->references(['id', 'branch_id'])->on('warehouses')->cascadeOnUpdate();
        });
        Schema::table('users', fn (Blueprint $t) => $t->foreign(['branch_id', 'company_id'], 'users_branch_company_fk')->references(['id', 'company_id'])->on('branches'));
        Schema::table('cash_registers', fn (Blueprint $t) => $t->foreign(['branch_id', 'company_id'], 'cash_registers_branch_company_fk')->references(['id', 'company_id'])->on('branches'));
        Schema::table('document_series', fn (Blueprint $t) => $t->foreign(['branch_id', 'company_id'], 'document_series_branch_company_fk')->references(['id', 'company_id'])->on('branches'));
        Schema::table('lots', fn (Blueprint $t) => $t->foreign(['warehouse_id', 'company_id'], 'lots_warehouse_company_fk')->references(['id', 'company_id'])->on('warehouses'));
    }

    public function down(): void
    {
        Schema::table('lots', fn (Blueprint $t) => $t->dropForeign('lots_warehouse_company_fk'));
        Schema::table('document_series', fn (Blueprint $t) => $t->dropForeign('document_series_branch_company_fk'));
        Schema::table('cash_registers', fn (Blueprint $t) => $t->dropForeign('cash_registers_branch_company_fk'));
        Schema::table('users', fn (Blueprint $t) => $t->dropForeign('users_branch_company_fk'));
        Schema::table('stocks', function (Blueprint $t) {
            $t->dropForeign('stocks_warehouse_company_fk');
            $t->dropForeign('stocks_warehouse_branch_fk');
        });
        Schema::table('warehouses', function (Blueprint $t) {
            $t->dropForeign('warehouses_branch_company_fk');
            $t->dropUnique('warehouses_id_company_unique');
            $t->dropUnique('warehouses_id_branch_unique');
        });
        Schema::table('branches', fn (Blueprint $t) => $t->dropUnique('branches_id_company_unique'));
    }
};
