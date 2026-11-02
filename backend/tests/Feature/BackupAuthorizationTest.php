<?php
namespace Tests\Feature;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackupAuthorizationTest extends TestCase{
 use RefreshDatabase;
 private function login():array{return['Authorization'=>'Bearer '.$this->loginToken('admin@deurstore.pe','Deurstore2026!')];}
 public function test_company_admin_cannot_list_or_download_global_backups():void{$this->seed(DatabaseSeeder::class);$headers=$this->login();$id=DB::table('backup_records')->insertGetId(['company_id'=>null,'user_id'=>User::first()->id,'filename'=>'global.sql','path'=>'backups/global.sql','size'=>1,'type'=>'manual','status'=>'completed','created_at'=>now(),'updated_at'=>now()]);$this->getJson('/api/v1/backups',$headers)->assertForbidden();$this->getJson("/api/v1/backups/{$id}/download",$headers)->assertForbidden();}
 public function test_platform_admin_can_list_global_backup_metadata():void{$this->seed(DatabaseSeeder::class);User::first()->update(['role'=>'platform_admin']);$headers=$this->login();DB::table('backup_records')->insert(['company_id'=>null,'user_id'=>User::first()->id,'filename'=>'global.sql','path'=>'backups/global.sql','size'=>1,'type'=>'manual','status'=>'completed','created_at'=>now(),'updated_at'=>now()]);$this->getJson('/api/v1/backups',$headers)->assertOk()->assertJsonPath('data.0.filename','global.sql');}
}
