<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Correo propio por empresa: configuración cifrada, comprobantes y enlaces de recuperación. */
class CompanyMailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['mail.default' => 'array']);
        $this->admin = User::where('email', 'admin@deurstore.pe')->first();
        $this->h = ['Authorization' => 'Bearer '.$this->loginToken('admin@deurstore.pe', 'Deurstore2026!')];
    }

    /** Correos capturados por el transporte "array" del correo de la plataforma. */
    private function sent(): \Illuminate\Support\Collection
    {
        return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage());
    }

    private function settings(array $overrides = []): array
    {
        return $overrides + ['enabled' => true, 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'ventas@tienda.test', 'password' => 'abcd efgh ijkl mnop', 'from_address' => 'ventas@tienda.test', 'from_name' => 'Mi Tienda'];
    }

    public function test_the_password_is_stored_encrypted_and_never_returned(): void
    {
        $this->putJson('/api/v1/mail-settings', $this->settings(), $this->h)->assertOk();
        $stored = DB::table('company_mail_settings')->where('company_id', $this->admin->company_id)->value('password');
        $this->assertNotSame('abcdefghijklmnop', $stored);
        $this->assertSame('abcdefghijklmnop', Crypt::decryptString($stored), 'Se guarda sin los espacios con que la muestra Gmail.');

        $shown = $this->getJson('/api/v1/mail-settings', $this->h)->assertOk()->json('data');
        $this->assertArrayNotHasKey('password', $shown);
        $this->assertTrue($shown['has_password']);

        // Guardar sin escribir contraseña conserva la anterior.
        $this->putJson('/api/v1/mail-settings', $this->settings(['password' => null, 'from_name' => 'Otra']), $this->h)->assertOk();
        $this->assertSame($stored, DB::table('company_mail_settings')->where('company_id', $this->admin->company_id)->value('password'));
    }

    public function test_an_active_configuration_needs_server_and_sender(): void
    {
        $this->putJson('/api/v1/mail-settings', $this->settings(['host' => '', 'from_address' => '']), $this->h)->assertUnprocessable()->assertJsonValidationErrors(['host', 'from_address']);
        $this->putJson('/api/v1/mail-settings', $this->settings(['enabled' => false, 'host' => '', 'from_address' => '']), $this->h)->assertOk();
    }

    public function test_a_failing_server_is_reported_clearly_and_recorded(): void
    {
        $this->putJson('/api/v1/mail-settings', $this->settings(['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none']), $this->h)->assertOk();
        $this->postJson('/api/v1/mail-settings/test', $this->settings(['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none']), $this->h)
            ->assertUnprocessable()->assertJsonFragment(['message' => 'No se pudo conectar con el servidor de correo. Revisa el servidor, el puerto y el cifrado (587 con TLS o 465 con SSL).']);
        $this->assertFalse((bool) DB::table('company_mail_settings')->value('last_test_ok'));
    }

    public function test_a_sale_receipt_is_emailed_to_the_customer(): void
    {
        $p = DB::table('products')->where(['company_id' => $this->admin->company_id, 'unit_type' => 'unit'])->first();
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Ana Torres', 'document_type' => 'DNI', 'document_number' => '45678912'], $this->h)->json('data.id');
        $sale = $this->postJson('/api/v1/sales', ['customer_id' => $customer, 'items' => [['product_id' => $p->id, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 2 * $p->sale_price]]], $this->h)->assertCreated()->json('data');

        // Sin correo del cliente ni indicado: se pide.
        $this->postJson("/api/v1/sales/{$sale['id']}/email", [], $this->h)->assertUnprocessable();
        $this->postJson("/api/v1/sales/{$sale['id']}/email", ['email' => 'ana@example.test'], $this->h)->assertOk()->assertJsonPath('data.via', 'platform');

        $mail = $this->sent()->last();
        $this->assertSame('ana@example.test', $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString($sale['number'], $mail->getSubject());
        $this->assertStringContainsString($p->name, $mail->getHtmlBody());
        // El correo queda en la ficha del cliente para la próxima vez.
        $this->assertSame('ana@example.test', DB::table('customers')->where('id', $customer)->value('email'));
        $this->postJson("/api/v1/sales/{$sale['id']}/email", [], $this->h)->assertOk();
    }

    public function test_a_sale_of_another_company_cannot_be_emailed(): void
    {
        $other = DB::table('companies')->insertGetId(['uuid' => \Illuminate\Support\Str::uuid(), 'business_name' => 'OTRA SAC', 'trade_name' => 'Otra', 'ruc' => '20111111111', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('branches')->insertGetId(['company_id' => $other, 'code' => 'P', 'name' => 'P', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $sale = DB::table('sales')->insertGetId(['uuid' => \Illuminate\Support\Str::uuid(), 'company_id' => $other, 'branch_id' => $branch, 'user_id' => $this->admin->id, 'number' => 'X-1', 'document_type' => 'ticket', 'subtotal' => 1, 'discount' => 0, 'tax' => 0, 'total' => 1, 'status' => 'paid', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson("/api/v1/sales/{$sale}/email", ['email' => 'x@example.test'], $this->h)->assertNotFound();
    }

    public function test_password_reset_links_open_the_web_app_and_expire_after_30_minutes(): void
    {
        config(['app.frontend_url' => 'http://localhost:3000']);
        $this->postJson('/api/v1/forgot-password', ['email' => 'admin@deurstore.pe'])->assertOk();
        $body = $this->sent()->last()->getTextBody();
        $this->assertStringContainsString('http://localhost:3000/reset-password?email=', $body);
        preg_match('/token=([A-Za-z0-9]+)/', $body, $m);

        $this->travel(31)->minutes();
        $this->postJson('/api/v1/reset-password', ['email' => 'admin@deurstore.pe', 'token' => $m[1], 'password' => 'NuevaClave123', 'password_confirmation' => 'NuevaClave123'])->assertUnprocessable();
    }

    public function test_only_users_with_settings_permission_manage_the_mail(): void
    {
        $role = DB::table('roles')->insertGetId(['company_id' => $this->admin->company_id, 'name' => 'Caja', 'slug' => 'caja', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'name' => 'Caja', 'email' => 'caja@example.test', 'password' => bcrypt('Clave12345'), 'role' => 'caja', 'status' => 'active']);
        DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $user->id]);
        $h = ['Authorization' => 'Bearer '.$this->loginToken('caja@example.test', 'Clave12345')];
        $this->getJson('/api/v1/mail-settings', $h)->assertForbidden();
        $this->putJson('/api/v1/mail-settings', $this->settings(), $h)->assertForbidden();
    }
}
