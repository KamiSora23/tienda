<?php

use App\Services\UnitCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $t) {
            // Código estándar (kg, g, m2…) que usan products.unit_type y las conversiones.
            $t->string('code', 10)->nullable()->after('company_id');
            $t->string('status', 10)->default('active')->after('allows_decimals');
            // El enum original no tenía «area».
            $t->string('measure_type', 10)->change();
            $t->unique(['company_id', 'code']);
        });

        $catalog = new UnitCatalog();
        foreach (DB::table('companies')->pluck('id') as $company) {
            $catalog->ensureFor((int) $company);
        }
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $t) {
            $t->dropUnique(['company_id', 'code']);
            $t->dropColumn(['code', 'status']);
        });
    }
};
