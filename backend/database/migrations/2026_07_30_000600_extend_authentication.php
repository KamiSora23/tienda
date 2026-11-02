<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void{Schema::table('users',function(Blueprint $t){$t->boolean('two_factor_enabled')->default(false);$t->string('two_factor_code',64)->nullable();$t->timestamp('two_factor_expires_at')->nullable();$t->unsignedTinyInteger('failed_login_attempts')->default(0);$t->timestamp('locked_until')->nullable();$t->foreignId('cash_session_id')->nullable()->after('branch_id');});}
 public function down():void{Schema::table('users',function(Blueprint $t){$t->dropColumn(['two_factor_enabled','two_factor_code','two_factor_expires_at','failed_login_attempts','locked_until','cash_session_id']);});}
};
