<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lotes con salida FEFO (primero vence, primero sale). Los lotes desglosan parte del stock de un
 * almacén: la suma de sus cantidades nunca supera el stock. Toda salida de stock descuenta primero
 * de los lotes que vencen antes; lo que exceda sale del stock sin lote. Cada movimiento queda en
 * lot_movements para poder revertirlo al lote exacto.
 */
final class LotService
{
    /** Lotes con existencias, en orden FEFO (sin fecha de vencimiento al final). */
    public function available(int $warehouse, int $product): Collection
    {
        return DB::table('lots')->where(['warehouse_id' => $warehouse, 'product_id' => $product])->where('quantity', '>', 0)
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')->orderBy('expires_at')->orderBy('id')
            ->lockForUpdate()->get();
    }

    public function trackedQuantity(int $warehouse, int $product): float
    {
        return (float) DB::table('lots')->where(['warehouse_id' => $warehouse, 'product_id' => $product])->where('quantity', '>', 0)->sum('quantity');
    }

    /** Entrada a un lote (se crea o se suma si ya existe con ese número en el almacén). */
    public function receive(int $company, int $warehouse, int $product, float $quantity, string $lotNumber, ?string $expiresAt, float $unitCost, string $document, int $documentId, ?array $source = null): int
    {
        $lotNumber = trim($lotNumber);
        $lot = DB::table('lots')->where(['warehouse_id' => $warehouse, 'product_id' => $product, 'lot_number' => $lotNumber])->lockForUpdate()->first();
        if ($lot) {
            DB::table('lots')->where('id', $lot->id)->update([
                'quantity' => (float) $lot->quantity + $quantity, 'status' => 'available',
                'expires_at' => $lot->expires_at ?? $expiresAt, 'updated_at' => now(),
            ]);
            $id = $lot->id;
        } else {
            $id = DB::table('lots')->insertGetId([
                'company_id' => $company, 'warehouse_id' => $warehouse, 'product_id' => $product, 'lot_number' => $lotNumber,
                'expires_at' => $expiresAt, 'quantity' => $quantity, 'unit_cost' => $unitCost, 'status' => 'available',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->log($company, $id, $product, $warehouse, $quantity, $document, $documentId, $source);
        return $id;
    }

    /**
     * Salida FEFO. $preferLots se consumen antes (p. ej. al devolver al proveedor lo de su propia
     * compra). Devuelve lo descontado de cada lote: [[lot, cantidad], ...].
     */
    public function consume(int $company, int $warehouse, int $product, float $quantity, string $document, int $documentId, array $preferLots = []): array
    {
        $lots = $this->available($warehouse, $product);
        if ($preferLots) {
            $lots = $lots->sortBy(fn ($l) => in_array($l->id, $preferLots) ? 0 : 1)->values();
        }
        $remaining = $quantity;
        $taken = [];
        foreach ($lots as $lot) {
            if ($remaining <= 0.0005) break;
            $take = min((float) $lot->quantity, $remaining);
            $left = (float) $lot->quantity - $take;
            DB::table('lots')->where('id', $lot->id)->update(['quantity' => $left, 'status' => $left > 0.0005 ? 'available' : 'depleted', 'updated_at' => now()]);
            $this->log($company, $lot->id, $product, $warehouse, -$take, $document, $documentId);
            $taken[] = [$lot, $take];
            $remaining -= $take;
        }
        return $taken;
    }

    /**
     * Devuelve a sus lotes lo que un documento consumió (anulación o devolución de una venta),
     * sin superar lo que ese documento sacó de cada lote.
     */
    public function restore(int $company, string $sourceDocument, int $sourceId, int $product, float $quantity, string $document, int $documentId): void
    {
        $consumed = DB::table('lot_movements')->where(['document_type' => $sourceDocument, 'document_id' => $sourceId, 'product_id' => $product])
            ->where('quantity', '<', 0)->orderByDesc('id')->get();
        $restored = DB::table('lot_movements')->where(['source_document_type' => $sourceDocument, 'source_document_id' => $sourceId, 'product_id' => $product])
            ->groupBy('lot_id')->selectRaw('lot_id, SUM(quantity) total')->pluck('total', 'lot_id');
        $remaining = $quantity;
        foreach ($consumed->groupBy('lot_id') as $lotId => $rows) {
            if ($remaining <= 0.0005) break;
            $open = -$rows->sum('quantity') - (float) ($restored[$lotId] ?? 0);
            if ($open <= 0.0005) continue;
            $give = min($open, $remaining);
            $lot = DB::table('lots')->where('id', $lotId)->lockForUpdate()->first();
            DB::table('lots')->where('id', $lotId)->update(['quantity' => (float) $lot->quantity + $give, 'status' => 'available', 'updated_at' => now()]);
            $this->log($company, $lotId, $product, $lot->warehouse_id, $give, $document, $documentId, [$sourceDocument, $sourceId]);
            $remaining -= $give;
        }
    }

    /** Lotes que entraron con un documento (p. ej. una compra). */
    public function lotsReceivedBy(string $document, int $documentId, int $product): array
    {
        return DB::table('lot_movements')->where(['document_type' => $document, 'document_id' => $documentId, 'product_id' => $product])
            ->where('quantity', '>', 0)->pluck('lot_id')->unique()->values()->all();
    }

    private function log(int $company, int $lot, int $product, int $warehouse, float $quantity, string $document, int $documentId, ?array $source = null): void
    {
        DB::table('lot_movements')->insert([
            'company_id' => $company, 'lot_id' => $lot, 'product_id' => $product, 'warehouse_id' => $warehouse, 'quantity' => $quantity,
            'document_type' => $document, 'document_id' => $documentId,
            'source_document_type' => $source[0] ?? null, 'source_document_id' => $source[1] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
