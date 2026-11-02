<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Lotes con salida FEFO (primero vence, primero sale) y trazabilidad de reversas. */
class LotFefoTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;
    private int $company;
    private int $branch;
    private int $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $token = $this->postJson('/api/v1/login', ['email' => 'admin@deurstore.pe', 'password' => 'Deurstore2026!'])->assertOk()->json('data.token');
        $this->headers = ['Authorization' => 'Bearer '.$token];
        $user = User::where('email', 'admin@deurstore.pe')->first();
        $this->company = $user->company_id;
        $this->branch = $user->branch_id;
        $this->warehouse = DB::table('warehouses')->where(['company_id' => $this->company, 'branch_id' => $this->branch])->value('id');
    }

    private function product(): int
    {
        return $this->postJson('/api/v1/products', [
            'sku' => 'L-'.uniqid(), 'name' => 'Yogurt', 'category' => DB::table('categories')->value('name'),
            'unit_type' => 'unit', 'sale_price' => 5, 'average_cost' => 0, 'stock' => 0,
        ], $this->headers)->assertOk()->json('data.id');
    }

    private function buy(int $product, float $qty, ?string $lot = null, ?int $days = null, string $path = '/api/v1/purchases'): int
    {
        $item = ['product_id' => $product, 'quantity' => $qty, 'unit_cost' => 2];
        if ($lot) $item += ['lot_number' => $lot, 'expires_at' => now()->addDays($days)->toDateString()];
        return $this->postJson($path, ['document_type' => 'invoice', 'items' => [$item]], $this->headers)->assertCreated()->json('data.id');
    }

    private function sell(int $product, float $qty): int
    {
        return $this->postJson('/api/v1/sales', ['items' => [['product_id' => $product, 'quantity' => $qty]], 'payments' => [['method' => 'cash', 'amount' => 5 * $qty]]], $this->headers)
            ->assertCreated()->json('data.id');
    }

    private function lot(int $product, string $number, ?int $warehouse = null): float
    {
        return (float) DB::table('lots')->where(['warehouse_id' => $warehouse ?? $this->warehouse, 'product_id' => $product, 'lot_number' => $number])->value('quantity');
    }

    public function test_purchases_create_lots_and_sales_consume_the_one_expiring_first(): void
    {
        $p = $this->product();
        $this->buy($p, 10, 'TARDE', 60);
        $this->buy($p, 10, 'PRONTO', 10);

        $this->sell($p, 12);

        $this->assertEquals(0, $this->lot($p, 'PRONTO'));
        $this->assertEquals(8, $this->lot($p, 'TARDE'));
        $this->assertSame('depleted', DB::table('lots')->where(['product_id' => $p, 'lot_number' => 'PRONTO'])->value('status'));
    }

    public function test_cancelling_a_sale_puts_units_back_in_their_original_lots(): void
    {
        $p = $this->product();
        $this->buy($p, 10, 'TARDE', 60);
        $this->buy($p, 10, 'PRONTO', 10);
        $sale = $this->sell($p, 12);

        $this->postJson("/api/v1/sales/{$sale}/cancel", ['reason' => 'Error'], $this->headers)->assertOk();

        $this->assertEquals(10, $this->lot($p, 'PRONTO'));
        $this->assertEquals(10, $this->lot($p, 'TARDE'));
    }

    public function test_sale_returns_restore_lots_and_cannot_exceed_what_was_sold(): void
    {
        $p = $this->product();
        $this->buy($p, 10, 'PRONTO', 10);
        $this->buy($p, 10, 'TARDE', 60);
        $sale = $this->sell($p, 12);
        $detail = DB::table('sale_details')->where('sale_id', $sale)->value('id');

        $this->postJson("/api/v1/sales/{$sale}/return", ['reason' => 'Devolución', 'items' => [['sale_detail_id' => $detail, 'quantity' => 8]]], $this->headers)->assertCreated();
        // Se devuelven 8: primero los 2 del último lote consumido, luego 6 al anterior.
        $this->assertEquals(10, $this->lot($p, 'TARDE'));
        $this->assertEquals(6, $this->lot($p, 'PRONTO'));

        // Ya se devolvieron 8 de 12: no se pueden devolver otros 5.
        $this->postJson("/api/v1/sales/{$sale}/return", ['reason' => 'Otra vez', 'items' => [['sale_detail_id' => $detail, 'quantity' => 5]]], $this->headers)->assertUnprocessable();
    }

    public function test_returning_to_the_supplier_takes_units_from_that_purchase_lot(): void
    {
        $p = $this->product();
        $this->buy($p, 10, 'PRONTO', 10);
        $later = $this->buy($p, 10, 'TARDE', 60);
        $line = DB::table('purchase_details')->where('purchase_id', $later)->value('id');

        $this->postJson("/api/v1/purchases/{$later}/return", ['reason' => 'Mal estado', 'items' => [['purchase_detail_id' => $line, 'quantity' => 4]]], $this->headers)->assertCreated();

        $this->assertEquals(10, $this->lot($p, 'PRONTO'));
        $this->assertEquals(6, $this->lot($p, 'TARDE'));
    }

    public function test_cancelling_a_purchase_removes_its_lot(): void
    {
        $p = $this->product();
        $this->buy($p, 10, 'PRONTO', 10);
        $later = $this->buy($p, 10, 'TARDE', 60);

        $this->postJson("/api/v1/purchases/{$later}/cancel", ['reason' => 'Duplicada'], $this->headers)->assertOk();

        $this->assertEquals(0, $this->lot($p, 'TARDE'));
        $this->assertEquals(10, $this->lot($p, 'PRONTO'));
    }

    public function test_pending_purchases_keep_the_lot_until_confirmed(): void
    {
        $p = $this->product();
        $pending = $this->buy($p, 7, 'L-PEND', 30, '/api/v1/purchases/pending');
        $this->assertDatabaseMissing('lots', ['product_id' => $p]);

        $this->postJson("/api/v1/purchases/{$pending}/confirm", [], $this->headers)->assertOk();
        $this->assertEquals(7, $this->lot($p, 'L-PEND'));
    }

    public function test_transfers_move_lots_to_the_destination_warehouse(): void
    {
        $p = $this->product();
        $this->buy($p, 10, 'PRONTO', 10);
        $target = DB::table('warehouses')->insertGetId(['company_id' => $this->company, 'branch_id' => $this->branch, 'code' => 'AUX', 'name' => 'Depósito', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/v1/inventory/transfers', ['source_warehouse_id' => $this->warehouse, 'destination_warehouse_id' => $target, 'items' => [['product_id' => $p, 'quantity' => 6]]], $this->headers)->assertCreated();

        $this->assertEquals(4, $this->lot($p, 'PRONTO'));
        $this->assertEquals(6, $this->lot($p, 'PRONTO', $target));
        $this->assertSame(
            DB::table('lots')->where(['product_id' => $p, 'warehouse_id' => $this->warehouse])->value('expires_at'),
            DB::table('lots')->where(['product_id' => $p, 'warehouse_id' => $target])->value('expires_at'),
        );
    }

    public function test_adjustments_and_counts_that_lower_stock_consume_lots_fefo(): void
    {
        $p = $this->product();
        $this->buy($p, 10, 'PRONTO', 10);
        $this->buy($p, 10, 'TARDE', 60);

        $this->postJson('/api/v1/inventory/adjustments', ['warehouse_id' => $this->warehouse, 'reason' => 'Merma', 'items' => [['product_id' => $p, 'new_quantity' => 15]]], $this->headers)->assertCreated();
        $this->assertEquals(5, $this->lot($p, 'PRONTO'));

        $this->postJson('/api/v1/inventory/counts', ['warehouse_id' => $this->warehouse, 'items' => [['product_id' => $p, 'counted_quantity' => 8]]], $this->headers)->assertCreated();
        $this->assertEquals(0, $this->lot($p, 'PRONTO'));
        $this->assertEquals(8, $this->lot($p, 'TARDE'));
    }

    public function test_a_manual_lot_cannot_exceed_the_stock_without_lot(): void
    {
        $p = $this->product();
        $this->buy($p, 10, 'PRONTO', 10);
        $this->buy($p, 5); // 5 unidades sin lote

        $payload = ['warehouse_id' => $this->warehouse, 'product_id' => $p, 'lot_number' => 'MANUAL', 'expires_at' => now()->addDays(20)->toDateString(), 'unit_cost' => 2];
        $this->postJson('/api/v1/inventory/lots', $payload + ['quantity' => 6], $this->headers)->assertUnprocessable();
        $this->postJson('/api/v1/inventory/lots', $payload + ['quantity' => 5], $this->headers)->assertCreated();
        $this->assertEquals(5, $this->lot($p, 'MANUAL'));
    }

    public function test_alerts_show_expired_lots_and_ignore_depleted_ones(): void
    {
        $p = $this->product();
        $this->buy($p, 5, 'VENCIDO', 1);
        $this->buy($p, 5, 'AGOTADO', 5);
        DB::table('lots')->where(['product_id' => $p, 'lot_number' => 'VENCIDO'])->update(['expires_at' => now()->subDay()->toDateString()]);
        DB::table('lots')->where(['product_id' => $p, 'lot_number' => 'AGOTADO'])->update(['quantity' => 0, 'status' => 'depleted']);

        $alerts = $this->getJson('/api/v1/inventory/alerts', $this->headers)->assertOk()->json('data');
        $this->assertContains('VENCIDO', array_column($alerts['expired'], 'lot_number'));
        $this->assertNotContains('AGOTADO', array_column($alerts['expiring'], 'lot_number'));
    }
}
