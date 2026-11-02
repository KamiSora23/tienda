<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Revisión del portal público: datos expuestos, stock, imágenes, pedidos y seguimiento. */
class PortalReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private object $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@deurstore.pe')->first();
        DB::table('settings')->updateOrInsert(['company_id' => $this->admin->company_id, 'group' => 'portal', 'key' => 'enabled'], ['value' => '1']);
        $this->product = DB::table('products')->where(['company_id' => $this->admin->company_id, 'unit_type' => 'unit', 'status' => 'active'])->first();
    }

    private function stockOf(int $productId): float
    {
        return (float) $this->getJson("/api/v1/portal/products/{$productId}")->assertOk()->json('data.stock');
    }

    private function order(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/portal/orders', $overrides + ['customer_name' => 'Ana', 'customer_phone' => '987654321', 'delivery_address' => 'Av. Grau 100', 'items' => [['product_id' => $this->product->id, 'quantity' => 2]]]);
    }

    public function test_public_endpoints_never_expose_costs_or_private_company_data(): void
    {
        $product = $this->getJson("/api/v1/portal/products/{$this->product->id}")->assertOk()->json('data');
        foreach (['average_cost', 'minimum_price', 'minimum_stock', 'company_id'] as $field) {
            $this->assertArrayNotHasKey($field, $product);
        }
        $company = $this->getJson('/api/v1/portal')->assertOk()->json('data.company');
        $this->assertArrayNotHasKey('ruc', $company);
        $this->assertArrayHasKey('trade_name', $company);
    }

    public function test_catalog_shows_the_primary_image_and_the_stock_that_can_actually_be_ordered(): void
    {
        $id = $this->product->id;
        DB::table('product_images')->insert([
            ['company_id' => $this->admin->company_id, 'product_id' => $id, 'path' => 'x/a.png', 'sort_order' => 1, 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $this->admin->company_id, 'product_id' => $id, 'path' => 'x/b.png', 'sort_order' => 2, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $primary = DB::table('product_images')->where(['product_id' => $id, 'is_primary' => true])->value('id');
        $row = collect($this->getJson('/api/v1/portal/catalog?per_page=48')->assertOk()->json('data.data'))->firstWhere('id', $id);
        $this->assertStringEndsWith("/portal/product-images/{$primary}", $row['image']);

        // Un pedido reserva stock: el catálogo y la ficha muestran lo que queda.
        $before = $this->stockOf($id);
        $this->order()->assertCreated();
        $this->assertEqualsWithDelta($before - 2, $this->stockOf($id), 0.001);
        $this->assertEqualsWithDelta($before - 2, collect($this->getJson('/api/v1/portal/catalog?per_page=48')->json('data.data'))->firstWhere('id', $id)['stock'], 0.001);
    }

    public function test_catalog_sorts_by_price(): void
    {
        $prices = collect($this->getJson('/api/v1/portal/catalog?sort=price_desc&per_page=48')->assertOk()->json('data.data'))->pluck('sale_price')->map(fn ($p) => (float) $p);
        $this->assertSame($prices->sortDesc()->values()->all(), $prices->all());
        $this->getJson('/api/v1/portal/catalog?sort=random')->assertUnprocessable();
    }

    public function test_orders_validate_contact_data_and_number_per_company(): void
    {
        $this->order(['customer_phone' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('customer_phone');

        // Pedidos de otra empresa no saltan la numeración de esta.
        $other = DB::table('companies')->insertGetId(['uuid' => Str::uuid(), 'business_name' => 'OTRA SAC', 'trade_name' => 'Otra', 'ruc' => '20111111111', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('web_orders')->insert(['uuid' => Str::uuid(), 'company_id' => $other, 'number' => 'WEB-000001', 'customer_name' => 'X', 'customer_phone' => '1', 'items' => '[]', 'total' => 0, 'status' => 'pending', 'delivery_address' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $expected = 'WEB-'.str_pad((string) (DB::table('web_orders')->where('company_id', $this->admin->company_id)->count() + 1), 6, '0', STR_PAD_LEFT);
        $this->order()->assertCreated()->assertJsonPath('data.number', $expected);
    }

    public function test_tracking_returns_the_status_history_without_internal_ids(): void
    {
        $number = $this->order()->assertCreated()->json('data.number');
        $this->getJson("/api/v1/portal/orders/{$number}?phone=000000")->assertNotFound();
        $order = $this->getJson('/api/v1/portal/orders/'.strtolower($number).'?phone=987654321')->assertOk()->json('data');
        $this->assertSame('pending', $order['history'][0]['status']);
        $this->assertArrayNotHasKey('id', $order);
    }

    public function test_favorites_only_accept_products_of_the_store(): void
    {
        $customer = DB::table('web_customers')->insertGetId(['company_id' => $this->admin->company_id, 'name' => 'Ana', 'email' => 'ana@example.test', 'password' => Hash::make('secreto123'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $token = $this->postJson('/api/v1/portal/login', ['email' => 'ana@example.test', 'password' => 'secreto123'])->assertOk()->json('data.token');
        $headers = ['Authorization' => "Bearer {$token}"];
        $this->postJson('/api/v1/portal/account/favorites/999999', [], $headers)->assertNotFound();
        $this->postJson("/api/v1/portal/account/favorites/{$this->product->id}", [], $headers)->assertOk()->assertJsonPath('data.active', true);
        $this->assertSame($this->product->id, $this->getJson('/api/v1/portal/account/favorites', $headers)->assertOk()->json('data.0.id'));
        $this->assertSame(1, DB::table('web_favorites')->where('web_customer_id', $customer)->count());
    }

    private function portalSetting(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(['company_id' => $this->admin->company_id, 'group' => 'portal', 'key' => $key], ['value' => $value]);
    }

    public function test_delivery_orders_keep_address_reference_location_and_charge_the_fee(): void
    {
        $this->portalSetting('delivery_fee', '5');
        $response = $this->order(['fulfillment_type' => 'delivery', 'delivery_district' => 'Castilla', 'delivery_reference' => 'Frente al parque', 'delivery_lat' => -5.19, 'delivery_lng' => -80.63, 'customer_notes' => 'Tocar el timbre'])->assertCreated();
        $products = 2 * (float) $this->product->sale_price;
        $response->assertJsonPath('data.delivery_fee', 5);
        $this->assertEqualsWithDelta($products + 5, $response->json('data.total'), 0.001);

        $order = DB::table('web_orders')->where('number', $response->json('data.number'))->first();
        $this->assertSame('delivery', $order->fulfillment_type);
        $this->assertSame('Frente al parque', $order->delivery_reference);
        $this->assertEqualsWithDelta(-5.19, (float) $order->delivery_lat, 0.0001);

        $tracked = $this->getJson("/api/v1/portal/orders/{$order->number}?phone=987654321")->assertOk()->json('data');
        $this->assertSame('Castilla', $tracked['delivery_district']);

        // La dirección es obligatoria en delivery.
        $this->order(['fulfillment_type' => 'delivery', 'delivery_address' => ''])->assertUnprocessable()->assertJsonValidationErrors('delivery_address');
    }

    public function test_free_delivery_threshold_and_pickup_without_address(): void
    {
        $this->portalSetting('delivery_fee', '5');
        $this->portalSetting('free_delivery_from', '1');
        $this->order(['fulfillment_type' => 'delivery'])->assertCreated()->assertJsonPath('data.delivery_fee', 0);

        $this->portalSetting('pickup_address', 'Av. Grau 1500');
        $pickup = $this->postJson('/api/v1/portal/orders', ['fulfillment_type' => 'pickup', 'customer_name' => 'Ana', 'customer_phone' => '987654321', 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]])->assertCreated();
        $order = DB::table('web_orders')->where('number', $pickup->json('data.number'))->first();
        $this->assertSame('pickup', $order->fulfillment_type);
        $this->assertSame('Recojo en tienda: Av. Grau 1500', $order->delivery_address);
        $this->assertEquals(0, (float) $order->delivery_fee);
    }

    public function test_delivery_radius_requires_a_point_inside_the_zone(): void
    {
        // Tienda en la Plaza de Armas de Piura, reparto hasta 3 km.
        $this->portalSetting('store_lat', '-5.1945');
        $this->portalSetting('store_lng', '-80.6328');
        $this->portalSetting('delivery_radius_km', '3');
        $this->portalSetting('delivery_city', 'Piura');
        $this->getJson('/api/v1/portal')->assertJsonPath('data.portal.delivery_city', 'Piura')->assertJsonPath('data.portal.delivery_radius_km', 3);

        $this->order(['fulfillment_type' => 'delivery'])->assertUnprocessable()->assertJsonFragment(['message' => 'Marca en el mapa el punto de entrega.']);
        // Sullana queda a unos 35 km.
        $far = $this->order(['fulfillment_type' => 'delivery', 'delivery_lat' => -4.9039, 'delivery_lng' => -80.6853])->assertUnprocessable();
        $this->assertStringContainsString('solo repartimos hasta 3 km', $far->json('message'));
        $this->order(['fulfillment_type' => 'delivery', 'delivery_lat' => -5.1972, 'delivery_lng' => -80.6351])->assertCreated();
        // El recojo no depende de la zona.
        $this->order(['fulfillment_type' => 'pickup'])->assertCreated();
    }

    public function test_only_enabled_fulfillment_methods_are_accepted(): void
    {
        $this->portalSetting('delivery_enabled', '0');
        $this->order(['fulfillment_type' => 'delivery'])->assertUnprocessable()->assertJsonValidationErrors('fulfillment_type');
        $this->getJson('/api/v1/portal')->assertJsonPath('data.portal.delivery_enabled', false);

        $this->portalSetting('pickup_enabled', '0');
        $this->order(['fulfillment_type' => 'pickup'])->assertUnprocessable()->assertJsonFragment(['message' => 'La tienda no tiene métodos de entrega activos en este momento.']);
    }

    public function test_delivering_an_order_bills_the_products_and_books_the_fee_as_cash_income(): void
    {
        $this->portalSetting('delivery_fee', '4.5');
        $number = $this->order(['fulfillment_type' => 'delivery'])->assertCreated()->json('data.number');
        $order = DB::table('web_orders')->where('number', $number)->first();
        $token = $this->postJson('/api/v1/login', ['email' => 'admin@deurstore.pe', 'password' => 'Deurstore2026!'])->json('data.token');
        $h = ['Authorization' => "Bearer {$token}"];
        foreach (['preparing', 'packed', 'ready', 'delivered'] as $status) {
            $this->postJson("/api/v1/web-orders/{$order->id}/status", ['status' => $status], $h)->assertOk();
        }
        $saleId = DB::table('web_orders')->where('id', $order->id)->value('sale_id');
        $this->assertEqualsWithDelta((float) $order->total - 4.5, (float) DB::table('sales')->where('id', $saleId)->value('total'), 0.001);
        $this->assertTrue(DB::table('cash_movements')->where(['type' => 'income', 'amount' => 4.5])->where('description', 'like', "%{$number}%")->exists());
    }

    public function test_settings_store_booleans_and_empty_values_consistently(): void
    {
        $token = $this->postJson('/api/v1/login', ['email' => 'admin@deurstore.pe', 'password' => 'Deurstore2026!'])->json('data.token');
        $this->postJson('/api/v1/admin/settings', ['settings' => ['portal' => ['enabled' => false, 'contact_phone' => null]]], ['Authorization' => "Bearer {$token}"])->assertOk();
        $values = DB::table('settings')->where(['company_id' => $this->admin->company_id, 'group' => 'portal'])->pluck('value', 'key');
        $this->assertSame('0', $values['enabled']);
        $this->assertSame('', $values['contact_phone']);
        $this->getJson('/api/v1/portal/catalog')->assertStatus(503);
    }
}
