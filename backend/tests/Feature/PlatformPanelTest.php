<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Panel de plataforma: vista global del superadministrador y correo general. */
class PlatformPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private array $super;
    private array $companyAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['mail.default' => 'array']);
        $this->admin = User::where('email', 'admin@deurstore.pe')->first();
        $user = User::create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'name' => 'Super', 'email' => 'super@example.test', 'password' => Hash::make('Super12345'), 'role' => 'platform_admin', 'status' => 'active']);
        DB::table('role_user')->insert(['role_id' => DB::table('roles')->where(['company_id' => $this->admin->company_id, 'slug' => 'admin'])->value('id'), 'user_id' => $user->id]);
        $this->super = ['Authorization' => 'Bearer '.$this->loginToken('super@example.test', 'Super12345')];
        $this->companyAdmin = ['Authorization' => 'Bearer '.$this->loginToken('admin@deurstore.pe', 'Deurstore2026!')];
    }

    public function test_only_the_platform_admin_opens_the_panel(): void
    {
        foreach (['/api/v1/platform/overview', '/api/v1/platform/activity', '/api/v1/platform/admins', '/api/v1/platform/mail'] as $url) {
            $this->getJson($url, $this->companyAdmin)->assertForbidden();
            $this->getJson($url, $this->super)->assertOk();
        }
    }

    public function test_overview_summarises_every_company(): void
    {
        $other = DB::table('companies')->insertGetId(['uuid' => Str::uuid(), 'business_name' => 'OTRA SAC', 'trade_name' => 'Otra', 'ruc' => '20111111111', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('branches')->insertGetId(['company_id' => $other, 'code' => 'P', 'name' => 'Principal', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales')->insert(['uuid' => Str::uuid(), 'company_id' => $other, 'branch_id' => $branch, 'user_id' => $this->admin->id, 'number' => 'T1', 'document_type' => 'ticket', 'subtotal' => 50, 'discount' => 0, 'tax' => 0, 'total' => 50, 'status' => 'paid', 'created_at' => now(), 'updated_at' => now()]);

        $data = $this->getJson('/api/v1/platform/overview', $this->super)->json('data');
        $row = collect($data['companies'])->firstWhere('id', $other);
        $this->assertSame(1, $row['sales_30d']);
        $this->assertEquals(50, $row['amount_30d']);
        $this->assertSame($branch, $row['main_branch_id']);
        $this->assertSame(2, $data['totals']['companies']);
        // Los superadministradores no se cuentan como usuarios de la empresa.
        $this->assertSame(1, collect($data['companies'])->firstWhere('id', $this->admin->company_id)['users']);
    }

    public function test_activity_lists_what_the_platform_admin_did_with_its_reason(): void
    {
        $this->postJson('/api/v1/platform/write-mode', ['reason' => 'Ticket 77: revisar categorías'], $this->super)->assertOk();
        $rows = collect($this->getJson('/api/v1/platform/activity', $this->super)->json('data'));
        $this->assertSame('Ticket 77: revisar categorías', $rows->firstWhere('event', 'platform.write_enabled')['reason']);
        $this->assertTrue($rows->every(fn ($r) => $r['user_name'] === 'Super'));
    }

    public function test_admins_lists_platform_accounts_only(): void
    {
        $this->assertSame(['super@example.test'], collect($this->getJson('/api/v1/platform/admins', $this->super)->json('data'))->pluck('email')->all());
    }

    public function test_platform_mail_is_managed_in_the_panel_and_used_by_companies_without_their_own(): void
    {
        $settings = ['enabled' => true, 'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'username' => 'sistema@plataforma.test', 'password' => 'secreta de prueba', 'from_address' => 'sistema@plataforma.test', 'from_name' => 'Plataforma'];
        // Guardar no necesita activar la edición: es tarea de plataforma.
        $this->putJson('/api/v1/platform/mail', $settings, $this->super)->assertOk();
        $this->assertSame('secretadeprueba', Crypt::decryptString(DB::table('platform_mail_settings')->value('password')));
        $this->assertArrayNotHasKey('password', $this->getJson('/api/v1/platform/mail', $this->super)->json('data'));
        $this->putJson('/api/v1/platform/mail', $settings, $this->companyAdmin)->assertForbidden();

        // Una empresa sin correo propio envía con el de la plataforma (aquí inalcanzable, así que falla).
        $p = DB::table('products')->where(['company_id' => $this->admin->company_id, 'unit_type' => 'unit'])->first();
        $sale = $this->postJson('/api/v1/sales', ['items' => [['product_id' => $p->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => (float) $p->sale_price]]], $this->companyAdmin)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/sales/{$sale}/email", ['email' => 'cliente@example.test'], $this->companyAdmin)->assertUnprocessable();

        // Desactivado, vuelve al correo de .env (aquí, el transporte "array").
        $this->putJson('/api/v1/platform/mail', ['enabled' => false] + $settings, $this->super)->assertOk();
        $this->postJson("/api/v1/sales/{$sale}/email", ['email' => 'cliente@example.test'], $this->companyAdmin)->assertOk()->assertJsonPath('data.via', 'platform');
    }
}
