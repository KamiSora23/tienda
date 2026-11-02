<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Hallazgos de la revisión completa del sistema (catálogo, promociones, caja, ventas, configuración). */
class SystemReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@deurstore.pe')->first();
        $token = $this->postJson('/api/v1/login', ['email' => 'admin@deurstore.pe', 'password' => 'Deurstore2026!'])->assertOk()->json('data.token');
        $this->headers = ['Authorization' => 'Bearer '.$token];
    }

    private function product(): object
    {
        return DB::table('products')->where('company_id', $this->admin->company_id)->where('unit_type', 'unit')->first();
    }

    public function test_previously_broken_screens_load(): void
    {
        $this->getJson("/api/v1/admin/users/{$this->admin->id}/profile", $this->headers)->assertOk()->assertJsonStructure(['data' => ['permissions']]);
        $this->getJson("/api/v1/branches/{$this->admin->branch_id}/detail", $this->headers)->assertOk()->assertJsonStructure(['data' => ['warehouses']]);
    }

    public function test_non_numeric_ids_return_404_instead_of_a_server_error(): void
    {
        $this->postJson('/api/v1/sales/undefined/cancel', ['reason' => 'x'], $this->headers)->assertNotFound();
    }

    public function test_category_names_are_unique_per_company(): void
    {
        $existing = DB::table('categories')->where('company_id', $this->admin->company_id)->value('name');
        $this->postJson('/api/v1/admin/categories', ['name' => mb_strtoupper($existing), 'status' => 'active'], $this->headers)->assertUnprocessable();
    }

    public function test_subcategories_show_their_parent_by_name_and_only_hang_from_own_main_categories(): void
    {
        $main = DB::table('categories')->where('company_id', $this->admin->company_id)->first();
        $child = $this->postJson('/api/v1/admin/categories', ['name' => 'Gaseosas', 'parent_id' => $main->id], $this->headers)->assertCreated()->json('data.id');
        $row = collect($this->getJson('/api/v1/admin/categories', $this->headers)->json('data'))->firstWhere('id', $child);
        $this->assertSame($main->name, $row['parent_name']);

        $this->postJson('/api/v1/admin/categories', ['name' => 'Nieta', 'parent_id' => $child], $this->headers)->assertUnprocessable();
        $this->putJson("/api/v1/admin/categories/{$main->id}", ['name' => $main->name, 'parent_id' => $main->id], $this->headers)->assertUnprocessable();
    }

    public function test_histories_show_names_instead_of_internal_ids(): void
    {
        $p = $this->product();
        $this->postJson('/api/v1/sales', ['items' => [['product_id' => $p->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => $p->sale_price]]], $this->headers)->assertCreated();
        $row = $this->getJson('/api/v1/histories/sales', $this->headers)->assertOk()->json('data.0');
        $this->assertSame($this->admin->name, $row['user_name']);
        $this->assertArrayNotHasKey('branch_id', $row);
        foreach (['purchases', 'prices', 'costs', 'stock', 'cash', 'users', 'promotions', 'orders'] as $type) {
            $this->getJson("/api/v1/histories/{$type}", $this->headers)->assertOk();
        }
    }

    public function test_each_company_keeps_its_own_app_colors(): void
    {
        $this->getJson('/api/v1/me', $this->headers)->assertJsonPath('data.appearance.primary', '#168555');

        $this->postJson('/api/v1/admin/settings', ['settings' => ['appearance' => ['mode' => 'light', 'primary' => '#1f6fd1', 'sidebar' => '#13233f', 'background' => '#f3f6fb', 'panel' => '#ffffff']]], $this->headers)->assertOk();
        $this->getJson('/api/v1/me', $this->headers)->assertJsonPath('data.appearance.primary', '#1f6fd1')->assertJsonPath('data.appearance.sidebar', '#13233f');

        // Otra empresa no ve los colores de esta.
        $other = DB::table('companies')->insertGetId(['uuid' => Str::uuid(), 'business_name' => 'OTRA SAC', 'trade_name' => 'Otra', 'ruc' => '20111111111', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('#168555', \App\Support\Appearance::for($other)['primary']);

        $this->postJson('/api/v1/admin/settings', ['settings' => ['appearance' => ['primary' => 'red']]], $this->headers)->assertUnprocessable();
        $this->postJson('/api/v1/admin/settings', ['settings' => ['appearance' => ['mode' => 'neon']]], $this->headers)->assertUnprocessable();
    }

    public function test_sessions_hide_token_hashes_drop_expired_ones_and_can_close_the_others(): void
    {
        $this->postJson('/api/v1/login', ['email' => 'admin@deurstore.pe', 'password' => 'Deurstore2026!'])->assertOk();
        DB::table('user_sessions')->insert(['user_id' => $this->admin->id, 'token_hash' => hash('sha256', 'viejo'), 'ip_address' => '1.1.1.1', 'user_agent' => 'node', 'last_activity_at' => now()->subDays(40), 'expires_at' => now()->subDay(), 'created_at' => now()->subDays(40), 'updated_at' => now()]);

        $sessions = $this->getJson('/api/v1/sessions', $this->headers)->assertOk()->json('data');
        $this->assertCount(2, $sessions, 'La sesión vencida no debe listarse.');
        $this->assertArrayNotHasKey('token_hash', $sessions[0]);
        $this->assertTrue($sessions[0]['current'], 'La sesión actual va primero.');

        $this->deleteJson('/api/v1/sessions/others', [], $this->headers)->assertOk()->assertJsonPath('data.closed', 1);
        $this->assertCount(1, $this->getJson('/api/v1/sessions', $this->headers)->assertOk()->json('data'));
    }

    public function test_a_percentage_promotion_cannot_exceed_100(): void
    {
        $this->postJson('/api/v1/promotions', ['name' => 'X', 'type' => 'percentage', 'value' => 150, 'starts_at' => now()->toDateTimeString(), 'ends_at' => now()->addDay()->toDateTimeString(), 'status' => 'active'], $this->headers)
            ->assertUnprocessable()->assertJsonValidationErrors('value');
    }

    public function test_identity_documents_follow_their_format(): void
    {
        $this->postJson('/api/v1/customers', ['name' => 'X', 'document_type' => 'RUC', 'document_number' => '123'], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('document_number');
        $this->postJson('/api/v1/customers', ['name' => 'X', 'document_type' => 'DNI', 'document_number' => '1234567'], $this->headers)->assertUnprocessable();
        $this->postJson('/api/v1/customers', ['name' => 'Andina', 'document_type' => 'RUC', 'document_number' => '20512345678'], $this->headers)->assertSuccessful();
    }

    public function test_inactive_products_cannot_be_sold(): void
    {
        $p = $this->product();
        DB::table('products')->where('id', $p->id)->update(['status' => 'inactive']);
        $this->postJson('/api/v1/sales', ['items' => [['product_id' => $p->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => $p->sale_price]]], $this->headers)->assertUnprocessable();
    }

    public function test_configuration_validates_codes_and_never_points_to_another_company(): void
    {
        $this->postJson('/api/v1/configuration/payment-methods', ['name' => 'Efectivo 2', 'code' => 'cash'], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/configuration/document-series', ['branch_id' => $this->admin->branch_id, 'document_type' => 'ticket', 'series' => 'T001'], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('series');
        $other = DB::table('companies')->insertGetId(['uuid' => Str::uuid(), 'business_name' => 'OTRA SAC', 'trade_name' => 'Otra', 'ruc' => '20111111111', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = DB::table('branches')->insertGetId(['company_id' => $other, 'code' => 'AJ', 'name' => 'Ajena', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/v1/configuration/printers', ['branch_id' => $foreign, 'name' => 'Intrusa', 'type' => 'ticket'], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_a_series_counter_never_goes_back(): void
    {
        $series = DB::table('document_series')->where(['branch_id' => $this->admin->branch_id, 'document_type' => 'ticket'])->first();
        DB::table('document_series')->where('id', $series->id)->update(['current_number' => 25]);
        $this->putJson("/api/v1/configuration/document-series/{$series->id}", ['branch_id' => $this->admin->branch_id, 'document_type' => 'ticket', 'series' => $series->series, 'current_number' => 3], $this->headers)->assertUnprocessable();
    }

    public function test_cash_withdrawals_cannot_exceed_the_cash_on_hand(): void
    {
        $expected = $this->getJson('/api/v1/cash', $this->headers)->assertOk()->json('data.expected_amount');
        $this->postJson('/api/v1/cash/movements', ['type' => 'withdrawal', 'amount' => $expected + 1, 'description' => 'Retiro'], $this->headers)->assertUnprocessable();
        $this->postJson('/api/v1/cash/movements', ['type' => 'withdrawal', 'amount' => min(1, $expected), 'description' => 'Retiro'], $this->headers)->assertCreated();
    }

    public function test_a_return_of_a_cash_sale_takes_the_money_out_of_the_cash_register(): void
    {
        $p = $this->product();
        $sale = $this->postJson('/api/v1/sales', ['items' => [['product_id' => $p->id, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 2 * $p->sale_price]]], $this->headers)->assertCreated()->json('data.id');
        $before = $this->getJson('/api/v1/cash', $this->headers)->json('data.expected_amount');
        $detail = DB::table('sale_details')->where('sale_id', $sale)->value('id');

        $this->postJson("/api/v1/sales/{$sale}/return", ['reason' => 'Devuelve uno', 'items' => [['sale_detail_id' => $detail, 'quantity' => 1]]], $this->headers)->assertCreated();

        $this->assertEqualsWithDelta($before - $p->sale_price, $this->getJson('/api/v1/cash', $this->headers)->json('data.expected_amount'), 0.01);
    }
}
