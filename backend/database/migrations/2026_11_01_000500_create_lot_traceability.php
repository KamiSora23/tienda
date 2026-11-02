<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada entrada o salida de un lote, con el documento que la causó. Permite devolver a su
        // lote original lo vendido cuando la venta se anula o se devuelve.
        Schema::create('lot_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained();
            $t->foreignId('warehouse_id')->constrained();
            $t->decimal('quantity', 14, 3); // positivo entra al lote, negativo sale
            $t->string('document_type', 30);
            $t->unsignedBigInteger('document_id');
            // Para reversas: documento original cuyo consumo se está devolviendo (p. ej. la venta).
            $t->string('source_document_type', 30)->nullable();
            $t->unsignedBigInteger('source_document_id')->nullable();
            $t->timestamps();
            $t->index(['document_type', 'document_id']);
            $t->index(['source_document_type', 'source_document_id']);
        });

        // Lote y vencimiento indicados al comprar (también en compras pendientes).
        Schema::table('purchase_details', function (Blueprint $t) {
            $t->string('lot_number', 60)->nullable()->after('total');
            $t->date('expires_at')->nullable()->after('lot_number');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_details', fn (Blueprint $t) => $t->dropColumn(['lot_number', 'expires_at']));
        Schema::dropIfExists('lot_movements');
    }
};
