<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductPresentationTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $token = $this->postJson('/api/v1/login', [
            'email' => 'admin@deurstore.pe',
            'password' => 'Deurstore2026!',
        ])->assertOk()->json('data.token');
        $this->headers = ['Authorization' => 'Bearer '.$token];
    }

    private function activate(string ...$codes): void
    {
        foreach ($codes as $code) {
            $this->putJson("/api/v1/units/{$code}", ['status' => 'active'], $this->headers)->assertOk();
        }
    }

    private function category(): string
    {
        return DB::table('categories')->where('status', 'active')->value('name');
    }

    private function createProduct(array $overrides = []): array
    {
        $payload = array_merge([
            'sku' => 'HUE-'.uniqid(),
            'name' => 'Huevos rosados',
            'category' => $this->category(),
            'unit_type' => 'unit',
            'average_cost' => 0.40,
            'sale_price' => 0.60,
            'stock' => 300,
            'presentations' => [
                ['name' => 'Docena', 'factor' => 12, 'sale_price' => 6.50],
                ['name' => 'Jaba', 'factor' => 30, 'sale_price' => 15.00, 'barcode' => '7750000000301'],
            ],
        ], $overrides);
        $id = $this->postJson('/api/v1/products', $payload, $this->headers)->assertOk()->json('data.id');

        return collect($this->getJson('/api/v1/products', $this->headers)->assertOk()->json('data'))->firstWhere('id', $id);
    }

    private function sell(array $item, float $total): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/sales', [
            'items' => [$item],
            'payments' => [['method' => 'cash', 'amount' => $total]],
        ], $this->headers);
    }

    public function test_products_list_includes_configured_presentations(): void
    {
        $product = $this->createProduct();

        $this->assertSame(['Docena', 'Jaba'], array_column($product['presentations'], 'name'));
        $this->assertEquals(12, $product['presentations'][0]['factor']);
        $this->assertSame('7750000000301', $product['presentations'][1]['barcode']);
    }

    public function test_presentation_sale_discounts_base_units_and_uses_catalog_price(): void
    {
        $product = $this->createProduct();
        $dozen = $product['presentations'][0];

        // El precio enviado por el navegador se ignora: manda el catálogo.
        $sale = $this->sell(['product_id' => $product['id'], 'presentation_id' => $dozen['id'], 'quantity' => 2, 'unit_price' => 0.01], 13.00)
            ->assertCreated()->json('data');

        $detail = DB::table('sale_details')->where('sale_id', $sale['id'])->first();
        $this->assertEquals(24, (float) $detail->quantity);
        $this->assertEquals(13.00, (float) $detail->total);
        $this->assertEqualsWithDelta(6.50 / 12, (float) $detail->unit_price, 0.0001);
        $this->assertEquals(0.40, (float) $detail->historical_unit_cost);
        $this->assertSame('Docena', $detail->presentation_name);
        $this->assertEquals(2, (float) $detail->presentation_quantity);
        $this->assertSame('presentation', $detail->entry_mode);

        $stock = collect($this->getJson('/api/v1/products', $this->headers)->json('data'))->firstWhere('id', $product['id'])['stock'];
        $this->assertEquals(276, (float) $stock);
    }

    public function test_weight_product_sells_half_kilo_presentation(): void
    {
        $product = $this->createProduct([
            'name' => 'Azúcar a granel', 'unit_type' => 'kg', 'average_cost' => 3.00, 'sale_price' => 4.80, 'stock' => 50,
            'presentations' => [['name' => '½ kg', 'factor' => 0.5, 'sale_price' => 2.50]],
        ]);

        $this->sell(['product_id' => $product['id'], 'presentation_id' => $product['presentations'][0]['id'], 'quantity' => 3], 7.50)
            ->assertCreated();

        $stock = collect($this->getJson('/api/v1/products', $this->headers)->json('data'))->firstWhere('id', $product['id'])['stock'];
        $this->assertEqualsWithDelta(48.5, (float) $stock, 0.0001);
    }

    public function test_liter_products_allow_decimal_quantities(): void
    {
        $product = $this->createProduct(['name' => 'Aceite a granel', 'unit_type' => 'l', 'sale_price' => 8.00, 'stock' => 20, 'presentations' => []]);

        $this->assertTrue((bool) $product['allow_decimal_quantity']);
        $this->sell(['product_id' => $product['id'], 'quantity' => 0.25], 2.00)->assertCreated();
    }

    public function test_extended_units_follow_their_family_decimal_rules(): void
    {
        $this->activate('g', 'm', 'pair');
        $spice = $this->createProduct(['name' => 'Comino', 'unit_type' => 'g', 'sale_price' => 0.05, 'stock' => 2000,
            'presentations' => [['name' => '1 kg', 'factor' => 1000, 'sale_price' => 45]]]);
        $this->assertTrue((bool) $spice['allow_decimal_quantity']);
        $this->sell(['product_id' => $spice['id'], 'quantity' => 12.5], 0.63)->assertCreated();

        $cable = $this->createProduct(['name' => 'Cable', 'unit_type' => 'm', 'sale_price' => 2, 'stock' => 100, 'presentations' => []]);
        $this->sell(['product_id' => $cable['id'], 'quantity' => 2.5], 5.00)->assertCreated();

        $shoes = $this->createProduct(['name' => 'Zapatillas', 'unit_type' => 'pair', 'sale_price' => 80, 'stock' => 10,
            'presentations' => [['name' => 'Docena', 'factor' => 12, 'sale_price' => 900]]]);
        $this->assertFalse((bool) $shoes['allow_decimal_quantity']);
        $this->sell(['product_id' => $shoes['id'], 'quantity' => 1.5], 120)->assertUnprocessable();

        $this->postJson('/api/v1/products', [
            'sku' => 'BAD-UNIT', 'name' => 'X', 'category' => $this->category(), 'unit_type' => 'furlong', 'sale_price' => 1,
        ], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('unit_type');
    }

    public function test_base_unit_cannot_change_once_the_product_has_stock_or_movements(): void
    {
        $this->activate('g');
        $sugar = $this->createProduct(['name' => 'Azúcar', 'unit_type' => 'kg', 'stock' => 50, 'presentations' => []]);
        $base = collect($sugar)->only(['sku', 'name', 'category', 'average_cost', 'sale_price'])->all();

        $this->putJson("/api/v1/products/{$sugar['id']}", $base + ['unit_type' => 'g'], $this->headers)->assertUnprocessable();
        $this->assertSame('kg', DB::table('products')->where('id', $sugar['id'])->value('unit_type'));

        // Sin stock ni movimientos (producto recién creado por error) sí puede corregirse.
        $fresh = $this->createProduct(['name' => 'Recién creado', 'unit_type' => 'kg', 'stock' => 0, 'presentations' => []]);
        $freshBase = collect($fresh)->only(['sku', 'name', 'category', 'average_cost', 'sale_price'])->all();
        $this->putJson("/api/v1/products/{$fresh['id']}", $freshBase + ['unit_type' => 'g'], $this->headers)->assertOk();
    }

    public function test_a_barcode_identifies_a_single_product_or_presentation(): void
    {
        $existing = DB::table('products')->whereNotNull('barcode')->value('barcode');

        // Presentación con el código de otro producto.
        $this->postJson('/api/v1/products', [
            'sku' => 'DUP-1', 'name' => 'Duplicado', 'category' => $this->category(), 'unit_type' => 'unit', 'sale_price' => 1,
            'presentations' => [['name' => 'Docena', 'factor' => 12, 'sale_price' => 10, 'barcode' => $existing]],
        ], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('presentations.0.barcode');

        // Producto con el código de la presentación de otro.
        $this->createProduct();
        $this->postJson('/api/v1/products', [
            'sku' => 'DUP-2', 'name' => 'Otro', 'category' => $this->category(), 'unit_type' => 'unit', 'sale_price' => 1, 'barcode' => '7750000000301',
        ], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('barcode');
    }

    public function test_labels_include_presentations_with_their_own_barcode(): void
    {
        $product = $this->createProduct();

        $label = $this->postJson('/api/v1/products/labels', ['product_ids' => [$product['id']]], $this->headers)->assertOk()->json('data.0');
        $this->assertSame([['name' => 'Jaba', 'barcode' => '7750000000301']], collect($label['presentations'])->map(fn ($p) => ['name' => $p['name'], 'barcode' => $p['barcode']])->all());
    }

    public function test_presentation_quantity_must_be_whole(): void
    {
        $product = $this->createProduct();

        $this->sell(['product_id' => $product['id'], 'presentation_id' => $product['presentations'][0]['id'], 'quantity' => 1.5], 9.75)
            ->assertUnprocessable();
    }

    public function test_presentation_of_another_product_is_rejected(): void
    {
        $eggs = $this->createProduct();
        $other = $this->createProduct(['name' => 'Gaseosa', 'presentations' => [['name' => 'Six pack', 'factor' => 6, 'sale_price' => 9.00]]]);

        $this->sell(['product_id' => $eggs['id'], 'presentation_id' => $other['presentations'][0]['id'], 'quantity' => 1], 9.00)
            ->assertUnprocessable();
    }

    public function test_unit_products_reject_fractional_equivalences(): void
    {
        $this->postJson('/api/v1/products', [
            'sku' => 'X-1', 'name' => 'Lapicero', 'category' => $this->category(), 'unit_type' => 'unit', 'sale_price' => 1,
            'presentations' => [['name' => 'Medio', 'factor' => 0.5, 'sale_price' => 0.5]],
        ], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('presentations.0.factor');
    }

    public function test_saving_replaces_presentations_and_omitting_them_keeps_them(): void
    {
        $product = $this->createProduct();
        $base = collect($product)->only(['sku', 'name', 'category', 'unit_type', 'average_cost', 'sale_price'])->all();

        $this->putJson("/api/v1/products/{$product['id']}", $base + ['presentations' => [
            ['id' => $product['presentations'][0]['id'], 'name' => 'Docena', 'factor' => 12, 'sale_price' => 7.00],
        ]], $this->headers)->assertOk();
        $this->assertSame(1, DB::table('product_presentations')->where('product_id', $product['id'])->count());
        $this->assertEquals(7.00, (float) DB::table('product_presentations')->where('product_id', $product['id'])->value('sale_price'));

        // Otros formularios (p. ej. importación) no envían presentaciones: no deben borrarse.
        $this->putJson("/api/v1/products/{$product['id']}", $base, $this->headers)->assertOk();
        $this->assertSame(1, DB::table('product_presentations')->where('product_id', $product['id'])->count());
    }

    private function portalOrder(array $items): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/portal/orders', [
            'customer_name' => 'Cliente portal', 'customer_phone' => '999999999', 'delivery_address' => 'Piura', 'items' => $items,
        ]);
    }

    public function test_portal_catalog_and_product_expose_presentations_without_barcodes(): void
    {
        $product = $this->createProduct();

        $listed = collect($this->getJson('/api/v1/portal/catalog')->assertOk()->json('data.data'))->firstWhere('id', $product['id']);
        $this->assertSame(['Docena', 'Jaba'], array_column($listed['presentations'], 'name'));
        $this->assertArrayNotHasKey('barcode', $listed['presentations'][1]);

        $this->getJson("/api/v1/portal/products/{$product['id']}")->assertOk()->assertJsonPath('data.presentations.0.name', 'Docena');
    }

    public function test_portal_order_by_presentation_becomes_a_sale_with_the_same_presentation(): void
    {
        $product = $this->createProduct();
        $order = $this->portalOrder([['product_id' => $product['id'], 'presentation_id' => $product['presentations'][1]['id'], 'quantity' => 2]])
            ->assertCreated()->assertJsonPath('data.total', 30)->json('data');

        $item = json_decode(DB::table('web_orders')->where('id', $order['id'])->value('items'), true)[0];
        $this->assertEquals(60, $item['quantity']);
        $this->assertSame('Jaba', $item['presentation']['name']);
        $this->assertEquals(60, (float) DB::table('stock_reservations')->where('reference_id', $order['id'])->value('quantity'));

        foreach (['preparing', 'packed', 'ready', 'delivered'] as $status) {
            $this->postJson("/api/v1/web-orders/{$order['id']}/status", ['status' => $status], $this->headers)->assertOk();
        }
        $detail = DB::table('sale_details')->where('product_id', $product['id'])->latest('id')->first();
        $this->assertSame('Jaba', $detail->presentation_name);
        $this->assertEquals(2, (float) $detail->presentation_quantity);
        $this->assertEquals(60, (float) $detail->quantity);
        $this->assertEquals(30.00, (float) $detail->total);
    }

    public function test_portal_order_checks_stock_across_lines_of_the_same_product(): void
    {
        $product = $this->createProduct(['stock' => 40]);

        // 30 (jaba) + 12 (docena) = 42 > 40 disponibles, aunque cada línea por separado cabe.
        $this->portalOrder([
            ['product_id' => $product['id'], 'presentation_id' => $product['presentations'][1]['id'], 'quantity' => 1],
            ['product_id' => $product['id'], 'presentation_id' => $product['presentations'][0]['id'], 'quantity' => 1],
        ])->assertUnprocessable();
    }

    public function test_returning_part_of_a_presentation_sale_refunds_proportionally(): void
    {
        $product = $this->createProduct();
        $sale = $this->sell(['product_id' => $product['id'], 'presentation_id' => $product['presentations'][0]['id'], 'quantity' => 1], 6.50)
            ->assertCreated()->json('data');
        $detail = DB::table('sale_details')->where('sale_id', $sale['id'])->first();

        $this->postJson("/api/v1/sales/{$sale['id']}/return", [
            'reason' => 'Huevos rotos',
            'items' => [['sale_detail_id' => $detail->id, 'quantity' => 6]],
        ], $this->headers)->assertSuccessful();

        $this->assertEquals(3.25, (float) DB::table('sale_return_details')->where('sale_detail_id', $detail->id)->value('total'));
    }
}
