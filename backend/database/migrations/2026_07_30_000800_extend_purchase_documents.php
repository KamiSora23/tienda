<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->after('number');
        });
    }
    public function down(): void {
        Schema::table('purchases', fn (Blueprint $table) => $table->dropColumn('invoice_number'));
    }
};
