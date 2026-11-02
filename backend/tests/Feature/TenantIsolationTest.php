<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $token = $this->postJson('/api/v1/login', [
            'email' => 'admin@deurstore.pe', 'password' => 'Deurstore2026!',
        ])->assertOk()->json('data.token');
        $this->headers = ['Authorization' => 'Bearer '.$token];
    }

    private function foreignInventory(): array
    {
        $company = DB::table('companies')->insertGetId(['uuid'=>Str::uuid(),'business_name'=>'FOREIGN SAC','trade_name'=>'Foreign','ruc'=>'20222222222','currency'=>'PEN','timezone'=>'America/Lima','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        $branch = DB::table('branches')->insertGetId(['company_id'=>$company,'code'=>'FOR-01','name'=>'Foreign','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        $warehouse = DB::table('warehouses')->insertGetId(['company_id'=>$company,'branch_id'=>$branch,'code'=>'FOR-W','name'=>'Foreign','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        $product = DB::table('products')->insertGetId(['uuid'=>Str::uuid(),'company_id'=>$company,'sku'=>'FOREIGN-1','name'=>'Ajeno','category'=>'General','unit_type'=>'unit','allow_decimal_quantity'=>0,'average_cost'=>1,'sale_price'=>2,'minimum_price'=>1,'minimum_stock'=>0,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        DB::table('stocks')->insert(['company_id'=>$company,'branch_id'=>$branch,'warehouse_id'=>$warehouse,'product_id'=>$product,'quantity'=>9,'created_at'=>now(),'updated_at'=>now()]);
        return [$warehouse, $product];
    }

    public function test_inventory_writes_reject_foreign_company_resources(): void
    {
        [$warehouse, $product] = $this->foreignInventory();

        $this->postJson('/api/v1/inventory/lots', ['warehouse_id'=>$warehouse,'product_id'=>$product,'lot_number'=>'X','quantity'=>1,'unit_cost'=>1], $this->headers)->assertNotFound();
        $this->postJson('/api/v1/inventory/adjustments', ['warehouse_id'=>$warehouse,'reason'=>'X','items'=>[['product_id'=>$product,'new_quantity'=>1]]], $this->headers)->assertNotFound();

        $this->assertSame(9.0, (float) DB::table('stocks')->where(['warehouse_id'=>$warehouse,'product_id'=>$product])->value('quantity'));
        $this->assertDatabaseMissing('lots', ['warehouse_id'=>$warehouse,'product_id'=>$product]);
    }

    public function test_transfer_rejects_a_foreign_destination_warehouse(): void
    {
        [$foreignWarehouse] = $this->foreignInventory();
        $localWarehouse = DB::table('warehouses')->where('company_id', User::where('email','admin@deurstore.pe')->value('company_id'))->first();
        $localProduct = DB::table('products')->where('company_id', $localWarehouse->company_id)->first();
        $before = (float) DB::table('stocks')->where(['warehouse_id'=>$localWarehouse->id,'product_id'=>$localProduct->id])->value('quantity');

        $this->postJson('/api/v1/inventory/transfers', [
            'source_warehouse_id'=>$localWarehouse->id, 'destination_warehouse_id'=>$foreignWarehouse,
            'items'=>[['product_id'=>$localProduct->id,'quantity'=>1]],
        ], $this->headers)->assertNotFound();

        $this->assertSame($before, (float) DB::table('stocks')->where(['warehouse_id'=>$localWarehouse->id,'product_id'=>$localProduct->id])->value('quantity'));
    }
}
