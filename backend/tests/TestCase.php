<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Inicia sesión completando la verificación en dos pasos cuando el usuario la requiere
     * (siempre en el superadministrador): fija un código conocido y lo confirma.
     */
    protected function loginToken(string $email, string $password): string
    {
        $login = $this->postJson('/api/v1/login', ['email' => $email, 'password' => $password])->assertOk();
        if (! $login->json('data.two_factor_required')) return $login->json('data.token');
        User::where('email', $email)->update(['two_factor_code' => hash('sha256', '123456'), 'two_factor_expires_at' => now()->addMinutes(5)]);

        return $this->postJson('/api/v1/two-factor/verify', ['email' => $email, 'code' => '123456'])->assertOk()->json('data.token');
    }
}
