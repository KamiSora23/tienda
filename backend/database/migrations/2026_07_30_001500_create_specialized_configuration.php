<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void{
  Schema::create('payment_methods',function(Blueprint$t){$t->id();$t->foreignId('company_id')->constrained();$t->string('name');$t->string('code');$t->boolean('requires_reference')->default(false);$t->boolean('affects_cash')->default(false);$t->string('status')->default('active');$t->timestamps();$t->unique(['company_id','code']);});
  Schema::create('tax_rates',function(Blueprint$t){$t->id();$t->foreignId('company_id')->constrained();$t->string('name');$t->string('code');$t->decimal('rate',6,3);$t->boolean('included')->default(true);$t->string('status')->default('active');$t->timestamps();});
  Schema::create('printers',function(Blueprint$t){$t->id();$t->foreignId('company_id')->constrained();$t->foreignId('branch_id')->constrained();$t->string('name');$t->enum('type',['ticket','label','document']);$t->string('connection')->default('browser');$t->string('paper_size')->nullable();$t->json('settings')->nullable();$t->string('status')->default('active');$t->timestamps();});
  Schema::create('cash_registers',function(Blueprint$t){$t->id();$t->foreignId('company_id')->constrained();$t->foreignId('branch_id')->constrained();$t->string('code');$t->string('name');$t->string('status')->default('active');$t->timestamps();$t->unique(['branch_id','code']);});
  Schema::create('warehouse_locations',function(Blueprint$t){$t->id();$t->foreignId('company_id')->constrained();$t->foreignId('warehouse_id')->constrained()->cascadeOnDelete();$t->string('code');$t->string('name');$t->string('zone')->nullable();$t->string('aisle')->nullable();$t->string('rack')->nullable();$t->string('level')->nullable();$t->string('status')->default('active');$t->timestamps();$t->unique(['warehouse_id','code']);});
 }
 public function down():void{foreach(['warehouse_locations','cash_registers','printers','tax_rates','payment_methods']as$t)Schema::dropIfExists($t);}
};
