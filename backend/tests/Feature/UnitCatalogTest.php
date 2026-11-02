<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnitCatalogTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $token = $this->postJson('/api/v1/login', ['email' => 'admin@deurstore.pe', 'password' => 'Deurstore2026!'])->assertOk()->json('data.token');
        $this->headers = ['Authorization' => 'Bearer '.$token];
    }

    private function units(): \Illuminate\Support\Collection
    {
        return collect($this->getJson('/api/v1/units', $this->headers)->assertOk()->json('data'))->keyBy('code');
    }

    private function product(string $unit, ?int $id = null): \Illuminate\Testing\TestResponse
    {
        $payload = ['sku' => 'U-'.uniqid(), 'name' => 'Producto '.$unit, 'category' => DB::table('categories')->value('name'), 'unit_type' => $unit, 'sale_price' => 1];
        return $id ? $this->putJson("/api/v1/products/{$id}", $payload, $this->headers) : $this->postJson('/api/v1/products', $payload, $this->headers);
    }

    public function test_company_gets_the_standard_catalog_with_usage_and_defaults(): void
    {
        $units = $this->units();

        $this->assertCount(15, $units);
        $this->assertSame('active', $units['unit']['status']);
        $this->assertSame('active', $units['kg']['status']);
        $this->assertSame('inactive', $units['m2']['status']);
        $this->assertSame('area', $units['m2']['family']);
        $this->assertSame('count', $units['unit']['family']);
        $this->assertFalse($units['pair']['allows_decimals']);
        $this->assertGreaterThan(0, $units['kg']['products']);
        // Las filas antiguas «Unidad» y «Kilogramo» se enlazan, no se duplican.
        $this->assertSame(15, DB::table('units')->count());
    }

    public function test_only_active_units_can_be_used_for_new_products(): void
    {
        $this->product('m2')->assertUnprocessable()->assertJsonValidationErrors('unit_type');

        $this->putJson('/api/v1/units/m2', ['status' => 'active'], $this->headers)->assertOk();
        $this->product('m2')->assertOk();
        $this->assertSame(1, $this->units()['m2']['products']);
    }

    public function test_deactivating_a_unit_keeps_existing_products_working(): void
    {
        $id = $this->product('l')->assertOk()->json('data.id');
        $this->putJson('/api/v1/units/l', ['status' => 'inactive'], $this->headers)->assertOk();

        $this->product('l', $id)->assertOk();
        $this->product('l')->assertUnprocessable();
    }

    public function test_the_last_active_unit_cannot_be_deactivated(): void
    {
        foreach ($this->units()->where('status', 'active')->keys()->slice(1) as $code) {
            $this->putJson("/api/v1/units/{$code}", ['status' => 'inactive'], $this->headers)->assertOk();
        }
        $last = $this->units()->firstWhere('status', 'active')['code'];

        $this->putJson("/api/v1/units/{$last}", ['status' => 'inactive'], $this->headers)->assertUnprocessable();
        $this->putJson('/api/v1/units/furlong', ['status' => 'active'], $this->headers)->assertNotFound();
    }

    public function test_free_form_unit_crud_is_no_longer_exposed(): void
    {
        $this->postJson('/api/v1/admin/units', ['name' => 'Inventada', 'symbol' => 'inv', 'measure_type' => 'unit'], $this->headers)->assertNotFound();
    }
}
