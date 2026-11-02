<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** deurstore:install prepara un servidor nuevo sin datos de demostración. */
class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    private function install()
    {
        return $this->artisan('deurstore:install')
            ->expectsQuestion('Razón social de la empresa', 'MI TIENDA SAC')
            ->expectsQuestion('Nombre comercial', 'Mi Tienda')
            ->expectsQuestion('RUC (11 dígitos)', '20123456789')
            ->expectsQuestion('Nombre del administrador de la empresa', 'Ana Dueña')
            ->expectsQuestion('Correo del administrador', 'ana@mitienda.test')
            ->expectsQuestion('Contraseña del administrador (mínimo 10 caracteres)', 'ClaveSegura1')
            ->expectsQuestion('Correo del superadministrador de la plataforma', 'plataforma@mitienda.test')
            ->expectsQuestion('Contraseña del superadministrador (mínimo 12 caracteres)', 'ClaveSegura123');
    }

    public function test_a_fresh_server_gets_a_ready_company_admin_and_platform_admin(): void
    {
        $this->install()->assertSuccessful();

        $this->assertSame(1, DB::table('companies')->count());
        $this->assertSame(0, DB::table('products')->count(), 'Sin datos de demostración.');
        $this->assertTrue(DB::table('permissions')->where('slug', 'sales.discount')->exists());
        $this->assertSame('platform_admin', User::where('email', 'plataforma@mitienda.test')->value('role'));

        // El administrador puede ingresar y operar.
        $token = $this->postJson('/api/v1/login', ['email' => 'ana@mitienda.test', 'password' => 'ClaveSegura1'])->assertOk()->json('data.token');
        $this->getJson('/api/v1/products', ['Authorization' => "Bearer {$token}"])->assertOk();
        // El superadministrador entra con verificación en dos pasos.
        $this->postJson('/api/v1/login', ['email' => 'plataforma@mitienda.test', 'password' => 'ClaveSegura123'])->assertJsonPath('data.two_factor_required', true);
    }

    public function test_data_can_be_passed_in_one_line_and_only_passwords_are_asked(): void
    {
        $this->artisan('deurstore:install', ['--company' => 'MI TIENDA SAC', '--trade-name' => 'Mi Tienda', '--ruc' => '20123456789', '--admin-name' => 'Ana', '--admin-email' => 'ana@mitienda.test', '--platform-email' => 'plataforma@mitienda.test'])
            ->expectsQuestion('Contraseña del administrador (mínimo 10 caracteres)', 'ClaveSegura1')
            ->expectsQuestion('Contraseña del superadministrador (mínimo 12 caracteres)', 'ClaveSegura123')
            ->assertSuccessful();
        $this->assertSame('Mi Tienda', DB::table('companies')->value('trade_name'));
    }

    public function test_a_wrong_answer_is_asked_again_without_starting_over(): void
    {
        $this->artisan('deurstore:install', ['--company' => 'MI TIENDA SAC', '--trade-name' => 'Mi Tienda', '--ruc' => '123', '--admin-name' => 'Ana', '--admin-email' => 'ana@mitienda.test', '--platform-email' => 'plataforma@mitienda.test'])
            ->expectsOutputToContain('RUC (11 dígitos) debe tener 11 dígitos')
            ->expectsQuestion('RUC (11 dígitos)', '20123456789')
            ->expectsQuestion('Contraseña del administrador (mínimo 10 caracteres)', 'corta')
            ->expectsQuestion('Contraseña del administrador (mínimo 10 caracteres)', 'ClaveSegura1')
            ->expectsQuestion('Contraseña del superadministrador (mínimo 12 caracteres)', 'ClaveSegura123')
            ->assertSuccessful();
        $this->assertSame('20123456789', DB::table('companies')->value('ruc'));
    }

    public function test_a_password_can_be_changed_from_the_console_and_unlocks_the_user(): void
    {
        $this->install()->assertSuccessful();
        User::where('email', 'ana@mitienda.test')->update(['failed_login_attempts' => 5, 'locked_until' => now()->addMinutes(15)]);

        $this->artisan('user:password', ['email' => 'ana@mitienda.test'])
            ->expectsQuestion('Nueva contraseña para ana@mitienda.test (mínimo 10 caracteres)', 'corta')
            ->expectsQuestion('Nueva contraseña para ana@mitienda.test (mínimo 10 caracteres)', 'OtraClave123')
            ->expectsQuestion('Repite la contraseña', 'OtraClave123')
            ->assertSuccessful();

        $this->postJson('/api/v1/login', ['email' => 'ana@mitienda.test', 'password' => 'OtraClave123'])->assertOk()->assertJsonStructure(['data' => ['token']]);
        $this->artisan('user:password', ['email' => 'nadie@mitienda.test'])->assertFailed();
    }

    public function test_it_does_nothing_when_companies_already_exist(): void
    {
        $this->install()->assertSuccessful();
        $this->artisan('deurstore:install')->expectsOutputToContain('Ya hay empresas registradas')->assertSuccessful();
        $this->assertSame(1, DB::table('companies')->count());
    }
}
