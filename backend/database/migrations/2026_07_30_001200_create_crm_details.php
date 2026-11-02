<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void{
  Schema::create('crm_addresses',function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->string('entity_type');$t->unsignedBigInteger('entity_id');$t->string('label')->default('Principal');$t->string('address');$t->string('district')->nullable();$t->string('city')->nullable();$t->string('reference')->nullable();$t->boolean('is_default')->default(false);$t->timestamps();$t->index(['entity_type','entity_id']);});
  Schema::create('crm_contacts',function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->string('entity_type');$t->unsignedBigInteger('entity_id');$t->string('name');$t->string('position')->nullable();$t->string('phone')->nullable();$t->string('email')->nullable();$t->boolean('is_primary')->default(false);$t->timestamps();$t->index(['entity_type','entity_id']);});
  Schema::create('crm_notes',function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->foreignId('user_id')->constrained();$t->string('entity_type');$t->unsignedBigInteger('entity_id');$t->text('note');$t->timestamps();$t->index(['entity_type','entity_id']);});
 }
 public function down():void{Schema::dropIfExists('crm_notes');Schema::dropIfExists('crm_contacts');Schema::dropIfExists('crm_addresses');}
};
