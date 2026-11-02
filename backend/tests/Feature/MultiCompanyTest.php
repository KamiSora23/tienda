<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Varias empresas: cada sucursal pertenece a una sola empresa y cada almacén a una sola sucursal. */
class MultiCompanyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@deurstore.pe')->first();
        $this->headers = $this->headersFor($this->admin);
    }

    private function headersFor(User $user): array
    {
        $token = Str::random(64);
        $user->update(['api_token' => hash('sha256', $token)]);
        return ['Authorization' => 'Bearer '.$token];
    }

    private function platformHeaders(): array
    {
        $this->admin->update(['role' => 'platform_admin']);
        return $this->headersFor($this->admin);
    }

    private function newCompany(array $headers, string $ruc = '20999999999', string $email = 'dueno@nueva.pe'): int
    {
        return $this->postJson('/api/v1/admin/companies', [
            'business_name' => 'NUEVA SAC', 'trade_name' => 'Nueva', 'ruc' => $ruc, 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active',
            'admin_name' => 'Dueña Nueva', 'admin_email' => $email, 'admin_password' => 'ClaveSegura2026!',
        ], $headers)->assertOk()->json('data.id');
    }

    private function userPayload(array $overrides = []): array
    {
        return $overrides + ['name' => 'Cajera', 'email' => 'cajera@deurstore.pe', 'password' => 'ClaveSegura2026!', 'role' => 'admin', 'branch_id' => $this->admin->branch_id, 'status' => 'active'];
    }

    // --- Usuarios ---------------------------------------------------------------------

    public function test_a_store_cannot_create_a_platform_administrator(): void
    {
        $this->postJson('/api/v1/admin/users', $this->userPayload(['role' => 'platform_admin']), $this->headers)
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'cajera@deurstore.pe']);
    }

    public function test_users_get_a_role_and_branch_of_their_own_company_and_a_unique_email(): void
    {
        $other = $this->newCompany($this->platformHeaders());
        $this->admin->update(['role' => 'admin']);
        $headers = $this->headersFor($this->admin);
        $foreignBranch = DB::table('branches')->where('company_id', $other)->value('id');

        $this->postJson('/api/v1/admin/users', $this->userPayload(['branch_id' => $foreignBranch]), $headers)->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->postJson('/api/v1/admin/users', $this->userPayload(['role' => 'inventado']), $headers)->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson('/api/v1/admin/users', $this->userPayload(['email' => 'admin@deurstore.pe']), $headers)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/admin/users', $this->userPayload(), $headers)->assertOk();
    }

    // --- Empresa nueva ----------------------------------------------------------------

    public function test_a_new_company_comes_with_its_admin_user_ready_to_work(): void
    {
        $company = $this->newCompany($this->platformHeaders());

        $owner = User::where('email', 'dueno@nueva.pe')->first();
        $this->assertSame($company, $owner->company_id);
        $this->assertSame(DB::table('branches')->where('company_id', $company)->value('id'), $owner->branch_id);
        $this->assertTrue(DB::table('role_user as ru')->join('roles as r', 'r.id', '=', 'ru.role_id')->where(['ru.user_id' => $owner->id, 'r.company_id' => $company, 'r.slug' => 'admin'])->exists());

        $token = $this->postJson('/api/v1/login', ['email' => 'dueno@nueva.pe', 'password' => 'ClaveSegura2026!'])->assertOk()->json('data.token');
        $own = ['Authorization' => 'Bearer '.$token];
        $this->getJson('/api/v1/products', $own)->assertOk()->assertJsonCount(0, 'data');
        // Nace con la categoría General: puede registrar productos de inmediato.
        $this->postJson('/api/v1/products', ['sku' => 'N-1', 'name' => 'Agua', 'category' => 'General', 'unit_type' => 'unit', 'sale_price' => 1.5, 'stock' => 10], $own)->assertOk();
        $this->assertSame([$company], collect($this->getJson('/api/v1/admin/companies', $own)->json('data'))->pluck('id')->all());
    }

    public function test_validation_messages_are_in_spanish(): void
    {
        $this->postJson('/api/v1/products', ['sku' => 'X', 'name' => 'X', 'category' => 'No existe', 'unit_type' => 'unit', 'sale_price' => 1], $this->headers)
            ->assertUnprocessable()->assertJsonPath('errors.category.0', 'El valor de categoría no es válido.');
        $this->postJson('/api/v1/admin/branches', ['code' => 'Z'], $this->headers)
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'Nombre es obligatorio.');
    }

    // --- Cambio de empresa y sucursal -------------------------------------------------

    public function test_the_platform_admin_can_switch_to_a_branch_of_another_company(): void
    {
        $headers = $this->platformHeaders();
        $company = $this->newCompany($headers);
        $options = $this->getJson('/api/v1/context/options', $headers)->assertOk()->json('data');
        $branch = collect($options['branches'])->firstWhere('company_id', $company);
        $this->assertNotNull($branch, 'Las sucursales de las otras empresas deben estar disponibles');

        $this->postJson('/api/v1/context', ['company_id' => $company, 'branch_id' => $branch['id']], $headers)->assertOk();
        $this->getJson('/api/v1/products', $headers)->assertOk()->assertJsonCount(0, 'data');

        // Una sucursal de otra empresa no vale como contexto de esta.
        $mine = DB::table('branches')->where('company_id', '!=', $company)->value('id');
        $this->postJson('/api/v1/context', ['company_id' => $company, 'branch_id' => $mine], $headers)->assertUnprocessable();
    }

    public function test_a_store_admin_only_sees_its_own_branches_as_context(): void
    {
        $this->newCompany($this->platformHeaders());
        $this->admin->update(['role' => 'admin']);
        $options = $this->getJson('/api/v1/context/options', $this->headersFor($this->admin))->assertOk()->json('data');

        $this->assertSame([$this->admin->company_id], array_values(array_unique(array_column($options['branches'], 'company_id'))));
        $this->assertSame([$this->admin->company_id], array_column($options['companies'], 'id'));
    }

    public function test_the_context_only_accepts_an_open_cash_session_of_that_branch(): void
    {
        $headers = $this->platformHeaders();
        $company = $this->newCompany($headers);
        $foreignBranch = DB::table('branches')->where('company_id', $company)->value('id');
        $foreignCash = DB::table('cash_sessions')->insertGetId(['company_id' => $company, 'branch_id' => $foreignBranch, 'user_id' => $this->admin->id, 'opening_amount' => 0, 'status' => 'open', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/v1/context', ['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'cash_session_id' => $foreignCash], $headers)->assertUnprocessable();
    }

    // --- Desactivar sucursal ----------------------------------------------------------

    public function test_deactivating_a_branch_deactivates_its_warehouses_and_needs_them_empty(): void
    {
        $branch = $this->postJson('/api/v1/admin/branches', ['code' => 'ESTE', 'name' => 'Este'], $this->headers)->assertCreated()->json('data.id');
        $warehouse = DB::table('warehouses')->where('branch_id', $branch)->value('id');
        $product = DB::table('products')->where('company_id', $this->admin->company_id)->value('id');
        DB::table('stocks')->insert(['company_id' => $this->admin->company_id, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()]);

        $this->deleteJson("/api/v1/admin/branches/{$branch}", [], $this->headers)->assertUnprocessable();

        DB::table('stocks')->where('warehouse_id', $warehouse)->update(['quantity' => 0]);
        $this->deleteJson("/api/v1/admin/branches/{$branch}", [], $this->headers)->assertOk();
        $this->assertSame('inactive', DB::table('warehouses')->where('id', $warehouse)->value('status'));

        // Reactivarla devuelve su almacén de venta.
        $this->putJson("/api/v1/admin/branches/{$branch}", ['code' => 'ESTE', 'name' => 'Este', 'status' => 'active'], $this->headers)->assertOk();
        $this->assertSame('active', DB::table('warehouses')->where('id', $warehouse)->value('status'));
    }

    // --- La base de datos impide mezclar empresas ---------------------------------------

    public function test_the_database_rejects_a_warehouse_in_a_branch_of_another_company(): void
    {
        $other = DB::table('companies')->insertGetId(['uuid' => Str::uuid(), 'business_name' => 'OTRA SAC', 'trade_name' => 'Otra', 'ruc' => '20111111111', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(QueryException::class);
        DB::table('warehouses')->insert(['company_id' => $other, 'branch_id' => $this->admin->branch_id, 'code' => 'MIX', 'name' => 'Mezclado', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_database_rejects_stock_whose_branch_or_company_differs_from_its_warehouse(): void
    {
        $warehouse = DB::table('warehouses')->where('branch_id', $this->admin->branch_id)->first();
        $product = DB::table('products')->where('company_id', $this->admin->company_id)->value('id');
        $branch = DB::table('branches')->insertGetId(['company_id' => $this->admin->company_id, 'code' => 'B9', 'name' => 'Otra sede', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stocks')->where(['warehouse_id' => $warehouse->id, 'product_id' => $product])->delete();

        $this->expectException(QueryException::class);
        DB::table('stocks')->insert(['company_id' => $this->admin->company_id, 'branch_id' => $branch, 'warehouse_id' => $warehouse->id, 'product_id' => $product, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_database_rejects_a_user_in_a_branch_of_another_company(): void
    {
        $other = DB::table('companies')->insertGetId(['uuid' => Str::uuid(), 'business_name' => 'OTRA SAC', 'trade_name' => 'Otra', 'ruc' => '20111111111', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $this->admin->id)->update(['company_id' => $other]);
    }
}
