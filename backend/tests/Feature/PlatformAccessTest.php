<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformAccessTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        $token = Str::random(64);
        $user->update(['api_token' => hash('sha256', $token)]);
        return $token;
    }

    private function secondCompany(): array
    {
        $company = DB::table('companies')->insertGetId([
            'uuid' => Str::uuid(), 'business_name' => 'OTRA SAC', 'trade_name' => 'Otra',
            'ruc' => '20111111111', 'currency' => 'PEN', 'timezone' => 'America/Lima',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = DB::table('branches')->insertGetId([
            'company_id' => $company, 'code' => 'OTR-01', 'name' => 'Otra sede',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$company, $branch];
    }

    public function test_company_admin_cannot_switch_to_another_company(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$company, $branch] = $this->secondCompany();
        $user = User::where('email', 'admin@deurstore.pe')->firstOrFail();

        $this->postJson('/api/v1/context', ['company_id' => $company, 'branch_id' => $branch], [
            'Authorization' => 'Bearer '.$this->tokenFor($user),
        ])->assertForbidden();

        $this->assertNotSame($company, $user->fresh()->company_id);
    }

    public function test_platform_admin_can_switch_to_another_company(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$company, $branch] = $this->secondCompany();
        $user = User::where('email', 'admin@deurstore.pe')->firstOrFail();
        $user->update(['role' => 'platform_admin']);

        $this->postJson('/api/v1/context', ['company_id' => $company, 'branch_id' => $branch], [
            'Authorization' => 'Bearer '.$this->tokenFor($user),
        ])->assertOk();

        $this->assertSame($company, $user->fresh()->company_id);
    }
}
