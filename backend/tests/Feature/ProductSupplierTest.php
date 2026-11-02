<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductSupplierTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->seed(DatabaseSeeder::class);
        $token=$this->postJson('/api/v1/login',['email'=>'admin@deurstore.pe','password'=>'Deurstore2026!'])->assertOk()->json('data.token');
        return [['Authorization'=>'Bearer '.$token],DB::table('products')->first(),DB::table('suppliers')->first()];
    }

    private function purchase(array $headers, object $product, ?int $supplierId, string $status='confirmed'): int
    {
        $response=$this->postJson('/api/v1/'.($status==='pending'?'purchases/pending':'purchases'),[
            'supplier_id'=>$supplierId,'document_type'=>'invoice','invoice_number'=>'F-'.uniqid(),
            'items'=>[['product_id'=>$product->id,'quantity'=>2,'unit_cost'=>4.25]],
            'tax'=>0,'freight'=>0,'perception'=>0,'other_costs'=>0,
        ],$headers)->assertStatus($status==='pending'?201:201);
        return (int)$response->json('data.id');
    }

    public function test_pending_purchase_does_not_associate_until_inventory_is_confirmed(): void
    {
        [$headers,$product,$supplier]=$this->context();
        $purchase=$this->purchase($headers,$product,$supplier->id,'pending');
        $this->assertDatabaseMissing('product_suppliers',['product_id'=>$product->id,'supplier_id'=>$supplier->id]);

        $this->postJson("/api/v1/purchases/{$purchase}/confirm",[],$headers)->assertOk();
        $this->assertDatabaseHas('product_suppliers',[
            'product_id'=>$product->id,'supplier_id'=>$supplier->id,'last_cost'=>4.25,'is_primary'=>1,'status'=>'active',
        ]);
    }

    public function test_direct_purchase_updates_relation_without_replacing_existing_primary(): void
    {
        [$headers,$product,$first]=$this->context();
        $second=DB::table('suppliers')->where('id','!=',$first->id)->first();
        $this->purchase($headers,$product,$first->id);
        $this->purchase($headers,$product,$second->id);

        $this->assertDatabaseHas('product_suppliers',['product_id'=>$product->id,'supplier_id'=>$first->id,'is_primary'=>1]);
        $this->assertDatabaseHas('product_suppliers',['product_id'=>$product->id,'supplier_id'=>$second->id,'is_primary'=>0]);
    }

    public function test_purchase_without_supplier_does_not_create_relation(): void
    {
        [$headers,$product]=$this->context();
        $this->purchase($headers,$product,null);
        $this->assertDatabaseCount('product_suppliers',0);
    }

    public function test_product_supplier_api_manages_one_primary_and_both_details_agree(): void
    {
        [$headers,$product,$first]=$this->context();
        $second=DB::table('suppliers')->where('id','!=',$first->id)->first();
        $this->postJson("/api/v1/products/{$product->id}/suppliers",[
            'supplier_id'=>$first->id,'supplier_sku'=>'PROV-1','minimum_order_quantity'=>5,'lead_time_days'=>3,
        ],$headers)->assertCreated();
        $this->postJson("/api/v1/products/{$product->id}/suppliers",['supplier_id'=>$second->id],$headers)->assertCreated();
        $this->postJson("/api/v1/products/{$product->id}/suppliers/{$second->id}/primary",[],$headers)->assertOk();

        $this->assertDatabaseHas('product_suppliers',['product_id'=>$product->id,'supplier_id'=>$first->id,'is_primary'=>0]);
        $this->assertDatabaseHas('product_suppliers',['product_id'=>$product->id,'supplier_id'=>$second->id,'is_primary'=>1]);
        $this->getJson("/api/v1/products/{$product->id}/detail",$headers)->assertOk()
            ->assertJsonPath('data.suppliers.0.product_id',$product->id);
        $this->getJson("/api/v1/crm/suppliers/{$second->id}",$headers)->assertOk()
            ->assertJsonPath('data.products.0.product_id',$product->id);
    }

    public function test_supplier_from_another_company_is_rejected(): void
    {
        [$headers,$product]=$this->context();
        $company=DB::table('companies')->insertGetId(['uuid'=>\Illuminate\Support\Str::uuid(),'business_name'=>'Otra','trade_name'=>'Otra','ruc'=>'20999999999','currency'=>'PEN','timezone'=>'America/Lima','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        $supplier=DB::table('suppliers')->insertGetId(['company_id'=>$company,'name'=>'Ajeno','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        $this->postJson("/api/v1/products/{$product->id}/suppliers",['supplier_id'=>$supplier],$headers)->assertUnprocessable();
    }
}
