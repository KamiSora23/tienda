<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Almacenes exclusivos o compartidos. Todo almacén pertenece a una empresa; puede ser de una sola
 * sucursal (branch_id) o compartido (branch_id nulo) entre las sucursales listadas en
 * branch_warehouse. Cada sucursal elige su almacén de venta (branches.default_warehouse_id), que
 * puede ser exclusivo o compartido.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. La sucursal del almacén pasa a ser opcional (nula = compartido). Se sueltan las claves
        //    que la usan, se cambia la columna y se vuelven a crear.
        // En SQLite cambiar la columna reconstruye la tabla y conserva sus claves; MySQL exige soltarlas.
        $mysql = DB::getDriverName() !== 'sqlite';
        if ($mysql) {
            Schema::table('stocks', fn (Blueprint $t) => $t->dropForeign('stocks_warehouse_branch_fk'));
            Schema::table('warehouses', function (Blueprint $t) {
                $t->dropForeign('warehouses_branch_company_fk');
                $t->dropForeign(['branch_id']);
            });
        }
        Schema::table('warehouses', fn (Blueprint $t) => $t->dropUnique(['branch_id', 'code']));
        Schema::table('warehouses', function (Blueprint $t) use ($mysql) {
            $t->unsignedBigInteger('branch_id')->nullable()->change();
            // El código identifica al almacén dentro de la empresa (los compartidos no tienen sucursal).
            $t->unique(['company_id', 'code']);
            if ($mysql) $t->foreign(['branch_id', 'company_id'], 'warehouses_branch_company_fk')->references(['id', 'company_id'])->on('branches');
        });
        Schema::table('stocks', function (Blueprint $t) use ($mysql) {
            $t->unsignedBigInteger('branch_id')->nullable()->change();
            // El stock de un almacén compartido no pertenece a ninguna sucursal concreta (nulo).
            if ($mysql) $t->foreign(['warehouse_id', 'branch_id'], 'stocks_warehouse_branch_fk')->references(['id', 'branch_id'])->on('warehouses')->cascadeOnUpdate();
        });

        // 2. Sucursales que pueden usar cada almacén compartido (siempre de la misma empresa).
        Schema::create('branch_warehouse', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->timestamps();
            $t->unique(['branch_id', 'warehouse_id']);
            $t->foreign(['branch_id', 'company_id'], 'branch_warehouse_branch_fk')->references(['id', 'company_id'])->on('branches')->cascadeOnDelete();
            $t->foreign(['warehouse_id', 'company_id'], 'branch_warehouse_warehouse_fk')->references(['id', 'company_id'])->on('warehouses')->cascadeOnDelete();
        });

        // 3. Almacén de venta elegido por cada sucursal (antes, warehouses.is_default).
        Schema::table('branches', function (Blueprint $t) {
            $t->unsignedBigInteger('default_warehouse_id')->nullable()->after('status');
            $t->foreign(['default_warehouse_id', 'company_id'], 'branches_default_warehouse_fk')->references(['id', 'company_id'])->on('warehouses');
        });
        foreach (DB::table('warehouses')->where('is_default', true)->get() as $w) {
            DB::table('branches')->where('id', $w->branch_id)->update(['default_warehouse_id' => $w->id]);
        }
        Schema::table('warehouses', fn (Blueprint $t) => $t->dropColumn('is_default'));
    }

    public function down(): void
    {
        Schema::table('warehouses', fn (Blueprint $t) => $t->boolean('is_default')->default(false));
        foreach (DB::table('branches')->whereNotNull('default_warehouse_id')->get() as $b) {
            DB::table('warehouses')->where('id', $b->default_warehouse_id)->update(['is_default' => true]);
        }
        Schema::table('branches', function (Blueprint $t) {
            $t->dropForeign('branches_default_warehouse_fk');
            $t->dropColumn('default_warehouse_id');
        });
        Schema::dropIfExists('branch_warehouse');
    }
};
