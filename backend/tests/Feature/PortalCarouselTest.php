<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Carrusel de portada y productos destacados del portal. */
class PortalCarouselTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake();
        $this->admin = User::where('email', 'admin@deurstore.pe')->first();
        DB::table('settings')->updateOrInsert(['company_id' => $this->admin->company_id, 'group' => 'portal', 'key' => 'enabled'], ['value' => '1']);
        $this->h = ['Authorization' => 'Bearer '.$this->postJson('/api/v1/login', ['email' => 'admin@deurstore.pe', 'password' => 'Deurstore2026!'])->json('data.token')];
    }

    private function product(): object
    {
        return DB::table('products')->where(['company_id' => $this->admin->company_id, 'status' => 'active'])->orderBy('id')->first();
    }

    public function test_slides_are_managed_and_shown_in_order_within_their_dates(): void
    {
        $p = $this->product();
        $first = $this->post('/api/v1/portal-content/banners', ['title' => 'Semana del arroz', 'subtitle' => '10% menos', 'product_id' => $p->id, 'sort_order' => 2, 'image' => UploadedFile::fake()->image('banner.jpg', 1600, 600)], $this->h + ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/portal-content/banners', ['title' => 'Lácteos frescos', 'category' => DB::table('categories')->where('company_id', $this->admin->company_id)->value('name'), 'sort_order' => 1], $this->h)->assertCreated();
        $this->postJson('/api/v1/portal-content/banners', ['title' => 'Vencida', 'sort_order' => 0, 'ends_at' => now()->subDay()->toDateTimeString()], $this->h)->assertCreated();
        $this->postJson('/api/v1/portal-content/banners', ['title' => 'Oculta', 'active' => false], $this->h)->assertCreated();

        $slides = $this->getJson('/api/v1/portal')->assertOk()->json('data.banners');
        $this->assertSame(['Lácteos frescos', 'Semana del arroz'], array_column($slides, 'title'));
        $this->assertSame($p->id, $slides[1]['product']['id']);
        $this->assertStringEndsWith("/portal/banner-images/{$first}", $slides[1]['image']);
        $this->get("/api/v1/portal/banner-images/{$first}")->assertOk();

        $this->deleteJson("/api/v1/portal-content/banners/{$first}", [], $this->h)->assertOk();
        $this->get("/api/v1/portal/banner-images/{$first}")->assertNotFound();
    }

    public function test_without_own_slides_active_promotions_fill_the_carousel(): void
    {
        DB::table('promotions')->insert(['company_id' => $this->admin->company_id, 'name' => 'Feria de ofertas', 'type' => 'percentage', 'value' => 15, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(3), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $slides = $this->getJson('/api/v1/portal')->json('data.banners');
        $this->assertSame('Feria de ofertas', $slides[0]['title']);
        $this->assertStringStartsWith('15% de descuento', $slides[0]['subtitle']);
    }

    public function test_slides_cannot_point_to_another_company(): void
    {
        $other = DB::table('companies')->insertGetId(['uuid' => Str::uuid(), 'business_name' => 'OTRA SAC', 'trade_name' => 'Otra', 'ruc' => '20111111111', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $foreign = DB::table('products')->insertGetId(['uuid' => Str::uuid(), 'company_id' => $other, 'sku' => 'X1', 'name' => 'Ajeno', 'category' => 'General', 'unit_type' => 'unit', 'sale_price' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/v1/portal-content/banners', ['title' => 'X', 'product_id' => $foreign], $this->h)->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->putJson('/api/v1/portal-content/featured', ['product_ids' => [$foreign]], $this->h)->assertUnprocessable();
    }

    public function test_featured_products_keep_their_order_and_only_show_with_stock(): void
    {
        $ids = DB::table('products')->where(['company_id' => $this->admin->company_id, 'status' => 'active'])->orderBy('id')->limit(3)->pluck('id')->all();
        $this->putJson('/api/v1/portal-content/featured', ['product_ids' => [$ids[2], $ids[0], $ids[1]]], $this->h)->assertOk();
        DB::table('stocks')->where('product_id', $ids[1])->update(['quantity' => 0]);

        $rail = collect($this->getJson('/api/v1/portal/catalog?featured=1')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertSame([$ids[2], $ids[0]], $rail);

        $this->putJson('/api/v1/portal-content/featured', ['product_ids' => []], $this->h)->assertOk();
        $this->assertSame([], $this->getJson('/api/v1/portal/catalog?featured=1')->json('data.data'));
    }

    public function test_managing_the_carousel_requires_the_portal_permission(): void
    {
        $role = DB::table('roles')->insertGetId(['company_id' => $this->admin->company_id, 'name' => 'Ventas', 'slug' => 'ventas', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'name' => 'Vendedor', 'email' => 'v@example.test', 'password' => bcrypt('Clave12345'), 'role' => 'ventas', 'status' => 'active']);
        DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $user->id]);
        $h = ['Authorization' => 'Bearer '.$this->postJson('/api/v1/login', ['email' => 'v@example.test', 'password' => 'Clave12345'])->json('data.token')];
        $this->getJson('/api/v1/portal-content', $h)->assertForbidden();
        $this->postJson('/api/v1/portal-content/banners', ['title' => 'X'], $h)->assertForbidden();
    }
}
