<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Una sucursal puede tener varios almacenes; el "almacén de venta" es del que salen POS,
    // ventas y compras. Antes se tomaba el primero que devolviera la base de datos.
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $t) {
            $t->boolean('is_default')->default(false)->after('name');
        });
        foreach (DB::table('warehouses')->where('status', 'active')->orderBy('id')->get()->groupBy('branch_id') as $rows) {
            DB::table('warehouses')->where('id', $rows->first()->id)->update(['is_default' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('warehouses', fn (Blueprint $t) => $t->dropColumn('is_default'));
    }
};
