<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration{public function up():void{DB::table('permissions')->insertOrIgnore(['module'=>'sales','action'=>'override_price','slug'=>'sales.override_price','created_at'=>now(),'updated_at'=>now()]);}public function down():void{DB::table('permissions')->where('slug','sales.override_price')->delete();}};
