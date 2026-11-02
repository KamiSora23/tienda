<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Empresas, sucursales y almacenes: aislamiento entre empresas y estructura operativa completa. */
class CompanyBranchTest extends TestCase
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

    private function otherCompany(): int
    {
        return DB::table('companies')->insertGetId(['uuid' => Str::uuid(), 'business_name' => 'OTRA SAC', 'trade_name' => 'Otra', 'ruc' => '20111111111', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function companyPayload(array $overrides = []): array
    {
        return $overrides + ['business_name' => 'NUEVA SAC', 'trade_name' => 'Nueva', 'ruc' => '20999999999', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'locale' => 'es_PE', 'date_format' => 'd/m/Y', 'status' => 'active'];
    }

    // --- Empresas ---------------------------------------------------------------------

    public function test_a_store_admin_only_sees_its_own_company(): void
    {
        $this->otherCompany();
        $ids = collect($this->getJson('/api/v1/admin/companies', $this->headers)->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$this->admin->company_id], $ids);
    }

    public function test_a_store_admin_cannot_edit_or_create_other_companies(): void
    {
        $other = $this->otherCompany();
        $this->putJson("/api/v1/admin/companies/{$other}", $this->companyPayload(['ruc' => '20111111111', 'trade_name' => 'Hackeada']), $this->headers)->assertForbidden();
        $this->assertSame('Otra', DB::table('companies')->where('id', $other)->value('trade_name'));

        $this->postJson('/api/v1/admin/companies', $this->companyPayload(), $this->headers)->assertForbidden();
    }

    public function test_a_store_admin_cannot_deactivate_its_own_company(): void
    {
        $own = DB::table('companies')->where('id', $this->admin->company_id)->first();
        $this->putJson("/api/v1/admin/companies/{$own->id}/profile", $this->companyPayload(['business_name' => $own->business_name, 'trade_name' => 'Renombrada', 'ruc' => $own->ruc, 'status' => 'inactive']), $this->headers)->assertOk();

        $this->assertSame('active', DB::table('companies')->where('id', $own->id)->value('status'));
        $this->assertSame('Renombrada', DB::table('companies')->where('id', $own->id)->value('trade_name'));
    }

    public function test_ruc_must_be_unique(): void
    {
        $this->otherCompany();
        $own = DB::table('companies')->where('id', $this->admin->company_id)->first();
        $this->putJson("/api/v1/admin/companies/{$own->id}/profile", $this->companyPayload(['ruc' => '20111111111']), $this->headers)
            ->assertUnprocessable()->assertJsonValidationErrors('ruc');
    }

    public function test_platform_admin_creates_a_company_ready_to_operate(): void
    {
        $this->admin->update(['role' => 'platform_admin']);
        $id = $this->postJson('/api/v1/admin/companies', $this->companyPayload(['admin_name' => 'Dueño', 'admin_email' => 'dueno@nueva.pe', 'admin_password' => 'ClaveSegura2026!']), $this->headersFor($this->admin))->assertOk()->json('data.id');

        $branch = DB::table('branches')->where('company_id', $id)->first();
        $this->assertNotNull($branch);
        $this->assertTrue(DB::table('warehouses')->where(['id' => $branch->default_warehouse_id, 'branch_id' => $branch->id])->exists());
        $this->assertTrue(DB::table('document_series')->where(['branch_id' => $branch->id, 'document_type' => 'ticket'])->exists());
        $this->assertTrue(DB::table('cash_registers')->where('branch_id', $branch->id)->exists());
        $this->assertTrue(DB::table('payment_methods')->where('company_id', $id)->exists());
        $this->assertSame(15, DB::table('units')->where('company_id', $id)->count());
    }

    // --- Sucursales -------------------------------------------------------------------

    public function test_a_new_branch_gets_its_warehouse_cash_register_and_own_series(): void
    {
        $id = $this->postJson('/api/v1/admin/branches', ['code' => 'SUR', 'name' => 'Sucursal Sur', 'status' => 'active'], $this->headers)->assertCreated()->json('data.id');

        $default = DB::table('branches')->where('id', $id)->value('default_warehouse_id');
        $this->assertTrue(DB::table('warehouses')->where(['id' => $default, 'branch_id' => $id, 'status' => 'active'])->exists());
        $this->assertTrue(DB::table('cash_registers')->where('branch_id', $id)->exists());
        // Las series no se repiten dentro de la empresa (un RUC no puede emitir dos T001-00000001).
        $this->assertSame('T002', DB::table('document_series')->where(['branch_id' => $id, 'document_type' => 'ticket'])->value('series'));
        $this->assertSame('F002', DB::table('document_series')->where(['branch_id' => $id, 'document_type' => 'invoice'])->value('series'));

        // Se puede trabajar en ella de inmediato.
        $this->postJson('/api/v1/context', ['company_id' => $this->admin->company_id, 'branch_id' => $id], $this->headers)->assertOk();
        $this->getJson('/api/v1/products', $this->headers)->assertOk();
    }

    public function test_branch_codes_are_unique_and_required_fields_validated(): void
    {
        $code = DB::table('branches')->where('company_id', $this->admin->company_id)->value('code');
        $this->postJson('/api/v1/admin/branches', ['code' => $code, 'name' => 'Repetida'], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/admin/branches', ['code' => 'X1'], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_the_only_active_branch_or_one_with_users_cannot_be_deactivated(): void
    {
        $main = $this->admin->branch_id;
        $this->deleteJson("/api/v1/admin/branches/{$main}", [], $this->headers)->assertUnprocessable();

        $other = $this->postJson('/api/v1/admin/branches', ['code' => 'NORTE', 'name' => 'Norte'], $this->headers)->assertCreated()->json('data.id');
        // La sucursal principal tiene usuarios asignados: no se desactiva aunque haya otra activa.
        $this->putJson("/api/v1/admin/branches/{$main}", ['code' => 'X', 'name' => 'Principal', 'status' => 'inactive'], $this->headers)->assertUnprocessable();
        // Una sin usuarios ni caja abierta sí.
        $this->deleteJson("/api/v1/admin/branches/{$other}", [], $this->headers)->assertOk();
        $this->assertSame('inactive', DB::table('branches')->where('id', $other)->value('status'));
    }

    // --- Almacenes --------------------------------------------------------------------

    public function test_a_warehouse_cannot_point_to_another_companys_branch(): void
    {
        $other = $this->otherCompany();
        $foreignBranch = DB::table('branches')->insertGetId(['company_id' => $other, 'code' => 'AJ', 'name' => 'Ajena', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/v1/admin/warehouses', ['branch_id' => $foreignBranch, 'code' => 'W-X', 'name' => 'Intruso'], $this->headers)
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_sales_use_the_branch_default_warehouse_and_it_can_be_changed(): void
    {
        $original = DB::table('branches')->where('id', $this->admin->branch_id)->value('default_warehouse_id');
        $second = $this->postJson('/api/v1/admin/warehouses', ['branch_id' => $this->admin->branch_id, 'code' => 'W-2', 'name' => 'Segundo'], $this->headers)->assertCreated()->json('data.id');
        $this->assertSame($original, DB::table('branches')->where('id', $this->admin->branch_id)->value('default_warehouse_id'));

        $this->putJson("/api/v1/admin/warehouses/{$second}", ['branch_id' => $this->admin->branch_id, 'code' => 'W-2', 'name' => 'Segundo', 'status' => 'active', 'default_for' => [$this->admin->branch_id]], $this->headers)->assertOk();
        $this->assertSame($second, DB::table('branches')->where('id', $this->admin->branch_id)->value('default_warehouse_id'));

        // Productos y ventas ahora trabajan con el nuevo almacén de venta (sin stock).
        $product = collect($this->getJson('/api/v1/products', $this->headers)->json('data'))->first();
        $this->assertEquals(0, (float) $product['stock']);
    }

    public function test_the_sales_warehouse_or_one_with_stock_cannot_be_deactivated(): void
    {
        $default = DB::table('branches')->where('id', $this->admin->branch_id)->value('default_warehouse_id');
        $this->deleteJson("/api/v1/admin/warehouses/{$default}", [], $this->headers)->assertUnprocessable();

        $aux = $this->postJson('/api/v1/admin/warehouses', ['branch_id' => $this->admin->branch_id, 'code' => 'W-AUX', 'name' => 'Auxiliar'], $this->headers)->assertCreated()->json('data.id');
        $product = DB::table('products')->where('company_id', $this->admin->company_id)->value('id');
        DB::table('stocks')->insert(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'warehouse_id' => $aux, 'product_id' => $product, 'quantity' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $this->deleteJson("/api/v1/admin/warehouses/{$aux}", [], $this->headers)->assertUnprocessable();
    }
}
