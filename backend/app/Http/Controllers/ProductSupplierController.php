<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductSupplierController extends Controller
{
    private function product(Request $request,int $product): object{return DB::table('products')->where(['id'=>$product,'company_id'=>$request->user()->company_id])->firstOrFail();}
    private function supplier(Request $request,int $supplier): object{$row=DB::table('suppliers')->where(['id'=>$supplier,'company_id'=>$request->user()->company_id])->first();abort_unless($row,422,'Proveedor inválido');return $row;}
    public function index(Request $r,int $product){$this->product($r,$product);return response()->json(['success'=>true,'data'=>$this->rows($r->user()->company_id,$product)]);}
    public function store(Request $r,int $product){$this->product($r,$product);$d=$r->validate(['supplier_id'=>'required|integer','supplier_sku'=>'nullable|string|max:100','minimum_order_quantity'=>'nullable|numeric|min:0','lead_time_days'=>'nullable|integer|min:0','status'=>'nullable|in:active,inactive']);$this->supplier($r,$d['supplier_id']);$company=$r->user()->company_id;$exists=DB::table('product_suppliers')->where(['company_id'=>$company,'product_id'=>$product,'supplier_id'=>$d['supplier_id']])->exists();abort_if($exists,422,'Este proveedor ya está asociado al producto');$primary=!DB::table('product_suppliers')->where(['company_id'=>$company,'product_id'=>$product,'is_primary'=>1,'status'=>'active'])->exists();$id=DB::table('product_suppliers')->insertGetId($d+['company_id'=>$company,'product_id'=>$product,'is_primary'=>$primary,'status'=>$d['status']??'active','created_at'=>now(),'updated_at'=>now()]);return response()->json(['success'=>true,'message'=>'Proveedor asociado','data'=>['id'=>$id]],201);}
    public function update(Request $r,int $product,int $supplier){$this->product($r,$product);$this->supplier($r,$supplier);$d=$r->validate(['supplier_sku'=>'nullable|string|max:100','minimum_order_quantity'=>'nullable|numeric|min:0','lead_time_days'=>'nullable|integer|min:0','status'=>'required|in:active,inactive']);DB::table('product_suppliers')->where(['company_id'=>$r->user()->company_id,'product_id'=>$product,'supplier_id'=>$supplier])->update($d+['updated_at'=>now()]);return response()->json(['success'=>true,'message'=>'Relación actualizada']);}
    public function primary(Request $r,int $product,int $supplier){$this->product($r,$product);$this->supplier($r,$supplier);$company=$r->user()->company_id;return DB::transaction(function()use($company,$product,$supplier){$target=DB::table('product_suppliers')->where(['company_id'=>$company,'product_id'=>$product,'supplier_id'=>$supplier])->firstOrFail();DB::table('product_suppliers')->where(['company_id'=>$company,'product_id'=>$product])->update(['is_primary'=>0,'updated_at'=>now()]);DB::table('product_suppliers')->where('id',$target->id)->update(['is_primary'=>1,'status'=>'active','updated_at'=>now()]);return response()->json(['success'=>true,'message'=>'Proveedor principal actualizado']);});}
    private function rows(int $company,int $product){return DB::table('product_suppliers as ps')->join('suppliers as s','s.id','=','ps.supplier_id')->where(['ps.company_id'=>$company,'ps.product_id'=>$product])->select('ps.*','s.name as supplier_name','s.document_number')->orderByDesc('ps.is_primary')->orderBy('s.name')->get();}
}
