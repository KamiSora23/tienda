<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_commercial_flow(): void
    {
        $this->seed(DatabaseSeeder::class);

        $login = $this->postJson('/api/v1/login', [
            'email' => 'admin@deurstore.pe',
            'password' => 'Deurstore2026!',
        ])->assertOk()->json('data');

        $headers = ['Authorization' => 'Bearer '.$login['token']];
        $products = $this->getJson('/api/v1/products', $headers)->assertOk()->json('data');
        $product = $products[0];
        $initialStock = (float) $product['stock'];

        $this->postJson('/api/v1/purchases', [
            'items' => [[
                'product_id' => $product['id'],
                'quantity' => 10,
                'unit_cost' => 3.80,
            ]],
            'freight' => 5,
        ], $headers)->assertCreated();

        $stockAfterPurchase = (float) $this->getJson('/api/v1/products', $headers)->json('data.0.stock');
        $this->assertEquals($initialStock + 10, $stockAfterPurchase);

        $saleTotal = round(2 * (float) $product['sale_price'], 2);
        $sale = $this->postJson('/api/v1/sales', [
            'items' => [[
                'product_id' => $product['id'],
                'quantity' => 2,
                'unit_price' => (float) $product['sale_price'],
            ]],
            'payments' => [['method' => 'cash', 'amount' => $saleTotal]],
        ], $headers)->assertCreated()->json('data');

        $stockAfterSale = (float) $this->getJson('/api/v1/products', $headers)->json('data.0.stock');
        $this->assertEquals($initialStock + 8, $stockAfterSale);

        $this->postJson('/api/v1/expenses', [
            'category' => 'Servicios',
            'description' => 'Prueba de gasto',
            'amount' => 2.50,
            'expense_date' => now()->toDateString(),
        ], $headers)->assertOk();

        // Además de los saldos iniciales del stock sembrado, el kardex registra la compra y la venta.
        $types = collect($this->getJson('/api/v1/inventory/kardex', $headers)->assertOk()->json('data'))->countBy('type');
        $this->assertSame(1, $types['purchase_in'] ?? 0);
        $this->assertSame(1, $types['sale_out'] ?? 0);
        $this->assertGreaterThan(0, $types['initial_stock'] ?? 0);

        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('audit_events', 3);

        $this->postJson("/api/v1/sales/{$sale['id']}/cancel", [
            'reason' => 'Prueba automatizada',
        ], $headers)->assertOk();

        $stockAfterCancellation = (float) $this->getJson('/api/v1/products', $headers)->json('data.0.stock');
        $this->assertEquals($initialStock + 10, $stockAfterCancellation);
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'status' => 'cancelled']);
    }
}
