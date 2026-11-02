<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaleDiscountTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;
    private object $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $token = $this->postJson('/api/v1/login', ['email' => 'admin@deurstore.pe', 'password' => 'Deurstore2026!'])->assertOk()->json('data.token');
        $this->headers = ['Authorization' => 'Bearer '.$token];
        $this->product = DB::table('products')->where('unit_type', 'unit')->first();
    }

    private function sale(array $item, float $paid, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/sales', $extra + [
            'items' => [$item + ['product_id' => $this->product->id]],
            'payments' => [['method' => 'cash', 'amount' => $paid]],
        ], $this->headers);
    }

    private function revokeDiscountPermission(): void
    {
        $role = DB::table('role_user')->where('user_id', User::first()->id)->value('role_id');
        $permission = DB::table('permissions')->where('slug', 'sales.discount')->value('id');
        DB::table('permission_role')->where(['role_id' => $role, 'permission_id' => $permission])->delete();
    }

    public function test_owner_can_discount_a_line_and_it_is_recorded_and_audited(): void
    {
        $price = (float) $this->product->sale_price;
        $sale = $this->sale(['quantity' => 2, 'discount' => 1.00], 2 * $price - 1, ['discount_reason' => 'Cliente frecuente'])
            ->assertCreated()->json('data');

        $detail = DB::table('sale_details')->where('sale_id', $sale['id'])->first();
        $this->assertEquals(1.00, (float) $detail->discount);
        $this->assertEquals(2 * $price - 1, (float) $detail->total);
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'subtotal' => 2 * $price, 'discount' => 1.00, 'total' => 2 * $price - 1]);
        $this->assertDatabaseHas('audit_events', ['event' => 'sale.discount_applied', 'entity_id' => $sale['id']]);
    }

    public function test_line_discount_cannot_exceed_the_line_amount(): void
    {
        $price = (float) $this->product->sale_price;
        $this->sale(['quantity' => 1, 'discount' => $price + 1], 0.01)->assertUnprocessable();
    }

    public function test_discounts_require_permission_but_normal_sales_do_not(): void
    {
        $this->revokeDiscountPermission();
        $price = (float) $this->product->sale_price;

        $this->sale(['quantity' => 1, 'discount' => 0.50], $price - 0.5)->assertForbidden();
        $this->sale(['quantity' => 1], $price - 0.5, ['discount' => 0.50])->assertForbidden();
        $this->sale(['quantity' => 1], $price)->assertCreated();
        $this->assertFalse($this->getJson('/api/v1/me', $this->headers)->assertOk()->json('data.can')['sales.discount']);
    }

    public function test_returning_a_discounted_line_refunds_what_was_actually_charged(): void
    {
        $price = (float) $this->product->sale_price;
        $sale = $this->sale(['quantity' => 2, 'discount' => 1.00], 2 * $price - 1)->assertCreated()->json('data');
        $detail = DB::table('sale_details')->where('sale_id', $sale['id'])->first();

        $this->postJson("/api/v1/sales/{$sale['id']}/return", ['reason' => 'Devolución', 'items' => [['sale_detail_id' => $detail->id, 'quantity' => 1]]], $this->headers)
            ->assertSuccessful();

        $this->assertEquals(round((2 * $price - 1) / 2, 2), (float) DB::table('sale_return_details')->where('sale_detail_id', $detail->id)->value('total'));
    }

    public function test_me_reports_sensitive_permissions(): void
    {
        $can = $this->getJson('/api/v1/me', $this->headers)->assertOk()->json('data.can');
        $this->assertTrue($can['sales.discount']);
        $this->assertFalse($can['sales.override_price']);
    }
}
