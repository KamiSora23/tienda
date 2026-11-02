<?php

namespace App\Http\Controllers;
use App\Services\ProductSupplierService;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class AdvancedOperationsController extends Controller {
 
 
 public function productDetail(Request $r,int $id){[$c]=$this->ctx($r);$p=DB::table('products')->where(['id'=>$id,'company_id'=>$c])->firstOrFail();$p->variants=DB::table('product_variants')->where('product_id',$id)->get();$p->images=DB::table('product_images')->where('product_id',$id)->orderBy('sort_order')->get()->map(function($x){$x->url=url("/api/v1/portal/product-images/{$x->id}");return$x;});$p->price_history=DB::table('price_history')->where('product_id',$id)->latest()->get();$p->cost_history=DB::table('cost_history')->where('product_id',$id)->latest()->get();$p->stocks=DB::table('stocks as s')->join('warehouses as w','w.id','=','s.warehouse_id')->where('s.product_id',$id)->select('s.*','w.name as warehouse_name')->get();$p->relations=DB::table('product_relations as r')->join('products as pr','pr.id','=','r.related_product_id')->where('r.product_id',$id)->select('r.type','pr.id','pr.name','pr.sku')->get();$p->suppliers=DB::table('product_suppliers as ps')->join('suppliers as s','s.id','=','ps.supplier_id')->where(['ps.company_id'=>$c,'ps.product_id'=>$id])->select('ps.*','s.name as supplier_name','s.document_number')->orderByDesc('ps.is_primary')->orderBy('s.name')->get();return response()->json(['success'=>true,'data'=>$p]);}
 public function duplicateProduct(Request $r,int $id){[$c]=$this->ctx($r);$p=(array)DB::table('products')->where(['id'=>$id,'company_id'=>$c])->firstOrFail();unset($p['id']);$p['uuid']=Str::uuid();$p['name'].=' (copia)';$p['sku'].='-COPY-'.now()->format('His');$p['barcode']=null;$p['created_at']=$p['updated_at']=now();$new=DB::table('products')->insertGetId($p);foreach(DB::table('product_variants')->where('product_id',$id)->get() as $v){$x=(array)$v;unset($x['id']);$x['product_id']=$new;$x['sku'].='-'.now()->format('His');$x['barcode']=null;$x['created_at']=$x['updated_at']=now();DB::table('product_variants')->insert($x);}app(\App\Services\ProductPresentationService::class)->copy($c,$id,$new);return response()->json(['success'=>true,'message'=>'Producto duplicado','data'=>['id'=>$new]],201);}
 public function variants(Request $r,int $product){[$c]=$this->ctx($r);return response()->json(['success'=>true,'data'=>DB::table('product_variants')->where(['company_id'=>$c,'product_id'=>$product])->get()]);}
 public function saveVariant(Request $r,int $product,?int $id=null){[$c]=$this->ctx($r);DB::table('products')->where(['id'=>$product,'company_id'=>$c])->firstOrFail();$d=$r->validate(['name'=>'required','sku'=>'required','barcode'=>'nullable','attributes'=>'nullable|array','cost'=>'nullable|numeric|min:0','price'=>'nullable|numeric|min:0','status'=>'nullable']);if(isset($d['attributes']))$d['attributes']=json_encode($d['attributes']);$d+=['company_id'=>$c,'product_id'=>$product,'status'=>'active'];$d['updated_at']=now();if($id)DB::table('product_variants')->where(['id'=>$id,'product_id'=>$product])->update($d);else{$d['created_at']=now();$id=DB::table('product_variants')->insertGetId($d);}return response()->json(['success'=>true,'message'=>'Variante guardada','data'=>['id'=>$id]]);}
 public function toggleProduct(Request $r,int $id){[$c]=$this->ctx($r);$p=DB::table('products')->where(['id'=>$id,'company_id'=>$c])->firstOrFail();$status=$p->status==='active'?'inactive':'active';DB::table('products')->where('id',$id)->update(['status'=>$status,'updated_at'=>now()]);return response()->json(['success'=>true,'data'=>['status'=>$status]]);}
 public function labels(Request $r){[$c]=$this->ctx($r);$ids=$r->validate(['product_ids'=>'required|array','product_ids.*'=>'integer'])['product_ids'];$products=DB::table('products')->where('company_id',$c)->whereIn('id',$ids)->select('id','name','sku','barcode','sale_price','unit_type')->get();$presentations=DB::table('product_presentations')->where('company_id',$c)->whereIn('product_id',$ids)->whereNotNull('barcode')->get()->groupBy('product_id');foreach($products as $p)$p->presentations=$presentations->get($p->id,collect())->map(fn($x)=>['name'=>$x->name,'barcode'=>$x->barcode,'sale_price'=>$x->sale_price,'factor'=>$x->factor])->values();return response()->json(['success'=>true,'data'=>$products]);}
 public function exportProducts(Request$r){[$c]=$this->ctx($r);$rows=DB::table('products as p')->leftJoin('stocks as s','s.product_id','=','p.id')->where('p.company_id',$c)->selectRaw('p.sku,p.barcode,p.name,p.category,p.brand,p.unit_type,p.average_cost,p.sale_price,p.minimum_price,p.minimum_stock,p.status,COALESCE(SUM(s.quantity),0) stock')->groupBy('p.id')->get();$cell=fn($v)=>'<Cell><Data ss:Type="String">'.htmlspecialchars((string)$v,ENT_XML1).'</Data></Cell>';$headers=['sku','barcode','name','category','brand','unit_type','average_cost','sale_price','minimum_price','minimum_stock','status','stock'];$xml='<?xml version="1.0" encoding="UTF-8"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Productos"><Table><Row>'.implode('',array_map($cell,$headers)).'</Row>';foreach($rows as$row)$xml.='<Row>'.implode('',array_map($cell,array_values((array)$row))).'</Row>';$xml.='</Table></Worksheet></Workbook>';return response($xml,200,['Content-Type'=>'application/vnd.ms-excel; charset=UTF-8','Content-Disposition'=>'attachment; filename="productos-'.now()->format('Ymd-His').'.xls"']);}
 public function uploadImage(Request$r,int$product){[$c]=$this->ctx($r);DB::table('products')->where(['id'=>$product,'company_id'=>$c])->firstOrFail();$r->validate(['image'=>'required|image|max:5120','alt'=>'nullable','is_primary'=>'boolean']);$file=$r->file('image');$path=$file->store("products/{$c}/{$product}");if($r->boolean('is_primary'))DB::table('product_images')->where('product_id',$product)->update(['is_primary'=>false]);$id=DB::table('product_images')->insertGetId(['company_id'=>$c,'product_id'=>$product,'path'=>$path,'alt'=>$r->alt,'sort_order'=>(int)DB::table('product_images')->where('product_id',$product)->max('sort_order')+1,'is_primary'=>$r->boolean('is_primary'),'created_at'=>now(),'updated_at'=>now()]);return response()->json(['success'=>true,'message'=>'Imagen guardada','data'=>['id'=>$id]],201);}
 public function deleteImage(Request$r,int$product,int$id){[$c]=$this->ctx($r);$img=DB::table('product_images')->where(['id'=>$id,'product_id'=>$product,'company_id'=>$c])->firstOrFail();if(Storage::exists($img->path))Storage::delete($img->path);DB::table('product_images')->where('id',$id)->delete();return response()->json(['success'=>true,'message'=>'Imagen eliminada']);}
 public function addRelation(Request$r,int$product){[$c]=$this->ctx($r);DB::table('products')->where(['id'=>$product,'company_id'=>$c])->firstOrFail();$d=$r->validate(['related_product_id'=>'required|integer|different:product','type'=>'required|in:related,similar']);DB::table('products')->where(['id'=>$d['related_product_id'],'company_id'=>$c])->firstOrFail();DB::table('product_relations')->insertOrIgnore($d+['product_id'=>$product,'created_at'=>now(),'updated_at'=>now()]);return response()->json(['success'=>true,'message'=>'Relación guardada']);}
 public function deleteRelation(Request$r,int$product,int$related,string$type){[$c]=$this->ctx($r);DB::table('products')->where(['id'=>$product,'company_id'=>$c])->firstOrFail();DB::table('product_relations')->where(['product_id'=>$product,'related_product_id'=>$related,'type'=>$type])->delete();return response()->json(['success'=>true,'message'=>'Relación eliminada']);}
 public function importProducts(Request$r){[$c,$b,$u]=$this->ctx($r);$r->validate(['file'=>'required|file|max:10240']);$file=$r->file('file');$ext=strtolower($file->getClientOriginalExtension());abort_unless(in_array($ext,['csv','xlsx']),422,'Use un archivo CSV o XLSX');$rows=$ext==='csv'?$this->csvRows($file->getRealPath()):$this->xlsxRows($file->getRealPath());abort_unless($rows,422,'El archivo no contiene registros');$w=$this->branchWarehouse($c,$b);$created=0;$updated=0;$errors=[];foreach($rows as$n=>$row){try{if(empty($row['sku'])||empty($row['name']))throw new \RuntimeException('SKU y nombre son obligatorios');$payload=['name'=>$row['name'],'barcode'=>$row['barcode']??null,'category'=>$row['category']??'General','unit_type'=>in_array($row['unit_type']??'',\App\Services\UnitCatalog::codes(),true)?$row['unit_type']:'unit','allow_decimal_quantity'=>\App\Services\ProductPresentationService::allowsDecimals((string)($row['unit_type']??'')),'average_cost'=>(float)($row['average_cost']??0),'sale_price'=>(float)($row['sale_price']??0),'minimum_price'=>(float)($row['minimum_price']??0),'minimum_stock'=>(float)($row['minimum_stock']??0),'status'=>'active','updated_at'=>now()];$p=DB::table('products')->where(['company_id'=>$c,'sku'=>$row['sku']])->first();if($p){DB::table('products')->where('id',$p->id)->update($payload);$id=$p->id;$updated++;}else{$id=DB::table('products')->insertGetId($payload+['uuid'=>Str::uuid(),'company_id'=>$c,'sku'=>$row['sku'],'created_at'=>now()]);$created++;}if(array_key_exists('stock',$row))DB::table('stocks')->updateOrInsert(['warehouse_id'=>$w->id,'product_id'=>$id],['company_id'=>$c,'branch_id'=>$w->branch_id,'quantity'=>(float)$row['stock'],'created_at'=>now(),'updated_at'=>now()]);}catch(\Throwable$e){$errors[]=['row'=>$n+2,'error'=>$e->getMessage()];}}return response()->json(['success'=>true,'message'=>'Importación finalizada','data'=>compact('created','updated','errors')]);}
 private function csvRows(string$path):array{$h=fopen($path,'r');$headers=array_map(fn($x)=>strtolower(trim($x)),fgetcsv($h));$rows=[];while(($values=fgetcsv($h))!==false){$values=array_pad($values,count($headers),null);$rows[]=array_combine($headers,array_slice($values,0,count($headers)));}fclose($h);return$rows;}
 private function xlsxRows(string$path):array{$zip=new ZipArchive;abort_unless($zip->open($path)===true,422,'XLSX inválido');$shared=[];$raw=$zip->getFromName('xl/sharedStrings.xml');if($raw){$xml=simplexml_load_string($raw);foreach($xml->si as$si)$shared[]=implode('',array_map('strval',iterator_to_array($si->t->count()?$si->t:$si->r->t)));}$sheet=simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));$matrix=[];foreach($sheet->sheetData->row as$row){$line=[];foreach($row->c as$c){$ref=(string)$c['r'];preg_match('/[A-Z]+/',$ref,$m);$col=0;foreach(str_split($m[0])as$ch)$col=$col*26+ord($ch)-64;$value=(string)$c->v;if((string)$c['t']==='s')$value=$shared[(int)$value]??'';elseif((string)$c['t']==='inlineStr')$value=(string)$c->is->t;$line[$col-1]=$value;}if($line){ksort($line);$matrix[]=$line;}}$zip->close();if(!$matrix)return[];$headers=array_map(fn($x)=>strtolower(trim($x)),array_shift($matrix));return array_map(function($r)use($headers){$r=array_replace(array_fill(0,count($headers),null),$r);return array_combine($headers,array_slice($r,0,count($headers)));},$matrix);}
 public function purchaseDetail(Request $r,int $id){[$c]=$this->ctx($r);$p=DB::table('purchases as p')->leftJoin('suppliers as s','s.id','=','p.supplier_id')->where(['p.id'=>$id,'p.company_id'=>$c])->select('p.*','s.name as supplier_name')->firstOrFail();$p->items=DB::table('purchase_details as d')->join('products as pr','pr.id','=','d.product_id')->where('d.purchase_id',$id)->select('d.*','pr.name as product_name','pr.unit_type')->get();foreach($p->items as $item)$item->returned=$this->returnedQuantity($id,$item->id);$p->payments=DB::table('purchase_payments')->where('purchase_id',$id)->get();$p->returns=DB::table('purchase_returns')->where('purchase_id',$id)->get();return response()->json(['success'=>true,'data'=>$p]);}
public function pendingPurchase(Request $r, \App\Services\InventoryCostService $costs){
    [$c,$b,$u]=$this->ctx($r);
    $d=$r->validate(['supplier_id'=>'nullable|integer','invoice_number'=>'nullable','document_type'=>'required|in:quick,invoice,receipt,guide','items'=>'required|array|min:1','items.*.product_id'=>'required|integer','items.*.quantity'=>'required|numeric|min:.001','items.*.unit_cost'=>'required|numeric|min:0','items.*.lot_number'=>'nullable|string|max:60','items.*.expires_at'=>'nullable|date','tax'=>'nullable|numeric|min:0','freight'=>'nullable|numeric|min:0','perception'=>'nullable|numeric|min:0','other_costs'=>'nullable|numeric|min:0']);
    if(!empty($d['supplier_id']))abort_unless(DB::table('suppliers')->where(['id'=>$d['supplier_id'],'company_id'=>$c,'status'=>'active'])->exists(),422,'Proveedor inválido');
    foreach($d['items'] as $x)abort_unless(DB::table('products')->where(['id'=>$x['product_id'],'company_id'=>$c])->exists(),404,'Producto no encontrado');
    return DB::transaction(function()use($c,$b,$u,$d,$costs){
        $subtotal=collect($d['items'])->sum(fn($x)=>$x['quantity']*$x['unit_cost']);
        $effective=$costs->effectiveCosts($d['items'],(float)(($d['freight']??0)+($d['other_costs']??0)));
        $number='CP-'.str_pad((string)(DB::table('purchases')->where('company_id',$c)->count()+1),6,'0',STR_PAD_LEFT);
        $id=DB::table('purchases')->insertGetId(['uuid'=>Str::uuid(),'company_id'=>$c,'branch_id'=>$b,'supplier_id'=>$d['supplier_id']??null,'user_id'=>$u,'number'=>$number,'invoice_number'=>$d['invoice_number']??null,'document_type'=>$d['document_type'],'subtotal'=>$subtotal,'tax'=>$d['tax']??0,'freight'=>$d['freight']??0,'perception'=>$d['perception']??0,'other_costs'=>$d['other_costs']??0,'total'=>$subtotal+($d['tax']??0)+($d['freight']??0)+($d['perception']??0)+($d['other_costs']??0),'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
        foreach($d['items'] as $k=>$x)DB::table('purchase_details')->insert(['purchase_id'=>$id,'product_id'=>$x['product_id'],'quantity'=>$x['quantity'],'unit_cost'=>$x['unit_cost'],'effective_unit_cost'=>$effective[$k],'total'=>$x['quantity']*$x['unit_cost'],'lot_number'=>($x['lot_number']??null)?:null,'expires_at'=>$x['expires_at']??null,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['success'=>true,'message'=>'Compra guardada como pendiente','data'=>['id'=>$id,'number'=>$number]],201);
    });
}
public function confirmPurchase(Request $r,int $id,ProductSupplierService $supplierService,\App\Services\InventoryCostService $costs){
    [$c,,$u]=$this->ctx($r);
    return DB::transaction(function()use($r,$c,$u,$id,$supplierService,$costs){
        $p=DB::table('purchases')->where(['id'=>$id,'company_id'=>$c,'status'=>'pending'])->lockForUpdate()->firstOrFail();
        // La mercadería entra al almacén de la sucursal donde se registró la compra.
        $w=$this->branchWarehouse($c,$p->branch_id);
        foreach(DB::table('purchase_details')->where('purchase_id',$id)->get() as $x){
            DB::table('products')->where(['id'=>$x->product_id,'company_id'=>$c])->lockForUpdate()->firstOrFail();
            $s=DB::table('stocks')->where(['warehouse_id'=>$w->id,'product_id'=>$x->product_id])->lockForUpdate()->first();
            $before=(float)($s->quantity??0); $new=$before+$x->quantity;
            $costs->receive($c,$x->product_id,(float)$x->quantity,(float)$x->effective_unit_cost,$u,"Compra {$p->number}");
            DB::table('stocks')->updateOrInsert(['warehouse_id'=>$w->id,'product_id'=>$x->product_id],['company_id'=>$c,'branch_id'=>$w->branch_id,'quantity'=>$new,'created_at'=>now(),'updated_at'=>now()]);
            if($x->lot_number)app(\App\Services\LotService::class)->receive($c,$w->id,$x->product_id,(float)$x->quantity,$x->lot_number,$x->expires_at,(float)$x->effective_unit_cost,'purchase',$id);
            DB::table('inventory_movements')->insert(['uuid'=>Str::uuid(),'company_id'=>$c,'branch_id'=>$p->branch_id,'warehouse_id'=>$w->id,'product_id'=>$x->product_id,'user_id'=>$u,'type'=>'purchase_in','quantity'=>$x->quantity,'historical_unit_cost'=>$x->effective_unit_cost,'previous_stock'=>$before,'new_stock'=>$new,'document_type'=>'purchase','document_id'=>$id,'document_number'=>$p->number,'created_at'=>now(),'updated_at'=>now()]);
        }
        $supplierService->syncConfirmedPurchase($c,$p,DB::table('purchase_details')->where('purchase_id',$id)->get());
        DB::table('purchases')->where('id',$id)->update(['status'=>'confirmed','updated_at'=>now()]);
        $this->recordAudit($r,'purchase.confirmed','purchase',$id,['number'=>$p->number]);
        return response()->json(['success'=>true,'message'=>'Compra confirmada y stock actualizado']);
    });
}
public function cancelPurchase(Request $r,int $id,\App\Services\InventoryCostService $costs){
    [$c,,$u]=$this->ctx($r);
    $reason=$r->validate(['reason'=>'required|string'])['reason'];
    return DB::transaction(function()use($r,$c,$u,$id,$reason,$costs){
        $p=DB::table('purchases')->where(['id'=>$id,'company_id'=>$c])->lockForUpdate()->firstOrFail();
        abort_if($p->status==='cancelled',422,'La compra ya está anulada');
        abort_if(DB::table('purchase_payments')->where('purchase_id',$id)->exists(),422,'No se puede anular una compra con pagos');
        if($p->status==='confirmed'){
            $w=$this->branchWarehouse($c,$p->branch_id);
            foreach(DB::table('purchase_details')->where('purchase_id',$id)->get() as $x){
                // Lo ya devuelto al proveedor salió antes del stock: solo se revierte el resto.
                $remaining=(float)$x->quantity-$this->returnedQuantity($id,$x->id);
                if($remaining<=0)continue;
                $s=DB::table('stocks')->where(['warehouse_id'=>$w->id,'product_id'=>$x->product_id])->lockForUpdate()->firstOrFail();
                abort_if($s->quantity<$remaining,422,'No hay stock suficiente para revertir esta compra: parte ya se vendió');
                $costs->release($c,$x->product_id,$remaining,(float)$x->effective_unit_cost,$u,"Anulación compra {$p->number}");
                $lots=app(\App\Services\LotService::class);$lots->consume($c,$w->id,$x->product_id,$remaining,'purchase_cancel',$id,$lots->lotsReceivedBy('purchase',$id,$x->product_id));
                $new=$s->quantity-$remaining;
                DB::table('stocks')->where('id',$s->id)->update(['quantity'=>$new,'updated_at'=>now()]);
                DB::table('inventory_movements')->insert(['uuid'=>Str::uuid(),'company_id'=>$c,'branch_id'=>$p->branch_id,'warehouse_id'=>$w->id,'product_id'=>$x->product_id,'user_id'=>$u,'type'=>'purchase_cancel_out','quantity'=>-$remaining,'historical_unit_cost'=>$x->effective_unit_cost,'previous_stock'=>$s->quantity,'new_stock'=>$new,'document_type'=>'purchase_cancel','document_id'=>$id,'document_number'=>$p->number,'created_at'=>now(),'updated_at'=>now()]);
            }
        }
        DB::table('purchases')->where('id',$id)->update(['status'=>'cancelled','updated_at'=>now()]);
        $this->recordAudit($r,'purchase.cancelled','purchase',$id,['number'=>$p->number,'reason'=>$reason]);
        return response()->json(['success'=>true,'message'=>"Compra anulada: {$reason}"]);
    });
}
public function purchasePayment(Request $r,int $id){
    [$c]=$this->ctx($r);
    $p=DB::table('purchases')->where(['id'=>$id,'company_id'=>$c])->firstOrFail();
    abort_if($p->status==='cancelled',422,'No se puede pagar una compra anulada');
    $d=$r->validate(['method'=>'required|in:cash,transfer,card,credit','amount'=>'required|numeric|min:0.01','reference'=>'nullable','paid_at'=>'required|date']);
    // Lo que se debe: total de la compra menos lo devuelto al proveedor y lo ya pagado.
    $owed=(float)$p->total-(float)DB::table('purchase_returns')->where('purchase_id',$id)->sum('total');
    $paid=(float)DB::table('purchase_payments')->where('purchase_id',$id)->sum('amount');
    abort_if($paid+$d['amount']>$owed+.01,422,'El pago supera el saldo pendiente de la compra (S/ '.number_format(max(0,$owed-$paid),2).')');
    $pid=DB::table('purchase_payments')->insertGetId($d+['purchase_id'=>$id,'created_at'=>now(),'updated_at'=>now()]);
    return response()->json(['success'=>true,'message'=>'Pago registrado','data'=>['id'=>$pid,'balance'=>round($owed-$paid-$d['amount'],2)]],201);
}
public function purchaseReturn(Request $r,int $id,\App\Services\InventoryCostService $costs){
    [$c,,$u]=$this->ctx($r);
    $d=$r->validate(['reason'=>'required','items'=>'required|array|min:1','items.*.purchase_detail_id'=>'required|integer','items.*.quantity'=>'required|numeric|min:0.001']);
    return DB::transaction(function()use($r,$id,$c,$u,$d,$costs){
        $purchase=DB::table('purchases')->where(['id'=>$id,'company_id'=>$c])->lockForUpdate()->firstOrFail();
        abort_unless($purchase->status==='confirmed',422,'Solo se puede devolver mercadería de una compra confirmada');
        $w=$this->branchWarehouse($c,$purchase->branch_id);
        $number='DVC-'.str_pad((string)(DB::table('purchase_returns')->where('company_id',$c)->count()+1),6,'0',STR_PAD_LEFT);
        $rid=DB::table('purchase_returns')->insertGetId(['uuid'=>Str::uuid(),'company_id'=>$c,'branch_id'=>$purchase->branch_id,'purchase_id'=>$id,'user_id'=>$u,'number'=>$number,'reason'=>$d['reason'],'total'=>0,'status'=>'completed','created_at'=>now(),'updated_at'=>now()]);
        $total=0;
        foreach($d['items'] as $i){
            $line=DB::table('purchase_details')->where(['id'=>$i['purchase_detail_id'],'purchase_id'=>$id])->firstOrFail();
            abort_if($this->returnedQuantity($id,$line->id)+$i['quantity']>$line->quantity,422,'La devolución supera la cantidad comprada');
            $stock=DB::table('stocks')->where(['warehouse_id'=>$w->id,'product_id'=>$line->product_id])->lockForUpdate()->firstOrFail();
            abort_if($stock->quantity<$i['quantity'],422,'No hay stock suficiente para devolver');
            // Lo devuelto sale con su costo de compra: el promedio de lo que queda no se distorsiona.
            $costs->release($c,$line->product_id,(float)$i['quantity'],(float)$line->effective_unit_cost,$u,"Devolución {$number}");
            $lots=app(\App\Services\LotService::class);$lots->consume($c,$w->id,$line->product_id,(float)$i['quantity'],'purchase_return',$rid,$lots->lotsReceivedBy('purchase',$id,$line->product_id));
            $new=$stock->quantity-$i['quantity'];
            DB::table('stocks')->where('id',$stock->id)->update(['quantity'=>$new,'updated_at'=>now()]);
            $amount=$i['quantity']*$line->unit_cost; $total+=$amount;
            DB::table('purchase_return_details')->insert(['purchase_return_id'=>$rid,'purchase_detail_id'=>$line->id,'product_id'=>$line->product_id,'quantity'=>$i['quantity'],'unit_cost'=>$line->unit_cost,'total'=>$amount,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('inventory_movements')->insert(['uuid'=>Str::uuid(),'company_id'=>$c,'branch_id'=>$purchase->branch_id,'warehouse_id'=>$w->id,'product_id'=>$line->product_id,'user_id'=>$u,'type'=>'purchase_return_out','quantity'=>-$i['quantity'],'historical_unit_cost'=>$line->effective_unit_cost,'previous_stock'=>$stock->quantity,'new_stock'=>$new,'document_type'=>'purchase_return','document_id'=>$rid,'document_number'=>$number,'created_at'=>now(),'updated_at'=>now()]);
        }
        DB::table('purchase_returns')->where('id',$rid)->update(['total'=>$total]);
        $this->recordAudit($r,'purchase.returned','purchase',$id,['return'=>$number,'total'=>$total]);
        return response()->json(['success'=>true,'message'=>'Devolución de compra registrada','data'=>['id'=>$rid,'number'=>$number,'total'=>$total]],201);
    });
}

/** Cantidad de una línea de compra ya devuelta al proveedor. */
private function returnedQuantity(int $purchase,int $detail):float{
    return (float)DB::table('purchase_return_details as rd')->join('purchase_returns as r','r.id','=','rd.purchase_return_id')->where(['r.purchase_id'=>$purchase,'rd.purchase_detail_id'=>$detail])->sum('rd.quantity');
}
 public function counts(Request $r){[$c]=$this->ctx($r);return response()->json(['success'=>true,'data'=>DB::table('inventory_counts as i')->join('warehouses as w','w.id','=','i.warehouse_id')->where('i.company_id',$c)->select('i.*','w.name as warehouse_name')->latest('i.id')->get()]);}
public function createCount(Request $r){
    [$c,,$u]=$this->ctx($r);
    $d=$r->validate(['warehouse_id'=>'required|integer','items'=>'required|array|min:1','items.*.product_id'=>'required|integer','items.*.counted_quantity'=>'required|numeric|min:0']);
    // Almacén y productos deben ser de la empresa del usuario.
    $warehouse=DB::table('warehouses')->where(['id'=>$d['warehouse_id'],'company_id'=>$c])->firstOrFail();
    foreach($d['items'] as $i)DB::table('products')->where(['id'=>$i['product_id'],'company_id'=>$c])->firstOrFail();
    return DB::transaction(function()use($r,$c,$u,$d,$warehouse){
        $number='CNT-'.str_pad((string)(DB::table('inventory_counts')->where('company_id',$c)->count()+1),6,'0',STR_PAD_LEFT);
        $id=DB::table('inventory_counts')->insertGetId(['uuid'=>Str::uuid(),'company_id'=>$c,'warehouse_id'=>$warehouse->id,'user_id'=>$u,'number'=>$number,'status'=>'confirmed','counted_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        foreach($d['items'] as $i){
            $s=DB::table('stocks')->where(['company_id'=>$c,'warehouse_id'=>$warehouse->id,'product_id'=>$i['product_id']])->lockForUpdate()->first();
            $before=(float)($s->quantity??0); $diff=$i['counted_quantity']-$before;
            DB::table('inventory_count_details')->insert(['inventory_count_id'=>$id,'product_id'=>$i['product_id'],'system_quantity'=>$before,'counted_quantity'=>$i['counted_quantity'],'difference'=>$diff,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('stocks')->updateOrInsert(['company_id'=>$c,'warehouse_id'=>$warehouse->id,'product_id'=>$i['product_id']],['branch_id'=>$warehouse->branch_id,'quantity'=>$i['counted_quantity'],'created_at'=>$s->created_at??now(),'updated_at'=>now()]);
            if($diff<-0.0005)app(\App\Services\LotService::class)->consume($c,$warehouse->id,$i['product_id'],-$diff,'inventory_count',$id);
            if(abs($diff)>0.0005){
                $cost=DB::table('products')->where('id',$i['product_id'])->value('average_cost');
                DB::table('inventory_movements')->insert(['uuid'=>Str::uuid(),'company_id'=>$c,'branch_id'=>$warehouse->branch_id??$r->user()->branch_id,'warehouse_id'=>$warehouse->id,'product_id'=>$i['product_id'],'user_id'=>$u,'type'=>'count_adjustment','quantity'=>$diff,'historical_unit_cost'=>$cost,'previous_stock'=>$before,'new_stock'=>$i['counted_quantity'],'document_type'=>'inventory_count','document_id'=>$id,'document_number'=>$number,'created_at'=>now(),'updated_at'=>now()]);
            }
        }
        return response()->json(['success'=>true,'message'=>'Conteo confirmado','data'=>['id'=>$id,'number'=>$number]],201);
    });
}
 public function reservations(Request $r){[$c]=$this->ctx($r);return response()->json(['success'=>true,'data'=>DB::table('stock_reservations as s')->join('products as p','p.id','=','s.product_id')->join('warehouses as w','w.id','=','s.warehouse_id')->where('s.company_id',$c)->select('s.*','p.name as product_name','p.category','p.brand','w.name as warehouse_name')->latest('s.id')->get()]);}
 public function releaseReservation(Request $r,int $id){[$c]=$this->ctx($r);DB::table('stock_reservations')->where(['id'=>$id,'company_id'=>$c])->update(['status'=>'released','updated_at'=>now()]);return response()->json(['success'=>true,'message'=>'Reserva liberada']);}
}
