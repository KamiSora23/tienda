<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up():void{
  Schema::table('web_orders',function(Blueprint$t){$t->string('payment_method')->default('cash_on_delivery')->after('total');$t->string('payment_status')->default('pending')->after('payment_method');$t->foreignId('sale_id')->nullable()->after('payment_status')->constrained();});
  Schema::create('web_order_status_history',function(Blueprint$t){$t->id();$t->foreignId('web_order_id')->constrained()->cascadeOnDelete();$t->foreignId('user_id')->nullable()->constrained();$t->string('previous_status')->nullable();$t->string('new_status');$t->text('notes')->nullable();$t->timestamps();});
 }
 public function down():void{Schema::dropIfExists('web_order_status_history');Schema::table('web_orders',fn(Blueprint$t)=>$t->dropConstrainedForeignId('sale_id'));Schema::table('web_orders',fn(Blueprint$t)=>$t->dropColumn(['payment_method','payment_status']));}
};
