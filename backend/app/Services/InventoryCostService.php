<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Costo promedio ponderado del producto. Es uno solo por producto, así que se calcula sobre el
 * stock de todos los almacenes de la empresa. Las entradas (compras) lo recalculan; las salidas
 * al proveedor (anulación o devolución de compra) retiran el valor de lo que sale. Las ventas
 * no lo modifican: salen al costo promedio vigente.
 */
final class InventoryCostService
{
    public function companyStock(int $company, int $product): float
    {
        return (float) DB::table('stocks')->where(['company_id' => $company, 'product_id' => $product])->sum('quantity');
    }

    /**
     * Costo unitario efectivo de cada línea: flete y otros costos se reparten según el valor de
     * la línea (no por cantidad, que mezclaría unidades, kilos y metros). Sin valor, por cantidad.
     */
    public function effectiveCosts(array $items, float $extra): array
    {
        $subtotal = collect($items)->sum(fn ($i) => $i['quantity'] * $i['unit_cost']);
        $quantity = collect($items)->sum('quantity');
        return array_map(function ($i) use ($extra, $subtotal, $quantity) {
            if ($extra <= 0) return (float) $i['unit_cost'];
            return $subtotal > 0
                ? $i['unit_cost'] * (1 + $extra / $subtotal)
                : $i['unit_cost'] + ($quantity ? $extra / $quantity : 0);
        }, $items);
    }

    /** Entrada de mercadería. Llamar ANTES de sumar la cantidad al stock. */
    public function receive(int $company, int $product, float $quantity, float $unitCost, int $user, string $source): void
    {
        $p = DB::table('products')->where(['id' => $product, 'company_id' => $company])->lockForUpdate()->first();
        $before = max(0, $this->companyStock($company, $product));
        $total = $before + $quantity;
        $average = $total > 0 ? (($before * (float) $p->average_cost) + ($quantity * $unitCost)) / $total : $unitCost;
        $this->save($company, $p, $average, $user, $source);
    }

    /** Salida al proveedor (anulación o devolución). Llamar ANTES de restar la cantidad del stock. */
    public function release(int $company, int $product, float $quantity, float $unitCost, int $user, string $source): void
    {
        $p = DB::table('products')->where(['id' => $product, 'company_id' => $company])->lockForUpdate()->first();
        $before = $this->companyStock($company, $product);
        $remaining = $before - $quantity;
        // Sin stock restante no hay valor que repartir: se conserva el último costo conocido.
        if ($remaining <= 0.0005) return;
        $average = (($before * (float) $p->average_cost) - ($quantity * $unitCost)) / $remaining;
        $this->save($company, $p, max(0, $average), $user, $source);
    }

    private function save(int $company, object $product, float $average, int $user, string $source): void
    {
        if (abs((float) $product->average_cost - $average) <= 0.0001) return;
        DB::table('products')->where('id', $product->id)->update(['average_cost' => $average, 'updated_at' => now()]);
        DB::table('cost_history')->insert([
            'company_id' => $company, 'product_id' => $product->id, 'user_id' => $user,
            'previous_cost' => $product->average_cost, 'new_cost' => $average, 'source' => $source,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
