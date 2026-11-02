<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeightedAmountSaleTest extends TestCase
{
    use RefreshDatabase;

    private function headers(): array
    {
        $this->seed(DatabaseSeeder::class);
        $token = $this->postJson('/api/v1/login', [
            'email' => 'admin@deurstore.pe',
            'password' => 'Deurstore2026!',
        ])->assertOk()->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_weighted_product_keeps_requested_amount_and_rounded_weight(): void
    {
        $headers = $this->headers();
        $product = collect($this->getJson('/api/v1/products', $headers)->assertOk()->json('data'))
            ->firstWhere('unit_type', 'kg');
        $initialStock = (float) $product['stock'];

        $sale = $this->postJson('/api/v1/sales', [
            'items' => [[
                'product_id' => $product['id'],
                'quantity' => 0.385,
                'unit_price' => 5.20,
                'entry_mode' => 'amount',
                'line_total' => 2.00,
            ]],
            'payments' => [['method' => 'cash', 'amount' => 2.00]],
        ], $headers)->assertCreated()->json('data');

        $this->assertDatabaseHas('sale_details', [
            'sale_id' => $sale['id'],
            'product_id' => $product['id'],
            'quantity' => 0.385,
            'unit_price' => 5.20,
            'total' => 2.00,
            'entry_mode' => 'amount',
        ]);
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'subtotal' => 2.00, 'total' => 2.00]);

        $stock = (float) collect($this->getJson('/api/v1/products', $headers)->assertOk()->json('data'))
            ->firstWhere('id', $product['id'])['stock'];
        $this->assertEqualsWithDelta($initialStock - 0.385, $stock, 0.0001);
    }

    public function test_unit_product_rejects_amount_entry_mode(): void
    {
        $headers = $this->headers();
        $product = collect($this->getJson('/api/v1/products', $headers)->assertOk()->json('data'))
            ->firstWhere('unit_type', 'unit');

        $this->postJson('/api/v1/sales', [
            'items' => [[
                'product_id' => $product['id'],
                'quantity' => 1,
                'unit_price' => $product['sale_price'],
                'entry_mode' => 'amount',
                'line_total' => 2.00,
            ]],
            'payments' => [['method' => 'cash', 'amount' => 2.00]],
        ], $headers)->assertUnprocessable()
            ->assertJsonPath('message', 'El modo por importe solo está disponible para productos vendidos por peso o volumen');
    }

    public function test_amount_mode_rejects_quantity_that_does_not_match_amount(): void
    {
        $headers = $this->headers();
        $product = collect($this->getJson('/api/v1/products', $headers)->assertOk()->json('data'))
            ->firstWhere('unit_type', 'kg');

        $this->postJson('/api/v1/sales', [
            'items' => [[
                'product_id' => $product['id'],
                'quantity' => 1,
                'unit_price' => 5.20,
                'entry_mode' => 'amount',
                'line_total' => 2.00,
            ]],
            'payments' => [['method' => 'cash', 'amount' => 2.00]],
        ], $headers)->assertUnprocessable()
            ->assertJsonPath('message', 'El peso enviado no coincide con el importe solicitado');
    }

    public function test_portal_order_keeps_requested_amount_and_rounded_weight(): void
    {
        $this->seed(DatabaseSeeder::class);
        $product = \Illuminate\Support\Facades\DB::table('products')->where('unit_type', 'kg')->first();
        $quantity = round(2 / (float) $product->sale_price, 3);

        $order = $this->postJson('/api/v1/portal/orders', [
            'customer_name' => 'Cliente portal',
            'customer_phone' => '999999999',
            'delivery_address' => 'Piura',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
                'entry_mode' => 'amount',
                'line_total' => 2.00,
            ]],
        ])->assertCreated()->assertJsonPath('data.total', 2)->json('data');

        $stored = \Illuminate\Support\Facades\DB::table('web_orders')->where('id', $order['id'])->first();
        $item = json_decode($stored->items, true)[0];
        $this->assertSame('amount', $item['entry_mode']);
        $this->assertEqualsWithDelta($quantity, $item['quantity'], 0.0001);
        $this->assertEqualsWithDelta(2.00, $item['total'], 0.001);
        $this->getJson('/api/v1/portal/orders/'.$order['number'])->assertUnprocessable();
        $this->getJson('/api/v1/portal/orders/'.$order['number'].'?phone=999999999')
            ->assertOk()->assertJsonPath('data.number', $order['number']);
    }
}
