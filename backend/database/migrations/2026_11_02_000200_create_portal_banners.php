<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Carrusel del portal (diapositivas con imagen, texto y enlace a un producto, promoción o categoría)
// y productos destacados para el carrusel de productos.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_banners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('subtitle', 255)->nullable();
            $table->string('button_label', 40)->nullable();
            $table->string('image_path')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 120)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'active', 'sort_order']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('status');
            $table->unsignedInteger('featured_order')->default(0)->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_banners');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['is_featured', 'featured_order']));
    }
};
