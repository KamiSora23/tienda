<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_sku')->nullable();
            $table->decimal('last_cost',14,4)->nullable();
            $table->timestamp('last_purchase_at')->nullable();
            $table->decimal('minimum_order_quantity',14,3)->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['company_id','product_id','supplier_id']);
            $table->index(['company_id','product_id','is_primary']);
        });

        $rows=DB::table('purchase_details as d')->join('purchases as p','p.id','=','d.purchase_id')
            ->where('p.status','confirmed')->whereNotNull('p.supplier_id')
            ->select('p.company_id','d.product_id','p.supplier_id','d.effective_unit_cost','p.created_at')
            ->orderBy('p.created_at')->get();
        foreach($rows as $row){
            $exists=DB::table('product_suppliers')->where(['company_id'=>$row->company_id,'product_id'=>$row->product_id])->exists();
            DB::table('product_suppliers')->updateOrInsert(
                ['company_id'=>$row->company_id,'product_id'=>$row->product_id,'supplier_id'=>$row->supplier_id],
                ['last_cost'=>$row->effective_unit_cost,'last_purchase_at'=>$row->created_at,'is_primary'=>!$exists,'status'=>'active','created_at'=>now(),'updated_at'=>now()]
            );
        }
    }

    public function down(): void { Schema::dropIfExists('product_suppliers'); }
};
