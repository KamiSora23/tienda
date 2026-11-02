<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void{
  Schema::create('user_sessions',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('token_hash',64)->unique();$t->string('ip_address',45)->nullable();$t->text('user_agent')->nullable();$t->timestamp('last_activity_at');$t->timestamp('expires_at')->nullable();$t->timestamps();});
  Schema::create('email_verification_tokens',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('token_hash',64)->unique();$t->timestamp('expires_at');$t->timestamps();});
 }
 public function down():void{Schema::dropIfExists('email_verification_tokens');Schema::dropIfExists('user_sessions');}
};
