<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Controles del superadministrador: dos pasos, solo lectura, edición con motivo y auditoría marcada. */
class PlatformSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    private User $super;
    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@deurstore.pe')->first();
        $this->super = User::create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'name' => 'Super', 'email' => 'super@example.test', 'password' => Hash::make('Super12345'), 'role' => 'platform_admin', 'status' => 'active']);
        DB::table('role_user')->insert(['role_id' => DB::table('roles')->where(['company_id' => $admin->company_id, 'slug' => 'admin'])->value('id'), 'user_id' => $this->super->id]);
        $this->h = ['Authorization' => 'Bearer '.$this->loginToken('super@example.test', 'Super12345')];
    }

    public function test_the_platform_admin_always_needs_the_second_factor(): void
    {
        $this->postJson('/api/v1/login', ['email' => 'super@example.test', 'password' => 'Super12345'])
            ->assertOk()->assertJsonPath('data.two_factor_required', true)->assertJsonMissingPath('data.token');
        $this->assertTrue(DB::table('audit_events')->where(['user_id' => $this->super->id, 'event' => 'auth.login', 'by_platform' => true])->exists());
    }

    public function test_the_code_is_shown_on_screen_only_in_local_development_without_mail(): void
    {
        // Fuera de local (aquí, "testing") el código nunca viaja en la respuesta.
        $this->postJson('/api/v1/login', ['email' => 'super@example.test', 'password' => 'Super12345'])->assertJsonMissingPath('data.dev_code');

        $this->travel(61)->seconds(); // pasada la espera entre códigos
        $this->app['env'] = 'local';
        config(['mail.default' => 'log']);
        $code = $this->postJson('/api/v1/login', ['email' => 'super@example.test', 'password' => 'Super12345'])->assertOk()->json('data.dev_code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->postJson('/api/v1/two-factor/verify', ['email' => 'super@example.test', 'code' => $code])->assertOk()->assertJsonStructure(['data' => ['token']]);

        // Con correo real configurado, tampoco se muestra.
        $this->travel(61)->seconds();
        config(['mail.default' => 'smtp']);
        \Illuminate\Support\Facades\Mail::fake();
        $this->postJson('/api/v1/login', ['email' => 'super@example.test', 'password' => 'Super12345'])->assertJsonMissingPath('data.dev_code');
    }

    public function test_a_mail_failure_never_locks_the_login(): void
    {
        // SMTP inalcanzable (como una contraseña de Gmail errónea o sin internet).
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
        // Fuera de local: se abre la pantalla del código (para usar user:login-code), sin mostrar el código.
        $this->postJson('/api/v1/login', ['email' => 'super@example.test', 'password' => 'Super12345'])
            ->assertOk()->assertJsonPath('data.two_factor_required', true)->assertJsonPath('data.mail_failed', true)->assertJsonMissingPath('data.dev_code');

        $this->travel(61)->seconds();
        $this->app['env'] = 'local';
        $this->postJson('/api/v1/login', ['email' => 'super@example.test', 'password' => 'Super12345'])
            ->assertOk()->assertJsonPath('data.mail_failed', true)->assertJsonStructure(['data' => ['dev_code']]);
    }

    public function test_repeated_clicks_do_not_send_several_codes(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $first = $this->postJson('/api/v1/login', ['email' => 'super@example.test', 'password' => 'Super12345'])->assertOk();
        $this->assertSame(60, $first->json('data.resend_in'));
        $hash = $this->super->fresh()->two_factor_code;

        // Segundo y tercer clic dentro del minuto: mismo código, sin correo nuevo.
        $again = $this->postJson('/api/v1/login', ['email' => 'super@example.test', 'password' => 'Super12345'])->assertOk()->assertJsonPath('data.already_sent', true);
        $this->assertGreaterThan(0, $again->json('data.resend_in'));
        $this->postJson('/api/v1/login', ['email' => 'super@example.test', 'password' => 'Super12345'])->assertOk();
        $this->assertSame($hash, $this->super->fresh()->two_factor_code);

        // «Reenviar» antes de tiempo se rechaza; pasado el minuto, envía un código nuevo.
        $this->postJson('/api/v1/two-factor/resend', ['email' => 'super@example.test'])->assertStatus(429);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/two-factor/resend', ['email' => 'super@example.test'])->assertOk()->assertJsonPath('data.resend_in', 60);
        $this->assertNotSame($hash, $this->super->fresh()->two_factor_code);
    }

    public function test_resend_needs_a_pending_login(): void
    {
        $this->postJson('/api/v1/two-factor/resend', ['email' => 'super@example.test'])->assertUnprocessable();
        $this->postJson('/api/v1/two-factor/resend', ['email' => 'nadie@example.test'])->assertUnprocessable();
    }

    public function test_a_console_command_issues_a_fresh_code(): void
    {
        $this->artisan('user:login-code', ['email' => 'super@example.test'])->expectsOutputToContain('Código para super@example.test')->assertSuccessful();
        $this->assertTrue($this->super->fresh()->two_factor_expires_at->isFuture());
        $this->artisan('user:login-code', ['email' => 'nadie@example.test'])->assertFailed();
    }

    public function test_it_works_read_only_until_it_enables_editing_with_a_reason(): void
    {
        $this->getJson('/api/v1/products', $this->h)->assertOk();
        $this->postJson('/api/v1/admin/categories', ['name' => 'Soporte'], $this->h)->assertForbidden()->assertJsonPath('code', 'platform_read_only');
        $this->getJson('/api/v1/me', $this->h)->assertJsonPath('data.platform_write', null);

        $this->postJson('/api/v1/platform/write-mode', ['reason' => 'corto'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/platform/write-mode', ['reason' => 'Ticket 123: corregir categoría duplicada', 'minutes' => 15], $this->h)->assertOk();
        $this->assertSame('Ticket 123: corregir categoría duplicada', $this->getJson('/api/v1/me', $this->h)->json('data.platform_write.platform_write_reason'));
        $this->postJson('/api/v1/admin/categories', ['name' => 'Soporte'], $this->h)->assertCreated();

        $this->deleteJson('/api/v1/platform/write-mode', [], $this->h)->assertOk();
        $this->postJson('/api/v1/admin/categories', ['name' => 'Otra'], $this->h)->assertForbidden();
    }

    public function test_editing_expires_on_its_own(): void
    {
        $this->postJson('/api/v1/platform/write-mode', ['reason' => 'Ajuste puntual de soporte'], $this->h)->assertOk();
        DB::table('user_sessions')->where('user_id', $this->super->id)->update(['platform_write_until' => now()->subMinute()]);
        $this->postJson('/api/v1/admin/categories', ['name' => 'Tarde'], $this->h)->assertForbidden();
    }

    public function test_platform_tasks_and_company_switching_do_not_need_edit_mode(): void
    {
        $company = DB::table('companies')->insertGetId(['uuid' => \Illuminate\Support\Str::uuid(), 'business_name' => 'OTRA SAC', 'trade_name' => 'Otra', 'ruc' => '20111111111', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('branches')->insertGetId(['company_id' => $company, 'code' => 'P', 'name' => 'Principal', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/v1/context', ['company_id' => $company, 'branch_id' => $branch], $this->h)->assertOk();
        // Gestionar empresas es tarea propia de la plataforma: no lo bloquea el modo solo lectura.
        $this->assertNotSame(403, $this->putJson("/api/v1/admin/companies/{$company}/profile", ['business_name' => 'OTRA SAC', 'trade_name' => 'Otra'], $this->h)->status());
        $this->getJson('/api/v1/maintenance/status', $this->h)->assertOk();
        // Pero operar dentro de esa empresa sí requiere activar la edición.
        $this->postJson('/api/v1/admin/brands', ['name' => 'Marca X'], $this->h)->assertForbidden();
    }

    public function test_switching_company_returns_to_read_only_and_shows_only_that_company(): void
    {
        $company = DB::table('companies')->insertGetId(['uuid' => \Illuminate\Support\Str::uuid(), 'business_name' => 'OTRA SAC', 'trade_name' => 'Otra', 'ruc' => '20111111111', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('branches')->insertGetId(['company_id' => $company, 'code' => 'P', 'name' => 'Principal', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('categories')->insert(['company_id' => $company, 'name' => 'Solo de Otra', 'slug' => 'solo-de-otra', 'status' => 'active', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/v1/platform/write-mode', ['reason' => 'Soporte en la empresa de origen'], $this->h)->assertOk();
        $home = collect($this->getJson('/api/v1/admin/categories', $this->h)->json('data'))->pluck('name');
        $this->assertNotContains('Solo de Otra', $home);

        $this->postJson('/api/v1/context', ['company_id' => $company, 'branch_id' => $branch], $this->h)->assertOk();
        $this->assertSame(['Solo de Otra'], collect($this->getJson('/api/v1/admin/categories', $this->h)->json('data'))->pluck('name')->all());
        $this->getJson('/api/v1/me', $this->h)->assertJsonPath('data.company.trade_name', 'Otra')->assertJsonPath('data.platform_write', null);
        $this->postJson('/api/v1/admin/brands', ['name' => 'X'], $this->h)->assertForbidden();
    }

    public function test_its_actions_are_marked_in_the_company_audit_trail(): void
    {
        $this->postJson('/api/v1/platform/write-mode', ['reason' => 'Revisión solicitada por la tienda'], $this->h)->assertOk();
        $this->postJson('/api/v1/admin/categories', ['name' => 'Revisada'], $this->h)->assertCreated();
        $events = DB::table('audit_events')->where(['company_id' => $this->super->company_id, 'by_platform' => true])->pluck('event')->all();
        $this->assertContains('platform.write_enabled', $events);
        $this->assertContains('created', $events);
        $this->assertSame('Revisión solicitada por la tienda', json_decode(DB::table('audit_events')->where('event', 'platform.write_enabled')->value('after'), true)['reason']);
    }
}
