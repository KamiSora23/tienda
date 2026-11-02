<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void{
  Schema::create('expense_categories',function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->string('name');$t->string('color')->nullable();$t->string('status')->default('active');$t->timestamps();$t->unique(['company_id','name']);});
  Schema::create('utility_meters',function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->foreignId('branch_id')->constrained();$t->string('name');$t->enum('type',['electricity','water','gas','other']);$t->string('unit');$t->string('identifier')->nullable();$t->string('status')->default('active');$t->timestamps();});
  Schema::create('meter_readings',function(Blueprint $t){$t->id();$t->foreignId('utility_meter_id')->constrained()->cascadeOnDelete();$t->foreignId('user_id')->constrained();$t->date('reading_date');$t->decimal('value',14,3);$t->decimal('consumption',14,3)->default(0);$t->decimal('amount',14,2)->nullable();$t->timestamps();$t->unique(['utility_meter_id','reading_date']);});
 }
 public function down():void{Schema::dropIfExists('meter_readings');Schema::dropIfExists('utility_meters');Schema::dropIfExists('expense_categories');}
};
