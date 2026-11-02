<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Circuito proveedor → compra → inventario → costo, con sus reversas. */
class SupplierInventoryFlowTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;
    private int $company;
    private int $branch;
    private int $warehouse;
    private int $supplier;

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
        $this->supplier = DB::table('suppliers')->where('company_id', $this->company)->value('id');
    }

    /** Producto nuevo sin stock ni costo, para que las cuentas sean exactas. */
    private function product(string $name = 'Producto prueba'): int
    {
        return $this->postJson('/api/v1/products', [
            'sku' => 'T-'.uniqid(), 'name' => $name, 'category' => DB::table('categories')->value('name'),
            'unit_type' => 'unit', 'sale_price' => 10, 'average_cost' => 0, 'stock' => 0,
        ], $this->headers)->assertOk()->json('data.id');
    }

    private function buy(array $items, array $extra = [], string $path = '/api/v1/purchases'): int
    {
        return $this->postJson($path, $extra + ['supplier_id' => $this->supplier, 'document_type' => 'invoice', 'items' => $items], $this->headers)
            ->assertCreated()->json('data.id');
    }

    private function stock(int $product, ?int $warehouse = null): float
    {
        return (float) DB::table('stocks')->where(['warehouse_id' => $warehouse ?? $this->warehouse, 'product_id' => $product])->value('quantity');
    }

    private function cost(int $product): float
    {
        return (float) DB::table('products')->where('id', $product)->value('average_cost');
    }

    private function detailId(int $purchase, int $product): int
    {
        return DB::table('purchase_details')->where(['purchase_id' => $purchase, 'product_id' => $product])->value('id');
    }

    public function test_freight_is_spread_by_line_value_not_by_quantity(): void
    {
        $eggs = $this->product('Huevos');
        $rice = $this->product('Saco de arroz');
        $id = $this->buy([['product_id' => $eggs, 'quantity' => 100, 'unit_cost' => 0.40], ['product_id' => $rice, 'quantity' => 1, 'unit_cost' => 150]], ['freight' => 19]);

        // Subtotal 190 y flete 19 (10 %): cada línea sube un 10 % de su valor.
        $this->assertEqualsWithDelta(0.44, (float) DB::table('purchase_details')->where(['purchase_id' => $id, 'product_id' => $eggs])->value('effective_unit_cost'), 0.0001);
        $this->assertEqualsWithDelta(165.00, (float) DB::table('purchase_details')->where(['purchase_id' => $id, 'product_id' => $rice])->value('effective_unit_cost'), 0.0001);
        $this->assertEqualsWithDelta(0.44, $this->cost($eggs), 0.0001);
    }

    public function test_cancelling_a_purchase_restores_stock_and_average_cost(): void
    {
        $p = $this->product();
        $this->buy([['product_id' => $p, 'quantity' => 10, 'unit_cost' => 2]]);
        $second = $this->buy([['product_id' => $p, 'quantity' => 10, 'unit_cost' => 4]]);
        $this->assertEqualsWithDelta(3.0, $this->cost($p), 0.0001);

        $this->postJson("/api/v1/purchases/{$second}/cancel", ['reason' => 'Error de digitación'], $this->headers)->assertOk();

        $this->assertEquals(10, $this->stock($p));
        $this->assertEqualsWithDelta(2.0, $this->cost($p), 0.0001);
    }

    public function test_cancelling_after_a_partial_return_only_reverses_what_is_left(): void
    {
        $p = $this->product();
        $id = $this->buy([['product_id' => $p, 'quantity' => 10, 'unit_cost' => 2]]);
        $this->postJson("/api/v1/purchases/{$id}/return", ['reason' => 'Dañados', 'items' => [['purchase_detail_id' => $this->detailId($id, $p), 'quantity' => 3]]], $this->headers)->assertCreated();
        $this->assertEquals(7, $this->stock($p));

        $this->postJson("/api/v1/purchases/{$id}/cancel", ['reason' => 'Anulada'], $this->headers)->assertOk();
        $this->assertEquals(0, $this->stock($p));
    }

    public function test_returning_goods_to_the_supplier_removes_their_cost(): void
    {
        $p = $this->product();
        $this->buy([['product_id' => $p, 'quantity' => 10, 'unit_cost' => 2]]);
        $second = $this->buy([['product_id' => $p, 'quantity' => 10, 'unit_cost' => 4]]);

        $this->postJson("/api/v1/purchases/{$second}/return", ['reason' => 'Vencidos', 'items' => [['purchase_detail_id' => $this->detailId($second, $p), 'quantity' => 10]]], $this->headers)->assertCreated();

        $this->assertEquals(10, $this->stock($p));
        $this->assertEqualsWithDelta(2.0, $this->cost($p), 0.0001);
    }

    public function test_pending_or_cancelled_purchases_cannot_be_returned_or_paid(): void
    {
        $p = $this->product();
        $pending = $this->buy([['product_id' => $p, 'quantity' => 5, 'unit_cost' => 2]], [], '/api/v1/purchases/pending');
        $this->postJson("/api/v1/purchases/{$pending}/return", ['reason' => 'X', 'items' => [['purchase_detail_id' => $this->detailId($pending, $p), 'quantity' => 1]]], $this->headers)->assertUnprocessable();

        $confirmed = $this->buy([['product_id' => $p, 'quantity' => 5, 'unit_cost' => 2]]);
        $this->postJson("/api/v1/purchases/{$confirmed}/cancel", ['reason' => 'X'], $this->headers)->assertOk();
        $this->postJson("/api/v1/purchases/{$confirmed}/payments", ['method' => 'cash', 'amount' => 1, 'paid_at' => now()->toDateString()], $this->headers)->assertUnprocessable();
    }

    public function test_payments_cannot_exceed_what_is_owed_after_returns(): void
    {
        $p = $this->product();
        $id = $this->buy([['product_id' => $p, 'quantity' => 10, 'unit_cost' => 2]]); // total 20
        $this->postJson("/api/v1/purchases/{$id}/return", ['reason' => 'Dañados', 'items' => [['purchase_detail_id' => $this->detailId($id, $p), 'quantity' => 5]]], $this->headers)->assertCreated(); // −10

        $this->postJson("/api/v1/purchases/{$id}/payments", ['method' => 'cash', 'amount' => 15, 'paid_at' => now()->toDateString()], $this->headers)->assertUnprocessable();
        $this->postJson("/api/v1/purchases/{$id}/payments", ['method' => 'cash', 'amount' => 10, 'paid_at' => now()->toDateString()], $this->headers)->assertCreated();
    }

    public function test_supplier_account_only_counts_confirmed_purchases_minus_returns(): void
    {
        $supplier = DB::table('suppliers')->insertGetId(['company_id' => $this->company, 'name' => 'Proveedor cuenta', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->supplier = $supplier;
        $p = $this->product();
        $kept = $this->buy([['product_id' => $p, 'quantity' => 10, 'unit_cost' => 2]]);            // 20
        $cancelled = $this->buy([['product_id' => $p, 'quantity' => 10, 'unit_cost' => 3]]);       // 30, se anula
        $this->buy([['product_id' => $p, 'quantity' => 10, 'unit_cost' => 5]], [], '/api/v1/purchases/pending'); // 50, pendiente
        $this->postJson("/api/v1/purchases/{$cancelled}/cancel", ['reason' => 'X'], $this->headers)->assertOk();
        $this->postJson("/api/v1/purchases/{$kept}/return", ['reason' => 'X', 'items' => [['purchase_detail_id' => $this->detailId($kept, $p), 'quantity' => 2]]], $this->headers)->assertCreated(); // −4
        $this->postJson("/api/v1/purchases/{$kept}/payments", ['method' => 'cash', 'amount' => 6, 'paid_at' => now()->toDateString()], $this->headers)->assertCreated();

        $detail = $this->getJson("/api/v1/crm/suppliers/{$supplier}", $this->headers)->assertOk()->json('data');
        $this->assertEquals(['purchases' => 20, 'returns' => 4, 'paid' => 6, 'balance' => 10], array_map('floatval', $detail['account']));
        $this->assertEquals(8, (float) collect($detail['products'])->firstWhere('product_id', $p)['quantity']); // 10 comprados − 2 devueltos
    }

    public function test_average_cost_uses_the_stock_of_every_warehouse(): void
    {
        $p = $this->product();
        $this->buy([['product_id' => $p, 'quantity' => 100, 'unit_cost' => 1]]);

        // Segunda sucursal con su almacén, sin stock de este producto.
        $branch = DB::table('branches')->insertGetId(['company_id' => $this->company, 'code' => 'B2', 'name' => 'Sucursal 2', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('warehouses')->insert(['company_id' => $this->company, 'branch_id' => $branch, 'code' => 'W2', 'name' => 'Almacén 2', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->where('email', 'admin@deurstore.pe')->update(['branch_id' => $branch]);

        $this->buy([['product_id' => $p, 'quantity' => 10, 'unit_cost' => 2]]);
        $this->assertEqualsWithDelta(120 / 110, $this->cost($p), 0.0001);
    }

    public function test_confirming_a_pending_purchase_uses_its_own_branch_and_records_cost_history(): void
    {
        $p = $this->product();
        $pending = $this->buy([['product_id' => $p, 'quantity' => 4, 'unit_cost' => 5]], [], '/api/v1/purchases/pending');

        $branch = DB::table('branches')->insertGetId(['company_id' => $this->company, 'code' => 'B3', 'name' => 'Sucursal 3', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $other = DB::table('warehouses')->insertGetId(['company_id' => $this->company, 'branch_id' => $branch, 'code' => 'W3', 'name' => 'Almacén 3', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->where('email', 'admin@deurstore.pe')->update(['branch_id' => $branch]);

        $this->postJson("/api/v1/purchases/{$pending}/confirm", [], $this->headers)->assertOk();

        $this->assertEquals(4, $this->stock($p));          // almacén de la sucursal de la compra
        $this->assertEquals(0, $this->stock($p, $other));  // no el de la sucursal actual del usuario
        $this->assertDatabaseHas('cost_history', ['product_id' => $p, 'new_cost' => 5]);
        $this->assertDatabaseHas('audit_events', ['event' => 'purchase.confirmed', 'entity_id' => $pending]);
    }

    public function test_transfers_and_counts_appear_in_the_kardex(): void
    {
        $p = $this->product();
        $this->buy([['product_id' => $p, 'quantity' => 10, 'unit_cost' => 2]]);
        $target = DB::table('warehouses')->insertGetId(['company_id' => $this->company, 'branch_id' => $this->branch, 'code' => 'W-AUX', 'name' => 'Depósito', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/v1/inventory/transfers', ['source_warehouse_id' => $this->warehouse, 'destination_warehouse_id' => $target, 'items' => [['product_id' => $p, 'quantity' => 4]]], $this->headers)->assertCreated();
        $this->postJson('/api/v1/inventory/counts', ['warehouse_id' => $this->warehouse, 'items' => [['product_id' => $p, 'counted_quantity' => 5]]], $this->headers)->assertCreated();

        $types = DB::table('inventory_movements')->where('product_id', $p)->orderBy('id')->pluck('type')->all();
        $this->assertSame(['purchase_in', 'transfer_out', 'transfer_in', 'count_adjustment'], $types);
        $this->assertEquals(5, $this->stock($p));
        $this->assertEquals(4, $this->stock($p, $target));
    }

    public function test_counts_reject_another_companys_warehouse(): void
    {
        $company = DB::table('companies')->insertGetId(['uuid' => Str::uuid(), 'business_name' => 'AJENA SAC', 'trade_name' => 'Ajena', 'ruc' => '20333333333', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('branches')->insertGetId(['company_id' => $company, 'code' => 'AJ', 'name' => 'Ajena', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $warehouse = DB::table('warehouses')->insertGetId(['company_id' => $company, 'branch_id' => $branch, 'code' => 'AJ-W', 'name' => 'Ajena', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $product = DB::table('products')->insertGetId(['uuid' => Str::uuid(), 'company_id' => $company, 'sku' => 'AJ-1', 'name' => 'Ajeno', 'category' => 'General', 'unit_type' => 'unit', 'allow_decimal_quantity' => 0, 'average_cost' => 1, 'sale_price' => 2, 'minimum_price' => 0, 'minimum_stock' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stocks')->insert(['company_id' => $company, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 9, 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/v1/inventory/counts', ['warehouse_id' => $warehouse, 'items' => [['product_id' => $product, 'counted_quantity' => 0]]], $this->headers)->assertNotFound();
        $this->assertEquals(9, (float) DB::table('stocks')->where(['warehouse_id' => $warehouse, 'product_id' => $product])->value('quantity'));
    }

    public function test_initial_stock_of_a_new_product_appears_in_the_kardex(): void
    {
        $id = $this->postJson('/api/v1/products', [
            'sku' => 'INI-1', 'name' => 'Con stock inicial', 'category' => DB::table('categories')->value('name'),
            'unit_type' => 'unit', 'sale_price' => 5, 'average_cost' => 3, 'stock' => 40,
        ], $this->headers)->assertOk()->json('data.id');

        $this->assertDatabaseHas('inventory_movements', ['product_id' => $id, 'type' => 'initial_stock', 'quantity' => 40, 'new_stock' => 40]);
    }

    public function test_purchase_detail_reports_what_was_already_returned(): void
    {
        $p = $this->product();
        $id = $this->buy([['product_id' => $p, 'quantity' => 10, 'unit_cost' => 2]]);
        $this->postJson("/api/v1/purchases/{$id}/return", ['reason' => 'X', 'items' => [['purchase_detail_id' => $this->detailId($id, $p), 'quantity' => 4]]], $this->headers)->assertCreated();

        $this->assertEquals(4, (float) $this->getJson("/api/v1/purchases/{$id}", $this->headers)->assertOk()->json('data.items.0.returned'));
    }

    public function test_adjusting_a_product_without_stock_in_that_warehouse_creates_it(): void
    {
        $p = $this->product();
        $target = DB::table('warehouses')->insertGetId(['company_id' => $this->company, 'branch_id' => $this->branch, 'code' => 'W-NEW', 'name' => 'Nuevo', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/v1/inventory/adjustments', ['warehouse_id' => $target, 'reason' => 'Inventario inicial', 'items' => [['product_id' => $p, 'new_quantity' => 6]]], $this->headers)->assertCreated();
        $this->assertEquals(6, $this->stock($p, $target));
    }
}
