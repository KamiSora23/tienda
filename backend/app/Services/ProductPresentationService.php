<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductPresentationService
{
    /** Reglas de decimales por unidad: ver UnitCatalog. */
    public static function allowsDecimals(string $unitType): bool
    {
        return UnitCatalog::allowsDecimals($unitType);
    }

    /** Stock o movimientos registrados: cambiar la unidad base reinterpretaría esas cantidades. */
    public function hasHistory(int $product): bool
    {
        return DB::table('stocks')->where('product_id', $product)->where('quantity', '!=', 0)->exists()
            || DB::table('inventory_movements')->where('product_id', $product)->exists()
            || DB::table('sale_details')->where('product_id', $product)->exists()
            || DB::table('purchase_details')->where('product_id', $product)->exists();
    }

    /** Presentaciones de varios productos agrupadas por product_id. */
    public function forCompany(int $company): Collection
    {
        return DB::table('product_presentations')->where('company_id', $company)
            ->orderBy('sort_order')->orderBy('factor')->get()->groupBy('product_id');
    }

    /**
     * Reemplaza las presentaciones del producto por las recibidas: actualiza las que
     * traen id, crea las nuevas y elimina las que ya no figuran. Las ventas guardan una
     * copia de nombre y equivalencia, así que eliminar no altera comprobantes emitidos.
     */
    public function sync(int $company, int $product, string $unitType, array $rows): void
    {
        $this->assertBarcodesFree($company, $product, $rows);
        $names = [];
        foreach ($rows as $index => $row) {
            $name = mb_strtolower(trim($row['name']));
            if (isset($names[$name])) {
                throw ValidationException::withMessages(["presentations.$index.name" => "La presentación «{$row['name']}» está repetida."]);
            }
            $names[$name] = true;
            if (!self::allowsDecimals($unitType) && fmod((float) $row['factor'], 1.0) !== 0.0) {
                throw ValidationException::withMessages(["presentations.$index.factor" => "«{$row['name']}» debe equivaler a un número entero de unidades."]);
            }
        }

        $keep = collect($rows)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        DB::table('product_presentations')->where(['company_id' => $company, 'product_id' => $product])
            ->when($keep, fn ($q) => $q->whereNotIn('id', $keep))->delete();

        foreach (array_values($rows) as $order => $row) {
            $values = [
                'name' => trim($row['name']),
                'factor' => (float) $row['factor'],
                'sale_price' => (float) $row['sale_price'],
                'barcode' => ($row['barcode'] ?? null) ?: null,
                'sort_order' => $order,
                'updated_at' => now(),
            ];
            $scope = ['company_id' => $company, 'product_id' => $product];
            if (!empty($row['id'])) {
                $updated = DB::table('product_presentations')->where($scope + ['id' => (int) $row['id']])->update($values);
                if ($updated) continue;
            }
            DB::table('product_presentations')->insert($scope + $values + ['created_at' => now()]);
        }
    }

    /** Ningún código de presentación puede repetirse ni coincidir con el de un producto. */
    private function assertBarcodesFree(int $company, int $product, array $rows): void
    {
        $seen = [];
        foreach ($rows as $index => $row) {
            $code = trim((string) ($row['barcode'] ?? ''));
            if ($code === '') continue;
            $taken = isset($seen[$code])
                || DB::table('products')->where(['company_id' => $company, 'barcode' => $code])->exists()
                || DB::table('product_presentations')->where(['company_id' => $company, 'barcode' => $code])->where('product_id', '!=', $product)->exists();
            if ($taken) {
                throw ValidationException::withMessages(["presentations.$index.barcode" => "El código {$code} ya está asignado a otro producto o presentación."]);
            }
            $seen[$code] = true;
        }
    }

    /** ¿El código está usado por una presentación de otro producto? */
    public function barcodeUsedByPresentation(int $company, string $code, ?int $exceptProduct = null): bool
    {
        return DB::table('product_presentations')->where(['company_id' => $company, 'barcode' => $code])
            ->when($exceptProduct, fn ($q) => $q->where('product_id', '!=', $exceptProduct))->exists();
    }

    /** Presentación activa del producto; 422 si no le pertenece. */
    public function find(int $company, int $product, int $presentation): object
    {
        $row = DB::table('product_presentations')
            ->where(['id' => $presentation, 'product_id' => $product, 'company_id' => $company])->first();
        abort_unless($row, 422, 'La presentación seleccionada no pertenece al producto.');
        return $row;
    }

    /** Copia las presentaciones al duplicar un producto. */
    public function copy(int $company, int $from, int $to): void
    {
        DB::table('product_presentations')->where(['company_id' => $company, 'product_id' => $from])->get()
            ->each(fn ($p) => DB::table('product_presentations')->insert([
                'company_id' => $company, 'product_id' => $to, 'name' => $p->name, 'factor' => $p->factor,
                'sale_price' => $p->sale_price, 'barcode' => null, 'sort_order' => $p->sort_order,
                'created_at' => now(), 'updated_at' => now(),
            ]));
    }
}
