<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryController extends Controller{
 
 private function warehouse(int$c,int$id):object{return DB::table('warehouses')->where(['id'=>$id,'company_id'=>$c])->firstOrFail();}
 private function product(int$c,int$id):object{return DB::table('products')->where(['id'=>$id,'company_id'=>$c])->firstOrFail();}
 public function lots(Request $r){[$c]=$this->ctx($r);return response()->json(['success'=>true,'data'=>DB::table('lots as l')->join('products as p',fn($j)=>$j->on('p.id','=','l.product_id')->on('p.company_id','=','l.company_id'))->join('warehouses as w',fn($j)=>$j->on('w.id','=','l.warehouse_id')->on('w.company_id','=','l.company_id'))->where('l.company_id',$c)->select('l.*','p.name as product_name','p.category','p.brand','w.name as warehouse_name')->latest('l.id')->get()]);}
public function saveLot(Request $r){
    [$c]=$this->ctx($r);
    $d=$r->validate(['warehouse_id'=>'required|integer','product_id'=>'required|integer','lot_number'=>'required|string|max:60','manufactured_at'=>'nullable|date','expires_at'=>'nullable|date','quantity'=>'required|numeric|min:0.001','unit_cost'=>'required|numeric|min:0']);
    $this->warehouse($c,$d['warehouse_id']);
    $product=$this->product($c,$d['product_id']);
    // Un lote manual identifica stock que ya existe sin lote: no crea mercadería nueva.
    return DB::transaction(function()use($c,$d,$product){
        $lots=app(\App\Services\LotService::class);
        $stock=(float)DB::table('stocks')->where(['company_id'=>$c,'warehouse_id'=>$d['warehouse_id'],'product_id'=>$product->id])->lockForUpdate()->value('quantity');
        $free=$stock-$lots->trackedQuantity($d['warehouse_id'],$product->id);
        abort_if($d['quantity']>$free+0.0005,422,'Solo hay '.rtrim(rtrim(number_format(max(0,$free),3,'.',''),'0'),'.')." {$product->name} sin lote en este almacén. Para mercadería nueva, registra la compra con su lote.");
        $id=$lots->receive($c,$d['warehouse_id'],$product->id,(float)$d['quantity'],$d['lot_number'],$d['expires_at']??null,(float)$d['unit_cost'],'lot_assignment',0);
        if(!empty($d['manufactured_at']))DB::table('lots')->where('id',$id)->update(['manufactured_at'=>$d['manufactured_at']]);
        return response()->json(['success'=>true,'message'=>'Lote registrado','data'=>['id'=>$id]],201);
    });
}
public function adjust(Request $r){
    [$c,,$u]=$this->ctx($r);
    $d=$r->validate(['warehouse_id'=>'required|integer','reason'=>'required','items'=>'required|array|min:1','items.*.product_id'=>'required|integer','items.*.new_quantity'=>'required|numeric|min:0']);
    $warehouse=$this->warehouse($c,$d['warehouse_id']);
    foreach($d['items'] as $i)$this->product($c,$i['product_id']);
    return DB::transaction(function()use($r,$c,$u,$d,$warehouse){
        $number='AJ-'.str_pad((string)(DB::table('inventory_adjustments')->where('company_id',$c)->count()+1),6,'0',STR_PAD_LEFT);
        $id=DB::table('inventory_adjustments')->insertGetId(['uuid'=>Str::uuid(),'company_id'=>$c,'branch_id'=>$warehouse->branch_id??$r->user()->branch_id,'warehouse_id'=>$warehouse->id,'user_id'=>$u,'number'=>$number,'reason'=>$d['reason'],'status'=>'confirmed','created_at'=>now(),'updated_at'=>now()]);
        foreach($d['items'] as $i){
            // Un producto puede no tener aún fila de stock en ese almacén (almacén nuevo): se crea.
            $stock=DB::table('stocks')->where(['company_id'=>$c,'warehouse_id'=>$warehouse->id,'product_id'=>$i['product_id']])->lockForUpdate()->first();
            $before=(float)($stock->quantity??0); $diff=$i['new_quantity']-$before;
            DB::table('stocks')->updateOrInsert(['company_id'=>$c,'warehouse_id'=>$warehouse->id,'product_id'=>$i['product_id']],['branch_id'=>$warehouse->branch_id,'quantity'=>$i['new_quantity'],'created_at'=>$stock->created_at??now(),'updated_at'=>now()]);
            DB::table('inventory_adjustment_details')->insert(['inventory_adjustment_id'=>$id,'product_id'=>$i['product_id'],'previous_quantity'=>$before,'new_quantity'=>$i['new_quantity'],'difference'=>$diff,'created_at'=>now(),'updated_at'=>now()]);
            if($diff<-0.0005)app(\App\Services\LotService::class)->consume($c,$warehouse->id,$i['product_id'],-$diff,'adjustment',$id);
            $p=$this->product($c,$i['product_id']);
            DB::table('inventory_movements')->insert(['uuid'=>Str::uuid(),'company_id'=>$c,'branch_id'=>$warehouse->branch_id??$r->user()->branch_id,'warehouse_id'=>$warehouse->id,'product_id'=>$i['product_id'],'user_id'=>$u,'type'=>'adjustment','quantity'=>$diff,'historical_unit_cost'=>$p->average_cost,'previous_stock'=>$before,'new_stock'=>$i['new_quantity'],'document_type'=>'adjustment','document_id'=>$id,'document_number'=>$number,'created_at'=>now(),'updated_at'=>now()]);
        }
        return response()->json(['success'=>true,'message'=>'Ajuste confirmado','data'=>['id'=>$id,'number'=>$number]],201);
    });
}
public function transfer(Request $r){
    [$c,,$u]=$this->ctx($r);
    $d=$r->validate(['source_warehouse_id'=>'required|integer|different:destination_warehouse_id','destination_warehouse_id'=>'required|integer','items'=>'required|array|min:1','items.*.product_id'=>'required|integer','items.*.quantity'=>'required|numeric|min:0.001','notes'=>'nullable']);
    $sourceWarehouse=$this->warehouse($c,$d['source_warehouse_id']);
    $destinationWarehouse=$this->warehouse($c,$d['destination_warehouse_id']);
    foreach($d['items'] as $i)$this->product($c,$i['product_id']);
    return DB::transaction(function()use($r,$c,$u,$d,$sourceWarehouse,$destinationWarehouse){
        $number='TR-'.str_pad((string)(DB::table('inventory_transfers')->where('company_id',$c)->count()+1),6,'0',STR_PAD_LEFT);
        $id=DB::table('inventory_transfers')->insertGetId(['uuid'=>Str::uuid(),'company_id'=>$c,'source_warehouse_id'=>$sourceWarehouse->id,'destination_warehouse_id'=>$destinationWarehouse->id,'user_id'=>$u,'number'=>$number,'status'=>'completed','notes'=>$d['notes']??null,'created_at'=>now(),'updated_at'=>now()]);
        foreach($d['items'] as $i){
            $source=DB::table('stocks')->where(['company_id'=>$c,'warehouse_id'=>$sourceWarehouse->id,'product_id'=>$i['product_id']])->lockForUpdate()->firstOrFail();
            abort_if($source->quantity<$i['quantity'],422,'Stock insuficiente para transferir');
            $destination=DB::table('stocks')->where(['company_id'=>$c,'warehouse_id'=>$destinationWarehouse->id,'product_id'=>$i['product_id']])->lockForUpdate()->first();
            $sourceAfter=$source->quantity-$i['quantity']; $destinationBefore=(float)($destination->quantity??0); $destinationAfter=$destinationBefore+$i['quantity'];
            DB::table('stocks')->where(['id'=>$source->id,'company_id'=>$c])->update(['quantity'=>$sourceAfter,'updated_at'=>now()]);
            DB::table('stocks')->updateOrInsert(['company_id'=>$c,'warehouse_id'=>$destinationWarehouse->id,'product_id'=>$i['product_id']],['branch_id'=>$destinationWarehouse->branch_id,'quantity'=>$destinationAfter,'created_at'=>$destination->created_at??now(),'updated_at'=>now()]);
            $p=$this->product($c,$i['product_id']);
            DB::table('inventory_transfer_details')->insert(['inventory_transfer_id'=>$id,'product_id'=>$i['product_id'],'quantity'=>$i['quantity'],'historical_unit_cost'=>$p->average_cost,'created_at'=>now(),'updated_at'=>now()]);
            $lots=app(\App\Services\LotService::class);
            foreach($lots->consume($c,$sourceWarehouse->id,$i['product_id'],(float)$i['quantity'],'transfer',$id) as [$lot,$moved])
                $lots->receive($c,$destinationWarehouse->id,$i['product_id'],$moved,$lot->lot_number,$lot->expires_at,(float)$lot->unit_cost,'transfer',$id);
            // Salida y entrada quedan en el kardex de cada almacén.
            $movement=['company_id'=>$c,'product_id'=>$i['product_id'],'user_id'=>$u,'historical_unit_cost'=>$p->average_cost,'document_type'=>'transfer','document_id'=>$id,'document_number'=>$number,'created_at'=>now(),'updated_at'=>now()];
            DB::table('inventory_movements')->insert($movement+['uuid'=>Str::uuid(),'branch_id'=>$sourceWarehouse->branch_id??$r->user()->branch_id,'warehouse_id'=>$sourceWarehouse->id,'type'=>'transfer_out','quantity'=>-$i['quantity'],'previous_stock'=>$source->quantity,'new_stock'=>$sourceAfter]);
            DB::table('inventory_movements')->insert($movement+['uuid'=>Str::uuid(),'branch_id'=>$destinationWarehouse->branch_id??$r->user()->branch_id,'warehouse_id'=>$destinationWarehouse->id,'type'=>'transfer_in','quantity'=>$i['quantity'],'previous_stock'=>$destinationBefore,'new_stock'=>$destinationAfter]);
        }
        return response()->json(['success'=>true,'message'=>'Transferencia completada','data'=>['id'=>$id,'number'=>$number]],201);
    });
}
public function alerts(Request $r){
    [$c]=$this->ctx($r);
    $low=DB::table('products as p')->join('stocks as s',fn($j)=>$j->on('s.product_id','=','p.id')->on('s.company_id','=','p.company_id'))->join('warehouses as w',fn($j)=>$j->on('w.id','=','s.warehouse_id')->on('w.company_id','=','s.company_id'))->where('p.company_id',$c)->where('p.status','active')->whereColumn('s.quantity','<=','p.minimum_stock')->select('p.id','p.name','p.category','p.brand','s.quantity','p.minimum_stock','s.warehouse_id','w.name as warehouse_name')->get();
    // Solo lotes con unidades pendientes: uno ya vendido o devuelto no es un riesgo.
    $lots=fn()=>DB::table('lots as l')->join('products as p',fn($j)=>$j->on('p.id','=','l.product_id')->on('p.company_id','=','l.company_id'))->join('warehouses as w',fn($j)=>$j->on('w.id','=','l.warehouse_id')->on('w.company_id','=','l.company_id'))->where('l.company_id',$c)->where('l.quantity','>',0)->select('l.*','p.name as product_name','p.category','p.brand','w.name as warehouse_name')->orderBy('l.expires_at');
    $today=now()->toDateString();
    return response()->json(['success'=>true,'data'=>[
        'low_stock'=>$low,
        'expiring'=>$lots()->whereBetween('l.expires_at',[$today,now()->addDays(30)->toDateString()])->get(),
        'expired'=>$lots()->where('l.expires_at','<',$today)->get(),
    ]]);
}
}
