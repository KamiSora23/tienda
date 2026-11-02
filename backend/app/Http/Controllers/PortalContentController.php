<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Contenido del portal: diapositivas del carrusel y productos destacados de la empresa. */
class PortalContentController extends Controller
{
    public function index(Request $r)
    {
        $c = $r->user()->company_id;
        $banners = DB::table('portal_banners as b')->leftJoin('products as p', 'p.id', '=', 'b.product_id')->leftJoin('promotions as pr', 'pr.id', '=', 'b.promotion_id')
            ->where('b.company_id', $c)->orderBy('b.sort_order')->orderBy('b.id')
            ->select('b.*', 'p.name as product_name', 'pr.name as promotion_name')->get()
            ->map(function ($b) { $b->image_url = $b->image_path ? url("/api/v1/portal/banner-images/{$b->id}") : null; unset($b->image_path); return $b; });
        $products = DB::table('products as p')->where(['p.company_id' => $c, 'p.status' => 'active'])
            ->select('p.id', 'p.name', 'p.category', 'p.sale_price', 'p.is_featured', 'p.featured_order', DB::raw('(select i.id from product_images i where i.product_id=p.id order by i.is_primary desc,i.sort_order,i.id limit 1) as image_id'))
            ->orderByDesc('p.is_featured')->orderBy('p.featured_order')->orderBy('p.name')->get()
            ->map(function ($p) { $p->image = $p->image_id ? url("/api/v1/portal/product-images/{$p->image_id}") : null; unset($p->image_id); return $p; });

        return response()->json(['success' => true, 'data' => [
            'banners' => $banners,
            'products' => $products,
            'promotions' => DB::table('promotions')->where(['company_id' => $c, 'status' => 'active'])->where('ends_at', '>=', now())->select('id', 'name', 'type', 'value', 'ends_at')->get(),
            'categories' => DB::table('categories')->where(['company_id' => $c, 'status' => 'active'])->orderBy('name')->pluck('name'),
        ]]);
    }

    public function save(Request $r, ?int $id = null)
    {
        $c = $r->user()->company_id;
        $banner = $id ? DB::table('portal_banners')->where(['id' => $id, 'company_id' => $c])->firstOrFail() : null;
        $d = $r->validate([
            'title' => 'required|string|max:120', 'subtitle' => 'nullable|string|max:255', 'button_label' => 'nullable|string|max:40',
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where(fn ($q) => $q->where('company_id', $c))],
            'promotion_id' => ['nullable', 'integer', Rule::exists('promotions', 'id')->where(fn ($q) => $q->where('company_id', $c))],
            'category' => ['nullable', 'string', Rule::exists('categories', 'name')->where(fn ($q) => $q->where('company_id', $c))],
            'sort_order' => 'nullable|integer|min:0|max:999', 'active' => 'nullable|boolean',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096', 'remove_image' => 'nullable|boolean',
        ], ['ends_at.after_or_equal' => 'La fecha de fin debe ser posterior al inicio.', 'image.max' => 'La imagen no puede pesar más de 4 MB.']);

        $data = collect($d)->except(['image', 'remove_image'])->all();
        $data['active'] = $r->boolean('active', true);
        $data['sort_order'] = (int) ($d['sort_order'] ?? 0);
        $data['updated_at'] = now();
        if ($r->hasFile('image') || $r->boolean('remove_image')) {
            if ($banner?->image_path && Storage::exists($banner->image_path)) Storage::delete($banner->image_path);
            $data['image_path'] = $r->hasFile('image') ? $r->file('image')->store("portal/{$c}/banners") : null;
        }
        if ($banner) DB::table('portal_banners')->where('id', $banner->id)->update($data);
        else $id = DB::table('portal_banners')->insertGetId($data + ['company_id' => $c, 'created_at' => now()]);
        $this->recordAudit($r, $banner ? 'portal.banner_updated' : 'portal.banner_created', 'portal', $id, collect($data)->except('image_path')->all());

        return response()->json(['success' => true, 'message' => 'Diapositiva guardada', 'data' => ['id' => $id]], $banner ? 200 : 201);
    }

    public function destroy(Request $r, int $id)
    {
        $banner = DB::table('portal_banners')->where(['id' => $id, 'company_id' => $r->user()->company_id])->firstOrFail();
        if ($banner->image_path && Storage::exists($banner->image_path)) Storage::delete($banner->image_path);
        DB::table('portal_banners')->where('id', $id)->delete();
        $this->recordAudit($r, 'portal.banner_deleted', 'portal', $id);

        return response()->json(['success' => true, 'message' => 'Diapositiva eliminada']);
    }

    /** Productos destacados en el orden recibido; los demás dejan de estar destacados. */
    public function featured(Request $r)
    {
        $c = $r->user()->company_id;
        $ids = $r->validate(['product_ids' => 'present|array|max:24', 'product_ids.*' => ['integer', Rule::exists('products', 'id')->where(fn ($q) => $q->where('company_id', $c))]], ['product_ids.max' => 'Puedes destacar hasta 24 productos.'])['product_ids'];
        DB::transaction(function () use ($c, $ids) {
            DB::table('products')->where('company_id', $c)->update(['is_featured' => false, 'featured_order' => 0]);
            foreach (array_values(array_unique($ids)) as $i => $id) DB::table('products')->where(['id' => $id, 'company_id' => $c])->update(['is_featured' => true, 'featured_order' => $i + 1]);
        });
        $this->recordAudit($r, 'portal.featured_updated', 'portal', null, ['product_ids' => $ids]);

        return response()->json(['success' => true, 'message' => 'Destacados actualizados']);
    }
}
