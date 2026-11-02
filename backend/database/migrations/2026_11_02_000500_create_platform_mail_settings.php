<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Correo general de la plataforma, configurable desde el panel del superadministrador.
// Si está activo, reemplaza al de .env; lo usan el superadministrador y las empresas sin correo propio.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_mail_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('host', 190)->nullable();
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('encryption', 10)->default('tls');
            $table->string('username', 190)->nullable();
            $table->text('password')->nullable();
            $table->string('from_address', 190)->nullable();
            $table->string('from_name', 120)->nullable();
            $table->string('reply_to', 190)->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_mail_settings');
    }
};
