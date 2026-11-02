<?php
namespace Tests\Feature;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class PlatformAdminCommandTest extends TestCase{use RefreshDatabase;public function test_existing_user_can_be_explicitly_promoted():void{$this->seed(DatabaseSeeder::class);$this->artisan('user:promote-platform',['email'=>'admin@deurstore.pe'])->assertSuccessful();$this->assertSame('platform_admin',User::where('email','admin@deurstore.pe')->value('role'));}}
