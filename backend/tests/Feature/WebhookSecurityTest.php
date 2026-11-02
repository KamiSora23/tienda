<?php
namespace Tests\Feature;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
class WebhookSecurityTest extends TestCase{
 use RefreshDatabase;
 private array$headers;
 protected function setUp():void{parent::setUp();$this->seed(DatabaseSeeder::class);$token=$this->postJson('/api/v1/login',['email'=>'admin@deurstore.pe','password'=>'Deurstore2026!'])->assertOk()->json('data.token');$this->headers=['Authorization'=>'Bearer '.$token];}
 #[DataProvider('unsafeUrls')]
 public function test_webhook_configuration_rejects_unsafe_destination(string$url):void{$this->postJson('/api/v1/integrations/webhook/configure',['config'=>['url'=>$url]],$this->headers)->assertUnprocessable();}
 public static function unsafeUrls():array{return[['http://example.com/hook'],['https://localhost/hook'],['https://127.0.0.1/hook'],['https://10.0.0.1/hook'],['https://169.254.169.254/latest/meta-data'],['https://user:pass@example.com/hook']];}
 public function test_public_https_webhook_can_be_saved_and_tested_without_redirects():void{Http::fake(['https://8.8.8.8/*'=>Http::response(['ok'=>true],200)]);$this->postJson('/api/v1/integrations/webhook/configure',['config'=>['url'=>'https://8.8.8.8/hook','secret'=>'abc']],$this->headers)->assertOk();$this->postJson('/api/v1/integrations/webhook/test',[],$this->headers)->assertOk();Http::assertSent(fn($request)=>$request->url()==='https://8.8.8.8/hook'&&$request->hasHeader('X-Deurstore-Secret','abc'));}
}
