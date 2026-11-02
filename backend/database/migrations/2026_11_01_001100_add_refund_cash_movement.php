<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Devolución de efectivo al cliente cuando devuelve mercadería pagada en efectivo.
    public function up(): void
    {
        Schema::table('cash_movements', fn (Blueprint $t) => $t->enum('type', ['opening', 'sale', 'income', 'expense', 'withdrawal', 'deposit', 'refund', 'closing'])->change());
    }

    public function down(): void
    {
        Schema::table('cash_movements', fn (Blueprint $t) => $t->enum('type', ['opening', 'sale', 'income', 'expense', 'withdrawal', 'deposit', 'closing'])->change());
    }
};
