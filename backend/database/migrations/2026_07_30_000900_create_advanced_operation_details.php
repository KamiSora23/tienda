<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void{
  Schema::create('purchase_return_details',function(Blueprint $t){$t->id();$t->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();$t->foreignId('purchase_detail_id')->constrained();$t->foreignId('product_id')->constrained();$t->decimal('quantity',14,3);$t->decimal('unit_cost',14,4);$t->decimal('total',14,2);$t->timestamps();});
  Schema::create('promotion_usages',function(Blueprint $t){$t->id();$t->foreignId('promotion_id')->constrained();$t->foreignId('sale_id')->constrained();$t->foreignId('customer_id')->nullable()->constrained();$t->decimal('discount',14,2);$t->timestamps();});
 }
 public function down():void{Schema::dropIfExists('promotion_usages');Schema::dropIfExists('purchase_return_details');}
};
