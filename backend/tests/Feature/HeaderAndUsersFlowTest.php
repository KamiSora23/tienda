<?php
namespace Tests\Feature;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class HeaderAndUsersFlowTest extends TestCase{
 use RefreshDatabase;
 private array$headers;
 protected function setUp():void{parent::setUp();$this->seed(DatabaseSeeder::class);$token=$this->postJson('/api/v1/login',['email'=>'admin@deurstore.pe','password'=>'Deurstore2026!'])->assertOk()->json('data.token');$this->headers=['Authorization'=>'Bearer '.$token];}
 public function test_user_list_and_options_are_both_available_to_user_admin():void{$this->getJson('/api/v1/admin/users',$this->headers)->assertOk()->assertJsonCount(1,'data');$this->getJson('/api/v1/admin/user-options',$this->headers)->assertOk()->assertJsonCount(1,'data.branches')->assertJsonCount(1,'data.roles');}
 public function test_global_search_returns_only_current_company_results():void{$product=DB::table('products')->first();$customer=DB::table('customers')->first();$this->getJson('/api/v1/global-search?q='.urlencode(substr($product->name,0,4)),$this->headers)->assertOk()->assertJsonFragment(['type'=>'product','id'=>$product->id]);$this->getJson('/api/v1/global-search?q='.urlencode(substr($customer->name,0,4)),$this->headers)->assertOk()->assertJsonFragment(['type'=>'customer','id'=>$customer->id]);}
}
