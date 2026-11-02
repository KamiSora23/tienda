<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // El producto exige una categoría: una empresa sin ninguna no podía registrar productos.
    public function up(): void
    {
        $withCategories = DB::table('categories')->distinct()->pluck('company_id');
        foreach (DB::table('companies')->whereNotIn('id', $withCategories)->pluck('id') as $company) {
            DB::table('categories')->insert(['company_id' => $company, 'name' => 'General', 'slug' => 'general', 'status' => 'active', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Las categorías creadas pueden tener productos: no se eliminan.
    }
};
