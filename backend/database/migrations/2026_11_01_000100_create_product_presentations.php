<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unidad base: el stock y el costo promedio se expresan siempre en ella.
        Schema::table('products', function (Blueprint $t) {
            $t->string('unit_type', 10)->default('unit')->change();
        });

        // Formas de vender un producto en múltiplos o fracciones de su unidad base
        // (½ kg, docena, jaba, pack x3…). Comparten el mismo stock del producto.
        Schema::create('product_presentations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->decimal('factor', 14, 3);
            $t->decimal('sale_price', 14, 2);
            $t->string('barcode', 64)->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['product_id', 'name']);
            $t->index(['company_id', 'barcode']);
        });

        // Instantánea de la presentación vendida: el comprobante no cambia si luego se edita.
        Schema::table('sale_details', function (Blueprint $t) {
            $t->unsignedBigInteger('presentation_id')->nullable()->after('product_name');
            $t->string('presentation_name', 60)->nullable()->after('presentation_id');
            $t->decimal('presentation_quantity', 14, 3)->nullable()->after('presentation_name');
            $t->decimal('presentation_factor', 14, 3)->nullable()->after('presentation_quantity');
            // Precio por unidad base; con presentaciones (docena a S/ 10 → 0.8333) dos decimales no bastan.
            $t->decimal('unit_price', 14, 4)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sale_details', function (Blueprint $t) {
            $t->dropColumn(['presentation_id', 'presentation_name', 'presentation_quantity', 'presentation_factor']);
            $t->decimal('unit_price', 14, 2)->change();
        });
        Schema::dropIfExists('product_presentations');
    }
};
