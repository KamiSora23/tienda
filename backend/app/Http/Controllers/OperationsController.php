<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationsController extends Controller {
    
    
    public function saleDetail(Request $r,int $id){[$c]=$this->ctx($r);$sale=DB::table('sales')->where(['id'=>$id,'company_id'=>$c])->firstOrFail();$sale->items=DB::table('sale_details as d')->join('products as p','p.id','=','d.product_id')->where('d.sale_id',$id)->select('d.*','p.unit_type')->get();$sale->payments=DB::table('sale_payments')->where('sale_id',$id)->get();$sale->customer_email=$sale->customer_id?DB::table('customers')->where(['id'=>$sale->customer_id,'company_id'=>$c])->value('email'):null;$sale->returns=DB::table('sale_returns')->where('sale_id',$id)->get();$sale->debit_notes=DB::table('sale_debit_notes')->where('sale_id',$id)->get();return response()->json(['success'=>true,'data'=>$sale]);}
public function cancelSale(Request $r,int $id){
    [$c,$b,$u]=$this->ctx($r);
    $reason=$r->validate(['reason'=>'required|string'])['reason'];
    return DB::transaction(function()use($r,$id,$c,$b,$u,$reason){
        $sale=DB::table('sales')->where(['id'=>$id,'company_id'=>$c])->lockForUpdate()->firstOrFail();
        abort_if($sale->status!=='paid',422,'La venta ya fue anulada o devuelta');
        // La mercadería vuelve al almacén de la sucursal donde se vendió, y a sus lotes originales.
        $w=$this->branchWarehouse($c,$sale->branch_id);
        $lots=app(\App\Services\LotService::class);
        foreach(DB::table('sale_details')->where('sale_id',$id)->get() as $detail){
            $stock=DB::table('stocks')->where(['warehouse_id'=>$w->id,'product_id'=>$detail->product_id])->lockForUpdate()->first();
            $before=(float)($stock->quantity??0); $new=$before+(float)$detail->quantity;
            DB::table('stocks')->updateOrInsert(['warehouse_id'=>$w->id,'product_id'=>$detail->product_id],['company_id'=>$c,'branch_id'=>$w->branch_id,'quantity'=>$new,'created_at'=>$stock->created_at??now(),'updated_at'=>now()]);
            $lots->restore($c,'sale',$id,$detail->product_id,(float)$detail->quantity,'sale_cancel',$id);
            DB::table('inventory_movements')->insert(['uuid'=>Str::uuid(),'company_id'=>$c,'branch_id'=>$sale->branch_id,'warehouse_id'=>$w->id,'product_id'=>$detail->product_id,'user_id'=>$u,'type'=>'sale_cancel_in','quantity'=>$detail->quantity,'historical_unit_cost'=>$detail->historical_unit_cost,'previous_stock'=>$before,'new_stock'=>$new,'document_type'=>'sale_cancel','document_id'=>$id,'document_number'=>$sale->number,'created_at'=>now(),'updated_at'=>now()]);
        }
        DB::table('sales')->where('id',$id)->update(['status'=>'cancelled','updated_at'=>now()]);
        $cash=app(\App\Services\CashService::class)->current($r);
        $cashPaid=(float)DB::table('sale_payments')->where(['sale_id'=>$id,'method'=>'cash'])->sum('amount');
        if($cash&&$cashPaid)DB::table('cash_movements')->insert(['cash_session_id'=>$cash->id,'type'=>'withdrawal','amount'=>$cashPaid,'description'=>"Anulación {$sale->number}: {$reason}",'reference'=>$sale->number,'user_id'=>$u,'created_at'=>now(),'updated_at'=>now()]);
        $this->recordAudit($r,'sale.cancelled','sale',$id,['reason'=>$reason]);
        return response()->json(['success'=>true,'message'=>'Venta anulada y stock restaurado']);
    });
}
public function returnSale(Request $r,int $id){
    [$c,,$u]=$this->ctx($r);
    $d=$r->validate(['reason'=>'required','items'=>'required|array|min:1','items.*.sale_detail_id'=>'required|integer','items.*.quantity'=>'required|numeric|min:0.001']);
    return DB::transaction(function()use($r,$id,$c,$u,$d){
        $sale=DB::table('sales')->where(['id'=>$id,'company_id'=>$c])->lockForUpdate()->firstOrFail();
        abort_unless(in_array($sale->status,['paid','partial_return']),422,'La venta no admite devolución');
        $w=$this->branchWarehouse($c,$sale->branch_id);
        $lots=app(\App\Services\LotService::class);
        $number='NC01-'.str_pad((string)(DB::table('sale_returns')->where('company_id',$c)->count()+1),6,'0',STR_PAD_LEFT);
        $returnId=DB::table('sale_returns')->insertGetId(['uuid'=>Str::uuid(),'company_id'=>$c,'branch_id'=>$sale->branch_id,'sale_id'=>$id,'user_id'=>$u,'number'=>$number,'reason'=>$d['reason'],'total'=>0,'status'=>'completed','created_at'=>now(),'updated_at'=>now()]);
        $total=0;
        // Parte de cada línea que realmente se cobró: los descuentos al total y las promociones se reparten en proporción.
        $lines=(float)DB::table('sale_details')->where('sale_id',$id)->sum('total');$paidShare=$lines>0?min(1,(float)$sale->total/$lines):1;
        foreach($d['items'] as $item){
            $detail=DB::table('sale_details')->where(['id'=>$item['sale_detail_id'],'sale_id'=>$id])->firstOrFail();
            // Tope acumulado: lo devuelto antes más lo de ahora no puede superar lo vendido.
            $already=(float)DB::table('sale_return_details')->where('sale_detail_id',$detail->id)->sum('quantity');
            abort_if($already+$item['quantity']>(float)$detail->quantity+0.0005,422,"Solo quedan ".rtrim(rtrim(number_format((float)$detail->quantity-$already,3,'.',''),'0'),'.')." por devolver de {$detail->product_name}");
            $line=round($item['quantity']*((float)$detail->total/(float)$detail->quantity)*$paidShare,2);
            $total+=$line;
            DB::table('sale_return_details')->insert(['sale_return_id'=>$returnId,'sale_detail_id'=>$detail->id,'product_id'=>$detail->product_id,'quantity'=>$item['quantity'],'unit_price'=>$detail->unit_price,'total'=>$line,'created_at'=>now(),'updated_at'=>now()]);
            $stock=DB::table('stocks')->where(['warehouse_id'=>$w->id,'product_id'=>$detail->product_id])->lockForUpdate()->first();
            $before=(float)($stock->quantity??0); $new=$before+$item['quantity'];
            DB::table('stocks')->updateOrInsert(['warehouse_id'=>$w->id,'product_id'=>$detail->product_id],['company_id'=>$c,'branch_id'=>$w->branch_id,'quantity'=>$new,'created_at'=>$stock->created_at??now(),'updated_at'=>now()]);
            $lots->restore($c,'sale',$id,$detail->product_id,(float)$item['quantity'],'sale_return',$returnId);
            DB::table('inventory_movements')->insert(['uuid'=>Str::uuid(),'company_id'=>$c,'branch_id'=>$sale->branch_id,'warehouse_id'=>$w->id,'product_id'=>$detail->product_id,'user_id'=>$u,'type'=>'sale_return_in','quantity'=>$item['quantity'],'historical_unit_cost'=>$detail->historical_unit_cost,'previous_stock'=>$before,'new_stock'=>$new,'document_type'=>'credit_note','document_id'=>$returnId,'document_number'=>$number,'created_at'=>now(),'updated_at'=>now()]);
        }
        DB::table('sale_returns')->where('id',$returnId)->update(['total'=>$total]);
        // Si el cliente pagó en efectivo, la devolución sale de la caja (hasta lo cobrado en efectivo).
        $cashPaid=(float)DB::table('sale_payments')->where(['sale_id'=>$id,'method'=>'cash'])->sum('amount');
        $alreadyRefunded=(float)DB::table('cash_movements')->where(['type'=>'refund','reference'=>$sale->number])->sum('amount');
        $refund=round(min($total,max(0,$cashPaid-$alreadyRefunded)),2);
        if($refund>0){$cashService=app(\App\Services\CashService::class);$cash=$cashService->current($r,true);abort_unless($cash,422,'Abre o selecciona una caja para devolver el efectivo al cliente.');abort_if($refund>$cashService->expected($cash->id)+0.005,422,'No hay suficiente efectivo en la caja para la devolución.');DB::table('cash_movements')->insert(['cash_session_id'=>$cash->id,'type'=>'refund','amount'=>$refund,'description'=>"Devolución {$number}",'reference'=>$sale->number,'user_id'=>$u,'created_at'=>now(),'updated_at'=>now()]);}
        $returnedTotal=(float)DB::table('sale_returns')->where('sale_id',$id)->sum('total');
        DB::table('sales')->where('id',$id)->update(['status'=>$returnedTotal>=(float)$sale->total-0.005?'refunded':'partial_return','updated_at'=>now()]);
        $this->recordAudit($r,'sale.returned','sale',$id,['return'=>$number,'total'=>$total]);
        return response()->json(['success'=>true,'message'=>'Devolución registrada','data'=>['number'=>$number,'total'=>$total]],201);
    });
}
    public function cashMovement(Request $r){[$c,$b,$u]=$this->ctx($r);$d=$r->validate(['type'=>'required|in:income,withdrawal,deposit','amount'=>'required|numeric|min:0.01','description'=>'required']);$cashService=app(\App\Services\CashService::class);$cash=$cashService->current($r,true);abort_unless($cash,422,'Debe seleccionar una caja abierta');
        // No se puede retirar ni depositar más efectivo del que hay en la caja.
        if($d['type']!=='income'){$available=$cashService->expected($cash->id);abort_if($d['amount']>$available+0.005,422,'Solo hay S/ '.number_format($available,2).' en efectivo en la caja.');}
        $id=DB::table('cash_movements')->insertGetId(['cash_session_id'=>$cash->id,'type'=>$d['type'],'amount'=>$d['amount'],'description'=>$d['description'],'reference'=>'MAN-'.now()->format('YmdHis'),'user_id'=>$u,'created_at'=>now(),'updated_at'=>now()]);return response()->json(['success'=>true,'message'=>'Movimiento de caja registrado','data'=>['id'=>$id]],201);}
    public function suspended(Request $r){[$c,$b]=$this->ctx($r);return response()->json(['success'=>true,'data'=>DB::table('suspended_sales')->where(['company_id'=>$c,'branch_id'=>$b,'status'=>'pending'])->latest()->get()]);}
    public function suspend(Request $r){[$c,$b,$u]=$this->ctx($r);$d=$r->validate(['cart'=>'required|array|min:1','customer_id'=>'nullable|integer']);$id=DB::table('suspended_sales')->insertGetId(['uuid'=>Str::uuid(),'company_id'=>$c,'branch_id'=>$b,'user_id'=>$u,'reference'=>'SUS-'.now()->format('YmdHis'),'cart'=>json_encode($d['cart']),'customer_id'=>$d['customer_id']??null,'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);return response()->json(['success'=>true,'message'=>'Venta suspendida','data'=>['id'=>$id]],201);}
    public function recover(Request $r,int $id){[$c,$b]=$this->ctx($r);$row=DB::table('suspended_sales')->where(['id'=>$id,'company_id'=>$c,'branch_id'=>$b,'status'=>'pending'])->firstOrFail();DB::table('suspended_sales')->where('id',$id)->update(['status'=>'recovered','updated_at'=>now()]);$row->cart=json_decode($row->cart,true);return response()->json(['success'=>true,'data'=>$row]);}
    public function ticket(Request $r,int $id){[$c]=$this->ctx($r);$sale=DB::table('sales')->where(['id'=>$id,'company_id'=>$c])->firstOrFail();return response()->json(['success'=>true,'data'=>['company'=>DB::table('companies')->where('id',$c)->first(),'sale'=>$sale,'items'=>DB::table('sale_details as d')->join('products as p','p.id','=','d.product_id')->where('d.sale_id',$id)->select('d.*','p.unit_type')->get(),'payments'=>DB::table('sale_payments')->where('sale_id',$id)->get()]]);}
    public function debitNote(Request$r,int$id){[$c,$b,$u]=$this->ctx($r);$d=$r->validate(['reason'=>'required','amount'=>'required|numeric|min:0.01','payment_method'=>'required|in:cash,yape,card,transfer','reference'=>'nullable']);return DB::transaction(function()use($r,$id,$c,$b,$u,$d){$sale=DB::table('sales')->where(['id'=>$id,'company_id'=>$c])->firstOrFail();abort_unless(in_array($sale->status,['paid','partial_return']),422,'La venta no admite nota de débito');$series=DB::table('document_series')->where(['company_id'=>$c,'branch_id'=>$b,'document_type'=>'debit_note','active'=>1])->lockForUpdate()->firstOrFail();$next=$series->current_number+1;DB::table('document_series')->where('id',$series->id)->update(['current_number'=>$next,'updated_at'=>now()]);$number=$series->series.'-'.str_pad((string)$next,8,'0',STR_PAD_LEFT);$nid=DB::table('sale_debit_notes')->insertGetId(['uuid'=>Str::uuid(),'company_id'=>$c,'branch_id'=>$b,'sale_id'=>$id,'user_id'=>$u,'number'=>$number]+$d+['status'=>'issued','created_at'=>now(),'updated_at'=>now()]);if($d['payment_method']==='cash'){$cash=app(\App\Services\CashService::class)->current($r);abort_unless($cash,422,'Debe abrir caja para cobrar la nota de débito');DB::table('cash_movements')->insert(['cash_session_id'=>$cash->id,'type'=>'income','amount'=>$d['amount'],'description'=>"Nota de débito {$number}",'reference'=>$sale->number,'user_id'=>$u,'created_at'=>now(),'updated_at'=>now()]);}$this->recordAudit($r,'sale.debit_note','sale',$id,['number'=>$number,'amount'=>$d['amount']]);return response()->json(['success'=>true,'message'=>'Nota de débito emitida','data'=>['id'=>$nid,'number'=>$number]],201);});}
    
}
