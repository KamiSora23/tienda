<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ProductSupplierService
{
    public function syncConfirmedPurchase(int $companyId, object $purchase, iterable $details): void
    {
        if(!$purchase->supplier_id)return;
        DB::table('suppliers')->where(['id'=>$purchase->supplier_id,'company_id'=>$companyId,'status'=>'active'])->firstOrFail();
        foreach($details as $detail){
            DB::table('products')->where(['id'=>$detail->product_id,'company_id'=>$companyId])->firstOrFail();
            $hasPrimary=DB::table('product_suppliers')->where(['company_id'=>$companyId,'product_id'=>$detail->product_id,'is_primary'=>1,'status'=>'active'])->exists();
            $existing=DB::table('product_suppliers')->where(['company_id'=>$companyId,'product_id'=>$detail->product_id,'supplier_id'=>$purchase->supplier_id])->first();
            $values=['last_cost'=>$detail->effective_unit_cost,'last_purchase_at'=>$purchase->created_at??now(),'status'=>'active','updated_at'=>now()];
            if(!$existing)$values+=['is_primary'=>!$hasPrimary,'created_at'=>now()];
            DB::table('product_suppliers')->updateOrInsert(['company_id'=>$companyId,'product_id'=>$detail->product_id,'supplier_id'=>$purchase->supplier_id],$values);
        }
    }
}
