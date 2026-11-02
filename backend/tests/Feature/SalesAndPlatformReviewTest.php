<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Descuentos, devoluciones, productos y accesos del superadministrador. */
class SalesAndPlatformReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private array $h;
    private int $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@deurstore.pe')->first();
        $this->h = $this->auth('admin@deurstore.pe', 'Deurstore2026!');
        // Producto controlado: precio 10, mínimo 8, 100 en stock.
        $this->product = $this->postJson('/api/v1/products', ['sku' => 'DSC-1', 'name' => 'Producto descuento', 'category' => DB::table('categories')->where('company_id', $this->admin->company_id)->value('name'), 'unit_type' => 'unit', 'sale_price' => 10, 'average_cost' => 6, 'minimum_price' => 8, 'stock' => 100], $this->h)->assertOk()->json('data.id');
    }

    private function auth(string $email, string $password): array
    {
        return ['Authorization' => 'Bearer '.$this->loginToken($email, $password)];
    }

    private function sell(array $item, float $paid, array $extra = [], ?array $h = null)
    {
        return $this->postJson('/api/v1/sales', ['items' => [['product_id' => $this->product] + $item], 'payments' => [['method' => 'cash', 'amount' => $paid]]] + $extra, $h ?? $this->h);
    }

    /** Usuario con un rol de la empresa limitado a ciertos permisos. */
    private function userWith(array $slugs, string $email): array
    {
        $role = DB::table('roles')->insertGetId(['company_id' => $this->admin->company_id, 'name' => 'Rol '.$email, 'slug' => 'rol-'.md5($email), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permission_role')->insert(DB::table('permissions')->whereIn('slug', $slugs)->pluck('id')->map(fn ($id) => ['role_id' => $role, 'permission_id' => $id])->all());
        $user = User::create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'name' => 'Usuario '.$email, 'email' => $email, 'password' => Hash::make('Clave12345'), 'role' => 'rol-'.md5($email), 'status' => 'active']);
        DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $user->id]);

        return [$user, $this->auth($email, 'Clave12345')];
    }

    public function test_a_discount_cannot_push_a_product_below_its_minimum_price_without_override_permission(): void
    {
        $this->sell(['quantity' => 1, 'discount' => 1], 9)->assertCreated();
        $this->sell(['quantity' => 1, 'discount' => 5], 5)->assertUnprocessable()->assertJsonFragment(['message' => 'El descuento deja Producto descuento en S/ 5.00, por debajo de su precio mínimo (S/ 8.00).']);
        $this->sell(['quantity' => 2], 10, ['discount' => 10])->assertUnprocessable();
        // Regalar el producto (100 % de descuento) tampoco pasa.
        $this->sell(['quantity' => 1, 'discount' => 10], 0.01)->assertUnprocessable();

        // Con permiso para cambiar precios, el descuento es una decisión explícita.
        DB::table('permission_role')->insert(['role_id' => DB::table('role_user')->where('user_id', $this->admin->id)->value('role_id'), 'permission_id' => DB::table('permissions')->where('slug', 'sales.override_price')->value('id')]);
        $this->sell(['quantity' => 1, 'discount' => 5], 5, ['discount_reason' => 'Producto con daño'])->assertCreated();
    }

    public function test_returning_a_discounted_sale_refunds_only_what_was_paid(): void
    {
        $sale = $this->sell(['quantity' => 10], 90, ['discount' => 10])->assertCreated()->json('data.id');
        $detail = DB::table('sale_details')->where('sale_id', $sale)->value('id');

        $half = $this->postJson("/api/v1/sales/{$sale}/return", ['reason' => 'Mitad', 'items' => [['sale_detail_id' => $detail, 'quantity' => 5]]], $this->h)->assertCreated();
        $this->assertEqualsWithDelta(45, $half->json('data.total'), 0.001);
        $rest = $this->postJson("/api/v1/sales/{$sale}/return", ['reason' => 'Resto', 'items' => [['sale_detail_id' => $detail, 'quantity' => 5]]], $this->h)->assertCreated();
        $this->assertEqualsWithDelta(45, $rest->json('data.total'), 0.001);
        $this->assertSame('refunded', DB::table('sales')->where('id', $sale)->value('status'));
    }

    public function test_products_reject_duplicate_codes_and_a_minimum_above_the_price(): void
    {
        $base = ['name' => 'Otro', 'category' => DB::table('categories')->where('company_id', $this->admin->company_id)->value('name'), 'unit_type' => 'unit', 'sale_price' => 5];
        $this->postJson('/api/v1/products', $base + ['sku' => 'DSC-1'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->postJson('/api/v1/products', $base + ['sku' => 'NUEVO-1', 'minimum_price' => 6], $this->h)->assertUnprocessable()->assertJsonValidationErrors('minimum_price');
        $code = DB::table('products')->whereNotNull('barcode')->where('company_id', $this->admin->company_id)->value('barcode');
        $this->postJson('/api/v1/products', $base + ['sku' => 'NUEVO-2', 'barcode' => $code], $this->h)->assertUnprocessable()->assertJsonValidationErrors('barcode');
        // Editar el propio producto conserva su SKU sin chocar consigo mismo.
        $this->putJson("/api/v1/products/{$this->product}", ['sku' => 'DSC-1', 'name' => 'Producto descuento', 'category' => $base['category'], 'unit_type' => 'unit', 'sale_price' => 11, 'minimum_price' => 8], $this->h)->assertOk();
    }

    public function test_server_operations_are_reserved_to_the_platform_admin(): void
    {
        foreach (['/api/v1/maintenance/logs', '/api/v1/maintenance/status', '/api/v1/maintenance/jobs'] as $url) {
            $this->getJson($url, $this->h)->assertForbidden();
        }
        $this->postJson('/api/v1/maintenance/mode', ['enabled' => true], $this->h)->assertForbidden();
        $this->getJson('/api/v1/backups', $this->h)->assertForbidden();

        $this->admin->update(['role' => 'platform_admin']);
        $this->getJson('/api/v1/maintenance/status', $this->h)->assertOk();
        $this->getJson('/api/v1/backups', $this->h)->assertOk();
    }

    public function test_company_admins_cannot_see_or_take_over_the_platform_admin(): void
    {
        $platform = User::create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'name' => 'Super', 'email' => 'super@example.test', 'password' => Hash::make('Super12345'), 'role' => 'platform_admin', 'status' => 'active']);
        DB::table('role_user')->insert(['role_id' => DB::table('roles')->where(['company_id' => $this->admin->company_id, 'slug' => 'admin'])->value('id'), 'user_id' => $platform->id]);

        $this->assertNotContains('super@example.test', collect($this->getJson('/api/v1/admin/users', $this->h)->assertOk()->json('data'))->pluck('email'));
        $this->putJson("/api/v1/admin/users/{$platform->id}/password", ['password' => 'Robada12345', 'password_confirmation' => 'Robada12345'], $this->h)->assertNotFound();
        $this->putJson("/api/v1/admin/users/{$platform->id}", ['name' => 'X', 'email' => 'super@example.test', 'role' => 'admin', 'branch_id' => $this->admin->branch_id, 'status' => 'inactive'], $this->h)->assertNotFound();
        $this->assertSame('platform_admin', $platform->fresh()->role);

        // El propio superadministrador sí se ve y, al guardar, conserva su marca.
        $ph = $this->auth('super@example.test', 'Super12345');
        $this->putJson("/api/v1/admin/users/{$platform->id}", ['name' => 'Super Admin', 'email' => 'super@example.test', 'role' => 'admin', 'branch_id' => $this->admin->branch_id], $ph)->assertForbidden()->assertJsonPath('code', 'platform_read_only');
        $this->postJson('/api/v1/platform/write-mode', ['reason' => 'Actualizo mi nombre de usuario'], $ph)->assertOk();
        $this->putJson("/api/v1/admin/users/{$platform->id}", ['name' => 'Super Admin', 'email' => 'super@example.test', 'role' => 'admin', 'branch_id' => $this->admin->branch_id], $ph)->assertOk();
        $this->assertSame('platform_admin', $platform->fresh()->role);
        $this->getJson('/api/v1/me', $ph)->assertJsonPath('data.is_platform', true);
    }

    public function test_the_platform_admin_has_full_access_whatever_the_role(): void
    {
        [$user, $h] = $this->userWith(['sales.view'], 'limitado@example.test');
        $this->getJson('/api/v1/admin/settings', $h)->assertForbidden();
        $this->assertSame(['sales.view'], $this->getJson('/api/v1/me', $h)->json('data.permissions'));

        $user->update(['role' => 'platform_admin']);
        $this->getJson('/api/v1/admin/settings', $h)->assertOk();
        $this->assertTrue($this->getJson('/api/v1/me', $h)->json('data.can')['sales.override_price']);
        $this->assertContains('settings.view', $this->getJson('/api/v1/me', $h)->json('data.permissions'));
    }

    public function test_a_cashier_without_discount_permission_cannot_discount(): void
    {
        [, $h] = $this->userWith(['sales.view', 'sales.create', 'cash.view', 'cash.create'], 'cajera@example.test');
        $register = DB::table('cash_registers')->insertGetId(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'code' => 'CAJA-T', 'name' => 'Caja test', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/v1/cash/open', ['opening_amount' => 50, 'cash_register_id' => $register], $h)->assertSuccessful();
        $this->sell(['quantity' => 1, 'discount' => 1], 9, [], $h)->assertForbidden();
        $this->sell(['quantity' => 1], 10, [], $h)->assertCreated();
    }
}
