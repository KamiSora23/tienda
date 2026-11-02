<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('document_series',function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->foreignId('branch_id')->constrained();$t->enum('document_type',['ticket','receipt','invoice','credit_note','debit_note','purchase']);$t->string('series',8);$t->unsignedBigInteger('current_number')->default(0);$t->boolean('active')->default(true);$t->timestamps();$t->unique(['branch_id','document_type','series']);});
        Schema::create('sale_returns',function(Blueprint $t){$t->id();$t->uuid('uuid')->unique();$t->foreignId('company_id')->constrained();$t->foreignId('branch_id')->constrained();$t->foreignId('sale_id')->constrained();$t->foreignId('user_id')->constrained();$t->string('number');$t->text('reason');$t->decimal('total',14,2);$t->string('status')->default('completed');$t->timestamps();});
        Schema::create('sale_return_details',function(Blueprint $t){$t->id();$t->foreignId('sale_return_id')->constrained()->cascadeOnDelete();$t->foreignId('sale_detail_id')->constrained();$t->foreignId('product_id')->constrained();$t->decimal('quantity',14,3);$t->decimal('unit_price',14,2);$t->decimal('total',14,2);$t->timestamps();});
        Schema::create('purchase_returns',function(Blueprint $t){$t->id();$t->uuid('uuid')->unique();$t->foreignId('company_id')->constrained();$t->foreignId('branch_id')->constrained();$t->foreignId('purchase_id')->constrained();$t->foreignId('user_id')->constrained();$t->string('number');$t->text('reason');$t->decimal('total',14,2);$t->string('status')->default('completed');$t->timestamps();});
        Schema::create('suspended_sales',function(Blueprint $t){$t->id();$t->uuid('uuid')->unique();$t->foreignId('company_id')->constrained();$t->foreignId('branch_id')->constrained();$t->foreignId('user_id')->constrained();$t->string('reference');$t->json('cart');$t->foreignId('customer_id')->nullable()->constrained();$t->string('status')->default('pending');$t->timestamps();});
    }
    public function down():void{foreach(['suspended_sales','purchase_returns','sale_return_details','sale_returns','document_series'] as $table)Schema::dropIfExists($table);}
};
