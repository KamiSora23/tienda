<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Almacenes de la empresa: exclusivos de una sucursal o compartidos entre varias. */
class SharedWarehouseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private array $headers;
    private int $main;
    private int $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@deurstore.pe')->first();
        $token = Str::random(64);
        $this->admin->update(['api_token' => hash('sha256', $token)]);
        $this->headers = ['Authorization' => 'Bearer '.$token];
        $this->main = $this->admin->branch_id;
        $this->second = $this->postJson('/api/v1/admin/branches', ['code' => 'NORTE', 'name' => 'Norte'], $this->headers)->assertCreated()->json('data.id');
    }

    private function shared(array $extra = []): int
    {
        return $this->postJson('/api/v1/admin/warehouses', $extra + ['scope' => 'shared', 'branch_ids' => [$this->main, $this->second], 'code' => 'CENTRAL', 'name' => 'Almacén central'], $this->headers)
            ->assertCreated()->json('data.id');
    }

    private function useAs(int $branch): array
    {
        DB::table('users')->where('id', $this->admin->id)->update(['branch_id' => $branch]);
        return $this->headers;
    }

    public function test_a_shared_warehouse_belongs_to_the_company_and_is_usable_by_its_branches(): void
    {
        $id = $this->shared();

        $warehouse = DB::table('warehouses')->where('id', $id)->first();
        $this->assertSame($this->admin->company_id, (int) $warehouse->company_id);
        $this->assertNull($warehouse->branch_id);

        $listed = collect($this->getJson('/api/v1/admin/warehouses', $this->headers)->json('data'))->firstWhere('id', $id);
        $this->assertSame('shared', $listed['scope']);
        $this->assertEqualsCanonicalizing([$this->main, $this->second], $listed['branch_ids']);

        foreach ([$this->main, $this->second] as $branch) {
            $options = collect($this->getJson('/api/v1/admin/branches', $this->headers)->json('data'))->firstWhere('id', $branch)['warehouses'];
            $this->assertContains($id, array_column($options, 'id'));
        }
    }

    public function test_two_branches_can_sell_from_the_same_shared_warehouse(): void
    {
        $id = $this->shared(['default_for' => [$this->main, $this->second]]);
        $product = DB::table('products')->where('company_id', $this->admin->company_id)->where('unit_type', 'unit')->first();
        DB::table('stocks')->insert(['company_id' => $this->admin->company_id, 'branch_id' => null, 'warehouse_id' => $id, 'product_id' => $product->id, 'quantity' => 10, 'created_at' => now(), 'updated_at' => now()]);

        foreach ([$this->main, $this->second] as $branch) {
            $this->useAs($branch);
            DB::table('cash_sessions')->insert(['company_id' => $this->admin->company_id, 'branch_id' => $branch, 'user_id' => $this->admin->id, 'opening_amount' => 0, 'status' => 'open', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->postJson('/api/v1/sales', ['items' => [['product_id' => $product->id, 'quantity' => 3]], 'payments' => [['method' => 'cash', 'amount' => 3 * $product->sale_price]]], $this->headers)->assertCreated();
        }

        $this->assertEquals(4, (float) DB::table('stocks')->where(['warehouse_id' => $id, 'product_id' => $product->id])->value('quantity'));
    }

    public function test_a_shared_warehouse_needs_two_branches_of_the_same_company(): void
    {
        $other = DB::table('companies')->insertGetId(['uuid' => Str::uuid(), 'business_name' => 'OTRA SAC', 'trade_name' => 'Otra', 'ruc' => '20111111111', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = DB::table('branches')->insertGetId(['company_id' => $other, 'code' => 'AJ', 'name' => 'Ajena', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/v1/admin/warehouses', ['scope' => 'shared', 'branch_ids' => [$this->main, $foreign], 'code' => 'MIX', 'name' => 'Mezcla'], $this->headers)
            ->assertUnprocessable()->assertJsonValidationErrors('branch_ids.1');
        $this->postJson('/api/v1/admin/warehouses', ['scope' => 'shared', 'branch_ids' => [$this->main], 'code' => 'UNO', 'name' => 'Uno'], $this->headers)
            ->assertUnprocessable()->assertJsonValidationErrors('branch_ids');
    }

    public function test_a_branch_cannot_lose_access_to_the_warehouse_it_sells_from(): void
    {
        $id = $this->shared(['default_for' => [$this->second]]);

        // Pasarlo a exclusivo de la sucursal principal dejaría a Norte sin su almacén de venta.
        $this->putJson("/api/v1/admin/warehouses/{$id}", ['scope' => 'branch', 'branch_id' => $this->main, 'code' => 'CENTRAL', 'name' => 'Almacén central'], $this->headers)->assertUnprocessable();
        // Desactivarlo tampoco.
        $this->deleteJson("/api/v1/admin/warehouses/{$id}", [], $this->headers)->assertUnprocessable();
    }

    public function test_the_sales_warehouse_of_a_branch_must_be_within_its_reach(): void
    {
        $exclusive = DB::table('branches')->where('id', $this->second)->value('default_warehouse_id');

        // La sucursal principal no puede vender desde el almacén exclusivo de Norte.
        $this->putJson("/api/v1/admin/branches/{$this->main}", ['code' => 'PIU-01', 'name' => 'Piura Centro', 'default_warehouse_id' => $exclusive], $this->headers)->assertUnprocessable();
        // Sí desde uno compartido con ella.
        $shared = $this->shared();
        $this->putJson("/api/v1/admin/branches/{$this->main}", ['code' => 'PIU-01', 'name' => 'Piura Centro', 'default_warehouse_id' => $shared], $this->headers)->assertOk();
        $this->assertSame($shared, DB::table('branches')->where('id', $this->main)->value('default_warehouse_id'));
    }

    public function test_deactivating_a_branch_keeps_shared_warehouses_running(): void
    {
        $shared = $this->shared(['default_for' => [$this->main]]);
        $exclusive = DB::table('branches')->where('id', $this->second)->value('default_warehouse_id');

        $this->deleteJson("/api/v1/admin/branches/{$this->second}", [], $this->headers)->assertOk();

        $this->assertSame('inactive', DB::table('warehouses')->where('id', $exclusive)->value('status'));
        $this->assertSame('active', DB::table('warehouses')->where('id', $shared)->value('status'));
    }

    public function test_reactivating_a_branch_from_the_list_brings_back_its_own_sales_warehouse(): void
    {
        $own = DB::table('branches')->where('id', $this->second)->value('default_warehouse_id');
        $this->deleteJson("/api/v1/admin/branches/{$this->second}", [], $this->headers)->assertOk();

        // La interfaz reenvía el almacén de venta actual (inactivo) al reactivar.
        $this->putJson("/api/v1/admin/branches/{$this->second}", ['code' => 'NORTE', 'name' => 'Norte', 'status' => 'active', 'default_warehouse_id' => $own], $this->headers)->assertOk();
        $this->assertSame('active', DB::table('warehouses')->where('id', $own)->value('status'));
    }

    public function test_the_database_rejects_access_rows_mixing_companies(): void
    {
        $other = DB::table('companies')->insertGetId(['uuid' => Str::uuid(), 'business_name' => 'OTRA SAC', 'trade_name' => 'Otra', 'ruc' => '20111111111', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $warehouse = $this->shared();

        $this->expectException(QueryException::class);
        DB::table('branch_warehouse')->insert(['company_id' => $other, 'branch_id' => $this->main, 'warehouse_id' => $warehouse, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_database_rejects_a_sales_warehouse_from_another_company(): void
    {
        $other = DB::table('companies')->insertGetId(['uuid' => Str::uuid(), 'business_name' => 'OTRA SAC', 'trade_name' => 'Otra', 'ruc' => '20111111111', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $foreignBranch = DB::table('branches')->insertGetId(['company_id' => $other, 'code' => 'AJ', 'name' => 'Ajena', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $foreignWarehouse = DB::table('warehouses')->insertGetId(['company_id' => $other, 'branch_id' => $foreignBranch, 'code' => 'AJ-W', 'name' => 'Ajeno', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(QueryException::class);
        DB::table('branches')->where('id', $this->main)->update(['default_warehouse_id' => $foreignWarehouse]);
    }
}
