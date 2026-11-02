<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_product_rows_include_category_brand_and_warehouse(): void
    {
        $this->seed(DatabaseSeeder::class);
        $token=$this->postJson('/api/v1/login',[
            'email'=>'admin@deurstore.pe','password'=>'Deurstore2026!',
        ])->assertOk()->json('data.token');
        $headers=['Authorization'=>'Bearer '.$token];
        $product=DB::table('products')->first();
        $warehouse=DB::table('warehouses')->first();
        DB::table('products')->where('id',$product->id)->update(['brand'=>'Costeño']);

        $this->postJson('/api/v1/inventory/adjustments',[
            'warehouse_id'=>$warehouse->id,'reason'=>'Prueba de catálogo',
            'items'=>[['product_id'=>$product->id,'new_quantity'=>20]],
        ],$headers)->assertCreated();
        $this->postJson('/api/v1/inventory/lots',[
            'warehouse_id'=>$warehouse->id,'product_id'=>$product->id,'lot_number'=>'LOTE-CAT',
            'quantity'=>2,'unit_cost'=>3.5,
        ],$headers)->assertCreated();

        $this->getJson('/api/v1/inventory/kardex',$headers)->assertOk()->assertJsonPath('data.0.category','Abarrotes')
            ->assertJsonPath('data.0.brand','Costeño')->assertJsonPath('data.0.warehouse_id',$warehouse->id)
            ->assertJsonPath('data.0.warehouse_name',$warehouse->name);
        $this->getJson('/api/v1/inventory/lots',$headers)->assertOk()->assertJsonPath('data.0.category','Abarrotes')
            ->assertJsonPath('data.0.brand','Costeño')->assertJsonPath('data.0.warehouse_id',$warehouse->id);

        DB::table('stocks')->where(['warehouse_id'=>$warehouse->id,'product_id'=>$product->id])->update(['quantity'=>0]);
        $this->getJson('/api/v1/inventory/alerts',$headers)->assertOk()
            ->assertJsonPath('data.low_stock.0.category','Abarrotes')
            ->assertJsonPath('data.low_stock.0.brand','Costeño')
            ->assertJsonPath('data.low_stock.0.warehouse_id',$warehouse->id)
            ->assertJsonPath('data.low_stock.0.warehouse_name',$warehouse->name);
    }
}
