<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvancedModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_promotions_pending_purchases_profiles_and_external_api(): void
    {
        $this->seed(DatabaseSeeder::class);
        $login = $this->postJson('/api/v1/login', [
            'email' => 'admin@deurstore.pe',
            'password' => 'Deurstore2026!',
        ])->assertOk()->json('data');
        $headers = ['Authorization' => 'Bearer '.$login['token']];
        $product = $this->getJson('/api/v1/products', $headers)->assertOk()->json('data.0');

        $promotion = $this->postJson('/api/v1/promotions', [
            'name' => 'Prueba 10%',
            'type' => 'percentage',
            'value' => 10,
            'starts_at' => now()->subHour()->toDateTimeString(),
            'ends_at' => now()->addDay()->toDateTimeString(),
            'status' => 'active',
            'rules' => ['minimum_quantity' => 1],
            'product_ids' => [$product['id']],
        ], $headers)->assertOk()->json('data');

        $subtotal = round((float) $product['sale_price'], 2);
        $total = round($subtotal * .9, 2);
        $this->postJson('/api/v1/sales', [
            'promotion_id' => $promotion['id'],
            'items' => [['product_id' => $product['id'], 'quantity' => 1, 'unit_price' => $subtotal]],
            'payments' => [['method' => 'cash', 'amount' => $total]],
        ], $headers)->assertCreated();
        $this->assertDatabaseCount('promotion_usages', 1);

        $pending = $this->postJson('/api/v1/purchases/pending', [
            'document_type' => 'invoice',
            'invoice_number' => 'F001-99',
            'items' => [['product_id' => $product['id'], 'quantity' => 2, 'unit_cost' => 3]],
        ], $headers)->assertCreated()->json('data');
        $this->assertDatabaseHas('purchases', ['id' => $pending['id'], 'status' => 'pending']);
        $this->postJson("/api/v1/purchases/{$pending['id']}/confirm", [], $headers)->assertOk();
        $this->assertDatabaseHas('purchases', ['id' => $pending['id'], 'status' => 'confirmed']);

        $this->putJson('/api/v1/admin/users/1/profile', [
            'name' => 'Administrador Deurstore',
            'phone' => '999888777',
            'notes' => 'Perfil verificado',
        ], $headers)->assertOk();
        $this->assertDatabaseHas('users', ['id' => 1, 'phone' => '999888777']);

        $plain = $this->postJson('/api/v1/integrations/api/tokens', [
            'name' => 'Prueba externa',
            'abilities' => ['read'],
        ], $headers)->assertCreated()->json('data.token');
        $this->getJson('/api/v1/external/status', ['Authorization' => 'Bearer '.$plain])->assertOk()
            ->assertJsonPath('data.service', 'Deurstore API');
        $this->getJson('/api/v1/external/products', ['Authorization' => 'Bearer '.$plain])->assertOk();
    }
}
