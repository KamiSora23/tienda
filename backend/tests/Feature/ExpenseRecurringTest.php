<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExpenseRecurringTest extends TestCase
{
    use RefreshDatabase;

    private function headers(): array
    {
        $this->seed(DatabaseSeeder::class);
        $token = $this->postJson('/api/v1/login', [
            'email' => 'admin@deurstore.pe',
            'password' => 'Deurstore2026!',
        ])->assertOk()->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_expense_schema_supports_pending_and_recurring_obligations(): void
    {
        $this->headers();

        $this->assertTrue(Schema::hasTable('recurring_expenses'));
        foreach (['expense_type', 'financial_status', 'period', 'due_date', 'paid_at',
            'payment_method', 'payment_reference', 'recurring_expense_id', 'meter_reading_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('expenses', $column), "Missing expenses.$column");
        }
    }

    public function test_monthly_template_generates_one_editable_pending_expense_per_period(): void
    {
        $headers = $this->headers();

        $templateId = $this->postJson('/api/v1/recurring-expenses', [
            'name' => 'Internet empresarial',
            'category' => 'Servicios',
            'description' => 'Plan mensual',
            'usual_amount' => 129.90,
            'due_day' => 15,
            'starts_on' => '2026-01-01',
            'usual_payment_method' => 'transfer',
            'status' => 'active',
        ], $headers)->assertCreated()->json('data.id');

        $this->postJson('/api/v1/recurring-expenses/generate', ['period' => '2026-08'], $headers)
            ->assertOk()->assertJsonPath('data.created', 1);
        $this->postJson('/api/v1/recurring-expenses/generate', ['period' => '2026-08'], $headers)
            ->assertOk()->assertJsonPath('data.created', 0);

        $this->assertDatabaseHas('expenses', [
            'recurring_expense_id' => $templateId,
            'period' => '2026-08',
            'expense_type' => 'fixed',
            'financial_status' => 'pending',
            'amount' => 129.90,
            'due_date' => '2026-08-15',
        ]);
    }

    public function test_meter_reading_with_amount_creates_one_variable_pending_expense(): void
    {
        $headers = $this->headers();
        $company = DB::table('companies')->first();
        $branch = DB::table('branches')->where('company_id', $company->id)->first();
        $meter = DB::table('utility_meters')->insertGetId([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Luz local',
            'type' => 'electricity', 'unit' => 'kWh', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $reading = $this->postJson("/api/v1/meters/$meter/readings", [
            'reading_date' => '2026-08-09', 'value' => 120.500, 'amount' => 87.40,
        ], $headers)->assertCreated()->json('data.id');

        $this->assertDatabaseHas('expenses', [
            'meter_reading_id' => $reading,
            'expense_type' => 'variable_metered',
            'financial_status' => 'pending',
            'amount' => 87.40,
            'period' => '2026-08',
        ]);

        $this->postJson("/api/v1/meters/$meter/readings", [
            'reading_date' => '2026-08-10', 'value' => 100, 'amount' => 20,
        ], $headers)->assertUnprocessable();
    }

    public function test_confirming_pending_expense_records_exactly_one_cash_movement(): void
    {
        $headers = $this->headers();
        $user = DB::table('users')->where('email', 'admin@deurstore.pe')->first();
        $cash = DB::table('cash_sessions')->where(['company_id' => $user->company_id, 'branch_id' => $user->branch_id, 'status' => 'open'])->first();
        $expense = DB::table('expenses')->insertGetId([
            'company_id' => $user->company_id, 'branch_id' => $user->branch_id, 'user_id' => $user->id,
            'category' => 'Servicios', 'description' => 'Internet', 'amount' => 129.90,
            'expense_date' => '2026-08-01', 'due_date' => '2026-08-15', 'period' => '2026-08',
            'expense_type' => 'fixed', 'financial_status' => 'pending', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/expenses/$expense/confirm-payment", [
            'paid_at' => '2026-08-09', 'amount' => 130.50, 'payment_method' => 'cash',
            'payment_reference' => 'REC-100',
        ], $headers)->assertOk();

        $this->assertDatabaseHas('expenses', ['id' => $expense, 'financial_status' => 'paid', 'amount' => 130.50]);
        $this->assertDatabaseHas('cash_movements', [
            'cash_session_id' => $cash->id, 'type' => 'expense', 'amount' => 130.50, 'reference' => 'REC-100',
        ]);
        $this->postJson("/api/v1/expenses/$expense/confirm-payment", [
            'paid_at' => '2026-08-09', 'amount' => 130.50, 'payment_method' => 'cash',
        ], $headers)->assertUnprocessable();
        $this->assertSame(1, DB::table('cash_movements')->where('reference', 'REC-100')->count());
    }

    public function test_financial_dashboard_excludes_pending_expenses(): void
    {
        $headers = $this->headers();
        $user = DB::table('users')->where('email', 'admin@deurstore.pe')->first();
        DB::table('expenses')->insert([
            'company_id' => $user->company_id, 'branch_id' => $user->branch_id, 'user_id' => $user->id,
            'category' => 'Servicios', 'description' => 'Pendiente del mes', 'amount' => 999.00,
            'expense_date' => now()->toDateString(), 'period' => now()->format('Y-m'),
            'expense_type' => 'fixed', 'financial_status' => 'pending', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $dashboard = $this->getJson('/api/v1/dashboards/financial', $headers)->assertOk()->json('data.metrics');
        $expenses = collect($dashboard)->firstWhere('label', 'Gastos del mes');
        $this->assertEquals(0, (float) $expenses['value']);
    }
}
