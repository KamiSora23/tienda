<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PromotionController extends Controller
{
    public function index(Request $request)
    {
        $company = $request->user()->company_id;
        $query = DB::table('promotions as p')
            ->leftJoin('promotion_usages as u', 'u.promotion_id', '=', 'p.id')
            ->where('p.company_id', $company)
            ->select('p.*', DB::raw('COUNT(u.id) as applications'), DB::raw('COALESCE(SUM(u.discount),0) as discount_total'))
            ->groupBy('p.id')
            ->latest('p.created_at');

        if ($request->filled('state')) {
            match ($request->state) {
                'active' => $query->where('p.status', 'active')->where('p.starts_at', '<=', now())->where('p.ends_at', '>=', now()),
                'scheduled' => $query->where('p.status', 'active')->where('p.starts_at', '>', now()),
                'expired' => $query->where('p.ends_at', '<', now()),
                'inactive' => $query->where('p.status', 'inactive'),
                default => null,
            };
        }

        return response()->json(['success' => true, 'data' => $query->get()->map(function ($row) {
            $row->state = $row->status !== 'active' ? 'inactive' : (now()->lt($row->starts_at) ? 'scheduled' : (now()->gt($row->ends_at) ? 'expired' : 'active'));
            $row->rules = json_decode($row->rules ?: '{}', true);
            return $row;
        })]);
    }

    public function show(Request $request, int $id)
    {
        $promotion = DB::table('promotions')->where(['id' => $id, 'company_id' => $request->user()->company_id])->firstOrFail();
        $promotion->rules = json_decode($promotion->rules ?: '{}', true);
        $promotion->product_ids = DB::table('promotion_product')->where('promotion_id', $id)->pluck('product_id');
        $promotion->category_ids = DB::table('promotion_category')->where('promotion_id', $id)->pluck('category_id');
        $promotion->brand_ids = DB::table('promotion_brand')->where('promotion_id', $id)->pluck('brand_id');
        $promotion->history = DB::table('promotion_usages as u')->join('sales as s', 's.id', '=', 'u.sale_id')
            ->leftJoin('customers as c', 'c.id', '=', 'u.customer_id')->where('u.promotion_id', $id)
            ->select('u.*', 's.number as sale_number', 'c.name as customer_name')->latest('u.created_at')->get();
        return response()->json(['success' => true, 'data' => $promotion]);
    }

    public function save(Request $request, ?int $id = null)
    {
        $company = $request->user()->company_id;
        $data = $request->validate([
            'name' => 'required|string|max:150', 'type' => 'required|in:percentage,fixed,buy_x_get_y,combo',
            'value' => ['required', 'numeric', 'min:0', $request->input('type') === 'percentage' ? 'max:100' : 'max:999999'], 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at',
            'status' => 'required|in:active,inactive', 'rules' => 'nullable|array',
            'product_ids' => 'nullable|array', 'product_ids.*' => 'integer',
            'category_ids' => 'nullable|array', 'category_ids.*' => 'integer',
            'brand_ids' => 'nullable|array', 'brand_ids.*' => 'integer',
        ]);

        return DB::transaction(function () use ($request, $company, $data, $id) {
            $payload = collect($data)->except(['product_ids', 'category_ids', 'brand_ids'])->all();
            $payload['rules'] = json_encode($payload['rules'] ?? []);
            $payload['company_id'] = $company;
            $payload['updated_at'] = now();
            if ($id) {
                DB::table('promotions')->where(['id' => $id, 'company_id' => $company])->firstOrFail();
                DB::table('promotions')->where('id', $id)->update($payload);
            } else {
                $payload['created_at'] = now();
                $id = DB::table('promotions')->insertGetId($payload);
            }
            foreach (['product' => 'product_ids', 'category' => 'category_ids', 'brand' => 'brand_ids'] as $relation => $field) {
                DB::table("promotion_{$relation}")->where('promotion_id', $id)->delete();
                $rows = collect($data[$field] ?? [])->unique()->map(fn ($related) => ['promotion_id' => $id, "{$relation}_id" => $related])->all();
                if ($rows) DB::table("promotion_{$relation}")->insert($rows);
            }
            $this->recordAudit($request, 'promotion.saved', 'promotion', $id, $data);
            return response()->json(['success' => true, 'message' => 'Promoción guardada', 'data' => ['id' => $id]]);
        });
    }
}
