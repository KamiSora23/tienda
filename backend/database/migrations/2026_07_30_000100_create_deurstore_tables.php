<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->string('business_name'); $t->string('trade_name');
            $t->string('ruc', 11)->unique(); $t->string('email')->nullable(); $t->string('phone')->nullable();
            $t->string('address')->nullable(); $t->string('currency', 3)->default('PEN'); $t->string('timezone')->default('America/Lima');
            $t->string('status')->default('active'); $t->timestamps();
        });
        Schema::create('branches', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnUpdate(); $t->string('code');
            $t->string('name'); $t->string('address')->nullable(); $t->string('phone')->nullable(); $t->string('status')->default('active');
            $t->timestamps(); $t->unique(['company_id','code']);
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('branch_id')->constrained();
            $t->string('code'); $t->string('name'); $t->string('status')->default('active'); $t->timestamps();
            $t->unique(['branch_id','code']);
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('company_id')->constrained(); $t->string('sku');
            $t->string('barcode')->nullable(); $t->string('name'); $t->string('category')->default('General'); $t->string('brand')->nullable();
            $t->enum('unit_type',['unit','kg'])->default('unit'); $t->boolean('allow_decimal_quantity')->default(false);
            $t->decimal('average_cost',14,4)->default(0); $t->decimal('sale_price',14,2); $t->decimal('minimum_price',14,2)->default(0);
            $t->decimal('minimum_stock',14,3)->default(0); $t->string('status')->default('active'); $t->timestamps();
            $t->unique(['company_id','sku']); $t->unique(['company_id','barcode']);
        });
        Schema::create('stocks', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('branch_id')->constrained();
            $t->foreignId('warehouse_id')->constrained(); $t->foreignId('product_id')->constrained(); $t->decimal('quantity',14,3)->default(0);
            $t->timestamps(); $t->unique(['warehouse_id','product_id']);
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->string('document_type')->nullable(); $t->string('document_number')->nullable();
            $t->string('name'); $t->string('phone')->nullable(); $t->string('email')->nullable(); $t->string('address')->nullable();
            $t->string('status')->default('active'); $t->timestamps(); $t->index(['company_id','name']);
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->string('document_type')->nullable(); $t->string('document_number')->nullable();
            $t->string('name'); $t->string('contact')->nullable(); $t->string('phone')->nullable(); $t->string('email')->nullable();
            $t->string('address')->nullable(); $t->string('status')->default('active'); $t->timestamps();
        });
        Schema::create('purchases', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('company_id')->constrained(); $t->foreignId('branch_id')->constrained();
            $t->foreignId('supplier_id')->nullable()->constrained(); $t->foreignId('user_id')->constrained(); $t->string('number');
            $t->string('document_type')->default('quick'); $t->decimal('subtotal',14,2); $t->decimal('tax',14,2)->default(0);
            $t->decimal('freight',14,2)->default(0); $t->decimal('perception',14,2)->default(0); $t->decimal('other_costs',14,2)->default(0);
            $t->decimal('total',14,2); $t->string('status')->default('confirmed'); $t->timestamps(); $t->unique(['company_id','number']);
        });
        Schema::create('purchase_details', function (Blueprint $t) {
            $t->id(); $t->foreignId('purchase_id')->constrained(); $t->foreignId('product_id')->constrained();
            $t->decimal('quantity',14,3); $t->decimal('unit_cost',14,4); $t->decimal('effective_unit_cost',14,4); $t->decimal('total',14,2); $t->timestamps();
        });
        Schema::create('sales', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('company_id')->constrained(); $t->foreignId('branch_id')->constrained();
            $t->foreignId('customer_id')->nullable()->constrained(); $t->foreignId('user_id')->constrained(); $t->string('number');
            $t->string('document_type')->default('ticket'); $t->decimal('subtotal',14,2); $t->decimal('discount',14,2)->default(0);
            $t->decimal('tax',14,2)->default(0); $t->decimal('total',14,2); $t->string('status')->default('paid'); $t->timestamps();
            $t->unique(['company_id','number']);
        });
        Schema::create('sale_details', function (Blueprint $t) {
            $t->id(); $t->foreignId('sale_id')->constrained(); $t->foreignId('product_id')->constrained();
            $t->string('product_name'); $t->decimal('quantity',14,3); $t->decimal('unit_price',14,2); $t->decimal('historical_unit_cost',14,4);
            $t->decimal('discount',14,2)->default(0); $t->decimal('total',14,2); $t->timestamps();
        });
        Schema::create('sale_payments', function (Blueprint $t) {
            $t->id(); $t->foreignId('sale_id')->constrained(); $t->enum('method',['cash','yape','card','transfer']);
            $t->decimal('amount',14,2); $t->string('reference')->nullable(); $t->timestamps();
        });
        Schema::create('cash_sessions', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('branch_id')->constrained(); $t->foreignId('user_id')->constrained();
            $t->decimal('opening_amount',14,2); $t->decimal('expected_amount',14,2)->nullable(); $t->decimal('counted_amount',14,2)->nullable();
            $t->decimal('difference',14,2)->nullable(); $t->string('status')->default('open'); $t->timestamp('opened_at'); $t->timestamp('closed_at')->nullable(); $t->timestamps();
        });
        Schema::create('cash_movements', function (Blueprint $t) {
            $t->id(); $t->foreignId('cash_session_id')->constrained(); $t->enum('type',['opening','sale','income','expense','withdrawal','closing']);
            $t->decimal('amount',14,2); $t->string('description'); $t->string('reference')->nullable(); $t->foreignId('user_id')->constrained(); $t->timestamps();
        });
        Schema::create('expenses', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained(); $t->foreignId('branch_id')->constrained(); $t->foreignId('user_id')->constrained();
            $t->string('category'); $t->string('description'); $t->decimal('amount',14,2); $t->date('expense_date'); $t->string('status')->default('active'); $t->timestamps();
        });
        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('company_id')->constrained(); $t->foreignId('branch_id')->constrained();
            $t->foreignId('warehouse_id')->constrained(); $t->foreignId('product_id')->constrained(); $t->foreignId('user_id')->constrained();
            $t->string('type'); $t->decimal('quantity',14,3); $t->decimal('historical_unit_cost',14,4);
            $t->decimal('previous_stock',14,3); $t->decimal('new_stock',14,3); $t->string('document_type')->nullable();
            $t->unsignedBigInteger('document_id')->nullable(); $t->string('document_number')->nullable(); $t->timestamps();
            $t->index(['company_id','product_id','created_at']);
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique(); $t->foreignId('company_id')->constrained(); $t->foreignId('user_id')->nullable()->constrained();
            $t->string('event'); $t->string('entity'); $t->unsignedBigInteger('entity_id')->nullable(); $t->json('before')->nullable();
            $t->json('after')->nullable(); $t->ipAddress('ip')->nullable(); $t->timestamps();
        });
    }
    public function down(): void {
        foreach (['audit_events','inventory_movements','expenses','cash_movements','cash_sessions','sale_payments','sale_details','sales','purchase_details','purchases','suppliers','customers','stocks','products','warehouses','branches','companies'] as $table) Schema::dropIfExists($table);
    }
};
