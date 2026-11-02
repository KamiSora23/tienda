<?php

use App\Services\KardexReconciler;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    // El stock inicial de los productos no se registraba en el kardex: se agrega el saldo inicial
    // que falta, fechado al crear cada producto. No modifica ningún stock.
    public function up(): void
    {
        (new KardexReconciler())->backfillInitialStock();
    }

    public function down(): void
    {
        // Los saldos iniciales agregados son indistinguibles de los nuevos: no se revierten.
    }
};
