<?php
namespace Tests\Feature;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalePriceAuthorizationTest extends TestCase{
 use RefreshDatabase;
 private array$headers;
 protected function setUp():void{parent::setUp();$this->seed(DatabaseSeeder::class);$token=$this->postJson('/api/v1/login',['email'=>'admin@deurstore.pe','password'=>'Deurstore2026!'])->assertOk()->json('data.token');$this->headers=['Authorization'=>'Bearer '.$token];}
 public function test_override_permission_is_available_but_not_granted_by_default():void{$this->assertDatabaseHas('permissions',['slug'=>'sales.override_price']);$permission=DB::table('permissions')->where('slug','sales.override_price')->value('id');$this->assertDatabaseMissing('permission_role',['permission_id'=>$permission]);}
 // Sin permiso, un precio distinto al de catálogo se rechaza con un aviso claro (antes se ignoraba y la venta fallaba por pagos).
 public function test_server_rejects_client_price_without_override_permission():void{$p=DB::table('products')->where('unit_type','unit')->first();$this->postJson('/api/v1/sales',['items'=>[['product_id'=>$p->id,'quantity'=>1,'unit_price'=>0.01]],'payments'=>[['method'=>'cash','amount'=>0.01]]],$this->headers)->assertForbidden();$this->assertDatabaseMissing('sale_details',['product_id'=>$p->id,'unit_price'=>0.01]);$this->postJson('/api/v1/sales',['items'=>[['product_id'=>$p->id,'quantity'=>1,'unit_price'=>(float)$p->sale_price]],'payments'=>[['method'=>'cash','amount'=>(float)$p->sale_price]]],$this->headers)->assertCreated();}
 public function test_authorized_override_requires_reason_and_is_audited():void{$p=DB::table('products')->where('unit_type','unit')->first();$permission=DB::table('permissions')->where('slug','sales.override_price')->value('id');$role=DB::table('role_user')->where('user_id',User::first()->id)->value('role_id');DB::table('permission_role')->insert(['role_id'=>$role,'permission_id'=>$permission]);$price=(float)$p->sale_price+.5;$this->postJson('/api/v1/sales',['items'=>[['product_id'=>$p->id,'quantity'=>1,'unit_price'=>$price]],'payments'=>[['method'=>'cash','amount'=>$price]]],$this->headers)->assertUnprocessable();$sale=$this->postJson('/api/v1/sales',['price_override_reason'=>'Precio acordado con cliente','items'=>[['product_id'=>$p->id,'quantity'=>1,'unit_price'=>$price]],'payments'=>[['method'=>'cash','amount'=>$price]]],$this->headers)->assertCreated()->json('data');$this->assertDatabaseHas('sale_details',['sale_id'=>$sale['id'],'unit_price'=>$price]);$this->assertDatabaseHas('audit_events',['event'=>'sale.price_overridden','entity_id'=>$sale['id']]);}
}
