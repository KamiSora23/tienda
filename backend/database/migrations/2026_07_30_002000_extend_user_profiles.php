<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration{
 public function up():void{Schema::table('users',function(Blueprint $t){$t->longText('avatar')->nullable();$t->string('phone',30)->nullable();$t->text('notes')->nullable();});}
 public function down():void{Schema::table('users',fn(Blueprint $t)=>$t->dropColumn(['avatar','phone','notes']));}
};
