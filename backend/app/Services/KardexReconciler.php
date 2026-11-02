<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Mantiene el kardex cuadrado con el stock: la suma de movimientos debe dar el stock actual. */
final class KardexReconciler
{
    /** Registra como saldo inicial la parte del stock que no tiene movimientos. Devuelve cuántos agregó. */
    public function backfillInitialStock(): int
    {
        $added = 0;
        foreach (DB::table('stocks')->get() as $stock) {
            $moved = (float) DB::table('inventory_movements')->where(['warehouse_id' => $stock->warehouse_id, 'product_id' => $stock->product_id])->sum('quantity');
            $missing = round((float) $stock->quantity - $moved, 3);
            if (abs($missing) < 0.0005) continue;

            $product = DB::table('products')->where('id', $stock->product_id)->first();
            $user = DB::table('users')->where('company_id', $stock->company_id)->orderBy('id')->value('id');
            $branch = $stock->branch_id ?? DB::table('branch_warehouse')->where('warehouse_id', $stock->warehouse_id)->orderBy('branch_id')->value('branch_id');
            if (!$branch) continue;
            if (!$product || !$user) continue;
            // Fechado al crear el producto, para que quede antes que sus demás movimientos.
            $date = $product->created_at ?? now();
            DB::table('inventory_movements')->insert([
                'uuid' => Str::uuid(), 'company_id' => $stock->company_id, 'branch_id' => $branch, 'warehouse_id' => $stock->warehouse_id,
                'product_id' => $stock->product_id, 'user_id' => $user, 'type' => 'initial_stock', 'quantity' => $missing,
                'historical_unit_cost' => $product->average_cost, 'previous_stock' => 0, 'new_stock' => $missing,
                'document_type' => 'product', 'document_id' => $product->id, 'document_number' => $product->sku,
                'created_at' => $date, 'updated_at' => $date,
            ]);
            $added++;
        }
        return $added;
    }
}
