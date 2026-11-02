<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Aplicar descuentos (por línea o al total) es sensible: solo el rol administrador (dueño)
    // lo recibe por defecto; a otros roles se les concede desde Roles y permisos.
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore(['module' => 'sales', 'action' => 'discount', 'slug' => 'sales.discount', 'created_at' => now(), 'updated_at' => now()]);
        $permission = DB::table('permissions')->where('slug', 'sales.discount')->value('id');
        foreach (DB::table('roles')->where('slug', 'admin')->pluck('id') as $role) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        $permission = DB::table('permissions')->where('slug', 'sales.discount')->value('id');
        DB::table('permission_role')->where('permission_id', $permission)->delete();
        DB::table('permissions')->where('slug', 'sales.discount')->delete();
    }
};
