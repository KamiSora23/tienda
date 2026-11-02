<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Controles del superadministrador: sus acciones quedan marcadas en la auditoría y, por defecto,
// opera en solo lectura; la edición se activa por sesión, con motivo y por tiempo limitado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->boolean('by_platform')->default(false)->after('user_id');
        });
        Schema::table('user_sessions', function (Blueprint $table) {
            $table->timestamp('platform_write_until')->nullable()->after('expires_at');
            $table->string('platform_write_reason', 500)->nullable()->after('platform_write_until');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', fn (Blueprint $t) => $t->dropColumn('by_platform'));
        Schema::table('user_sessions', fn (Blueprint $t) => $t->dropColumn(['platform_write_until', 'platform_write_reason']));
    }
};
