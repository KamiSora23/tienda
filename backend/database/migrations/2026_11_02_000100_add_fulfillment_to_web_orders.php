<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pedidos web con recojo en tienda o delivery: datos de entrega, ubicación en el mapa y costo de envío.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_orders', function (Blueprint $table) {
            $table->string('fulfillment_type', 20)->default('delivery')->after('status');
            $table->string('delivery_district', 120)->nullable()->after('delivery_address');
            $table->string('delivery_reference', 255)->nullable()->after('delivery_district');
            $table->decimal('delivery_lat', 10, 7)->nullable()->after('delivery_reference');
            $table->decimal('delivery_lng', 10, 7)->nullable()->after('delivery_lat');
            $table->decimal('delivery_fee', 12, 2)->default(0)->after('total');
            $table->string('customer_notes', 500)->nullable()->after('delivery_lng');
        });
        Schema::table('web_customer_addresses', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('reference');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
        });
    }

    public function down(): void
    {
        Schema::table('web_orders', fn (Blueprint $t) => $t->dropColumn(['fulfillment_type', 'delivery_district', 'delivery_reference', 'delivery_lat', 'delivery_lng', 'delivery_fee', 'customer_notes']));
        Schema::table('web_customer_addresses', fn (Blueprint $t) => $t->dropColumn(['lat', 'lng']));
    }
};
