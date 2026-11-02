<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private function headers(): array
    {
        $this->seed(DatabaseSeeder::class);
        $token=$this->postJson('/api/v1/login',['email'=>'admin@deurstore.pe','password'=>'Deurstore2026!'])
            ->assertOk()->json('data.token');
        return ['Authorization'=>'Bearer '.$token];
    }

    public function test_product_rejects_a_category_that_is_not_configured(): void
    {
        $this->postJson('/api/v1/products',[
            'sku'=>'TEST-INVALID','name'=>'Producto inválido','category'=>'Categoría inventada',
            'unit_type'=>'unit','sale_price'=>10,'average_cost'=>5,'minimum_price'=>7,
            'minimum_stock'=>1,'stock'=>5,
        ],$this->headers())->assertUnprocessable()
            ->assertJsonValidationErrors('category');
    }

    public function test_product_accepts_an_active_configured_category(): void
    {
        $this->postJson('/api/v1/products',[
            'sku'=>'TEST-VALID','name'=>'Producto correcto','category'=>'Abarrotes',
            'unit_type'=>'unit','sale_price'=>10,'average_cost'=>5,'minimum_price'=>7,
            'minimum_stock'=>1,'stock'=>5,
        ],$this->headers())->assertOk();
        $this->assertDatabaseHas('products',['sku'=>'TEST-VALID','category'=>'Abarrotes']);
    }

    public function test_category_can_be_created_without_manually_writing_a_slug(): void
    {
        $headers=$this->headers();
        $this->postJson('/api/v1/admin/categories',[
            'name'=>'Cuidado personal','sort_order'=>5,'status'=>'active',
        ],$headers)->assertCreated();
        $this->assertDatabaseHas('categories',['name'=>'Cuidado personal','slug'=>'cuidado-personal','status'=>'active']);
    }

    public function test_product_accepts_an_optional_generic_brand(): void
    {
        $this->postJson('/api/v1/products',[
            'sku'=>'TEST-GENERIC','name'=>'Azúcar a granel','category'=>'Abarrotes','brand'=>'',
            'unit_type'=>'kg','sale_price'=>4.8,'average_cost'=>3.2,'minimum_price'=>3.9,
            'minimum_stock'=>5,'stock'=>10,
        ],$this->headers())->assertOk();

        $this->assertDatabaseHas('products',['sku'=>'TEST-GENERIC','brand'=>null]);
    }

    public function test_product_accepts_an_active_configured_brand(): void
    {
        $headers=$this->headers();
        $this->postJson('/api/v1/admin/brands',['name'=>'Costeño'],$headers)->assertCreated();

        $this->postJson('/api/v1/products',[
            'sku'=>'TEST-BRAND','name'=>'Arroz con marca','category'=>'Abarrotes','brand'=>'Costeño',
            'unit_type'=>'kg','sale_price'=>6,'average_cost'=>4,'minimum_price'=>5,
            'minimum_stock'=>5,'stock'=>10,
        ],$headers)->assertOk();

        $this->assertDatabaseHas('products',['sku'=>'TEST-BRAND','brand'=>'Costeño']);
    }

    public function test_product_rejects_an_unconfigured_brand(): void
    {
        $this->postJson('/api/v1/products',[
            'sku'=>'TEST-FAKE-BRAND','name'=>'Producto inválido','category'=>'Abarrotes','brand'=>'Marca inventada',
            'unit_type'=>'unit','sale_price'=>10,'average_cost'=>5,'minimum_price'=>7,
            'minimum_stock'=>1,'stock'=>5,
        ],$this->headers())->assertUnprocessable()->assertJsonValidationErrors('brand');
    }

    public function test_brand_defaults_to_active_and_cannot_be_duplicated_for_the_company(): void
    {
        $headers=$this->headers();
        $this->postJson('/api/v1/admin/brands',['name'=>'  Nueva Marca  '],$headers)->assertCreated();
        $this->assertDatabaseHas('brands',['name'=>'Nueva Marca','status'=>'active']);
        $this->postJson('/api/v1/admin/brands',['name'=>'Nueva Marca'],$headers)
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }
}
