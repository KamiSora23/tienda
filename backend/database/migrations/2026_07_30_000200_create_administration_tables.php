<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('roles', function(Blueprint $t){$t->id();$t->foreignId('company_id')->nullable()->constrained();$t->string('name');$t->string('slug');$t->string('description')->nullable();$t->boolean('system')->default(false);$t->timestamps();$t->unique(['company_id','slug']);});
        Schema::create('permissions', function(Blueprint $t){$t->id();$t->string('module');$t->string('action');$t->string('slug')->unique();$t->timestamps();});
        Schema::create('permission_role', function(Blueprint $t){$t->foreignId('role_id')->constrained()->cascadeOnDelete();$t->foreignId('permission_id')->constrained()->cascadeOnDelete();$t->primary(['role_id','permission_id']);});
        Schema::create('role_user', function(Blueprint $t){$t->foreignId('role_id')->constrained()->cascadeOnDelete();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->primary(['role_id','user_id']);});
        Schema::create('categories', function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();$t->string('name');$t->string('slug');$t->integer('sort_order')->default(0);$t->string('status')->default('active');$t->timestamps();$t->unique(['company_id','slug']);});
        Schema::create('brands', function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->string('name');$t->string('logo')->nullable();$t->string('status')->default('active');$t->timestamps();});
        Schema::create('units', function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->string('name');$t->string('symbol',12);$t->enum('measure_type',['unit','weight','volume','length']);$t->boolean('allows_decimals')->default(false);$t->timestamps();});
        Schema::create('promotions', function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->string('name');$t->enum('type',['percentage','fixed','buy_x_get_y','combo']);$t->decimal('value',14,2)->default(0);$t->json('rules')->nullable();$t->timestamp('starts_at');$t->timestamp('ends_at');$t->string('status')->default('active');$t->timestamps();});
        Schema::create('promotion_product', function(Blueprint $t){$t->foreignId('promotion_id')->constrained()->cascadeOnDelete();$t->foreignId('product_id')->constrained()->cascadeOnDelete();$t->primary(['promotion_id','product_id']);});
        Schema::create('settings', function(Blueprint $t){$t->id();$t->foreignId('company_id')->nullable()->constrained();$t->string('group');$t->string('key');$t->longText('value')->nullable();$t->string('type')->default('string');$t->timestamps();$t->unique(['company_id','group','key']);});
        Schema::create('notifications', function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->foreignId('user_id')->nullable()->constrained();$t->string('type');$t->string('title');$t->text('message');$t->string('link')->nullable();$t->timestamp('read_at')->nullable();$t->timestamps();});
        Schema::create('documents', function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->foreignId('user_id')->constrained();$t->string('name');$t->string('path');$t->string('mime_type');$t->unsignedBigInteger('size');$t->string('entity_type')->nullable();$t->unsignedBigInteger('entity_id')->nullable();$t->timestamps();});
        Schema::create('web_orders', function(Blueprint $t){$t->id();$t->uuid('uuid')->unique();$t->foreignId('company_id')->constrained();$t->foreignId('customer_id')->nullable()->constrained();$t->string('number');$t->string('customer_name');$t->string('customer_phone')->nullable();$t->json('items');$t->decimal('total',14,2);$t->enum('status',['pending','preparing','packed','ready','delivered','cancelled'])->default('pending');$t->text('delivery_address')->nullable();$t->timestamps();});
        Schema::create('integration_logs', function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->string('provider');$t->string('operation');$t->string('status');$t->json('request_data')->nullable();$t->json('response_data')->nullable();$t->text('error')->nullable();$t->timestamps();});
        Schema::create('ai_logs', function(Blueprint $t){$t->id();$t->foreignId('company_id')->constrained();$t->foreignId('user_id')->nullable()->constrained();$t->string('feature');$t->string('model')->nullable();$t->unsignedInteger('tokens')->default(0);$t->decimal('cost',12,6)->default(0);$t->longText('prompt')->nullable();$t->longText('response')->nullable();$t->string('status')->default('completed');$t->timestamps();});
    }
    public function down(): void {foreach(['ai_logs','integration_logs','web_orders','documents','notifications','settings','promotion_product','promotions','units','brands','categories','role_user','permission_role','permissions','roles'] as $table)Schema::dropIfExists($table);}
};
