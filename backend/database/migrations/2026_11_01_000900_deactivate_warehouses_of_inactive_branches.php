<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Una sucursal inactiva no tiene almacenes operativos. Las sucursales desactivadas antes de
    // esta regla conservaban sus almacenes activos; solo se alinean los que no tienen stock.
    public function up(): void
    {
        $inactive = DB::table('branches')->where('status', 'inactive')->pluck('id');
        $withStock = DB::table('stocks')->where('quantity', '>', 0)->distinct()->pluck('warehouse_id');
        DB::table('warehouses')->whereIn('branch_id', $inactive)->whereNotIn('id', $withStock)->where('status', 'active')
            ->update(['status' => 'inactive', 'updated_at' => now()]);
    }

    public function down(): void
    {
    }
};
