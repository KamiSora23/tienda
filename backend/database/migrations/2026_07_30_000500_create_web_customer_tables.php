<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void{Schema::create('web_customers',function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->foreignId('customer_id')->nullable()->constrained();$t->string('name');$t->string('email');$t->string('phone')->nullable();$t->string('password');$t->string('api_token',64)->nullable()->unique();$t->timestamp('email_verified_at')->nullable();$t->string('status')->default('active');$t->timestamps();$t->unique(['company_id','email']);});Schema::create('web_customer_addresses',function(Blueprint $t){$t->id();$t->foreignId('web_customer_id')->constrained()->cascadeOnDelete();$t->string('label')->default('Principal');$t->string('address');$t->string('district')->nullable();$t->string('city')->default('Piura');$t->string('reference')->nullable();$t->boolean('is_default')->default(false);$t->timestamps();});Schema::create('web_favorites',function(Blueprint $t){$t->foreignId('web_customer_id')->constrained()->cascadeOnDelete();$t->foreignId('product_id')->constrained()->cascadeOnDelete();$t->timestamps();$t->primary(['web_customer_id','product_id']);});}
 public function down():void{Schema::dropIfExists('web_favorites');Schema::dropIfExists('web_customer_addresses');Schema::dropIfExists('web_customers');}
};
