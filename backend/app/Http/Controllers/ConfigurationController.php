<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;use Illuminate\Validation\Rule;
class ConfigurationController extends Controller{
 private array$defs=['payment-methods'=>['payment_methods',['name','code','requires_reference','affects_cash','status']],'tax-rates'=>['tax_rates',['name','code','rate','included','status']],'printers'=>['printers',['branch_id','name','type','connection','paper_size','settings','status']],'cash-registers'=>['cash_registers',['branch_id','code','name','status']],'document-series'=>['document_series',['branch_id','document_type','series','current_number','active']],'warehouse-locations'=>['warehouse_locations',['warehouse_id','code','name','zone','aisle','rack','level','status']]];
 private function def(string$r):array{abort_unless(isset($this->defs[$r]),404);return$this->defs[$r];}
 public function index(Request$r,string$res){[$table]=$this->def($res);return response()->json(['success'=>true,'data'=>DB::table($table)->where('company_id',$r->user()->company_id)->latest('id')->get()]);}
public function save(Request$r,string$res,?int$id=null){
    [$table,$fields]=$this->def($res);
    $c=$r->user()->company_id;
    if($id)DB::table($table)->where(['id'=>$id,'company_id'=>$c])->firstOrFail();
    if(in_array($res,['printers','cash-registers','document-series']))$r->merge(['branch_id'=>$r->input('branch_id',$r->user()->branch_id)]);
    $d=$r->validate($this->rules($res,$c,$id,$r),['unique'=>'Ya existe un registro con ese :attribute.','series.regex'=>'La serie debe tener 4 caracteres: una letra y tres números (p. ej. T001) o NC01/ND01.']);
    $d=array_intersect_key($d,array_flip($fields));
    if(isset($d['settings'])&&is_array($d['settings']))$d['settings']=json_encode($d['settings']);
    // El correlativo de una serie nunca retrocede: repetiría números de comprobante ya emitidos.
    if($res==='document-series'&&$id&&isset($d['current_number'])){$current=(int)DB::table('document_series')->where('id',$id)->value('current_number');abort_if((int)$d['current_number']<$current,422,"El correlativo no puede bajar de {$current}: ya se emitieron esos números.");}
    $d['company_id']=$c;$d['updated_at']=now();
    if($id)DB::table($table)->where(['id'=>$id,'company_id'=>$c])->update($d);
    else{$d['created_at']=now();$id=DB::table($table)->insertGetId($d);}
    return response()->json(['success'=>true,'message'=>'Configuración guardada','data'=>['id'=>$id]]);
}

/** Reglas por recurso: códigos únicos y referencias (sucursal, almacén) siempre de la propia empresa. */
private function rules(string$res,int$c,?int$id,Request$r):array{
    $branch=['required','integer',Rule::exists('branches','id')->where(fn($q)=>$q->where(['company_id'=>$c,'status'=>'active']))];
    $status='nullable|in:active,inactive';
    return match($res){
        'payment-methods'=>['name'=>'required|string|max:60','code'=>['required','alpha_dash','max:20',Rule::unique('payment_methods','code')->where('company_id',$c)->ignore($id)],'requires_reference'=>'boolean','affects_cash'=>'boolean','status'=>$status],
        'tax-rates'=>['name'=>'required|string|max:60','code'=>['required','alpha_dash','max:20',Rule::unique('tax_rates','code')->where('company_id',$c)->ignore($id)],'rate'=>'required|numeric|min:0|max:100','included'=>'boolean','status'=>$status],
        'printers'=>['branch_id'=>$branch,'name'=>'required|string|max:80','type'=>'required|in:ticket,label,document','connection'=>'nullable|string|max:120','paper_size'=>'nullable|string|max:20','settings'=>'nullable|array','status'=>$status],
        'cash-registers'=>['branch_id'=>$branch,'code'=>['required','string','max:20',Rule::unique('cash_registers','code')->where('branch_id',$r->input('branch_id'))->ignore($id)],'name'=>'required|string|max:80','status'=>$status],
        // Un RUC no puede repetir la misma serie para el mismo tipo de comprobante en dos locales.
        'document-series'=>['branch_id'=>$branch,'document_type'=>'required|in:ticket,receipt,invoice,credit_note,debit_note,purchase','series'=>['required','regex:/^([A-Z]\d{3}|NC\d{2}|ND\d{2})$/',Rule::unique('document_series','series')->where(fn($q)=>$q->where(['company_id'=>$c,'document_type'=>$r->input('document_type')]))->ignore($id)],'current_number'=>'nullable|integer|min:0','active'=>'boolean'],
        'warehouse-locations'=>['warehouse_id'=>['required','integer',Rule::exists('warehouses','id')->where('company_id',$c)],'code'=>['required','string','max:20',Rule::unique('warehouse_locations','code')->where('warehouse_id',$r->input('warehouse_id'))->ignore($id)],'name'=>'required|string|max:80','zone'=>'nullable|string|max:40','aisle'=>'nullable|string|max:40','rack'=>'nullable|string|max:40','level'=>'nullable|string|max:40','status'=>$status],
    };
}
 public function destroy(Request$r,string$res,int$id){[$table,$fields]=$this->def($res);$q=DB::table($table)->where(['id'=>$id,'company_id'=>$r->user()->company_id]);if(in_array('status',$fields))$q->update(['status'=>'inactive','updated_at'=>now()]);elseif(in_array('active',$fields))$q->update(['active'=>false,'updated_at'=>now()]);else$q->delete();return response()->json(['success'=>true,'message'=>'Configuración desactivada']);}
 public function branchDetail(Request$r,int$id){$c=$r->user()->company_id;$branch=DB::table('branches')->where(['id'=>$id,'company_id'=>$c])->firstOrFail();$branch->users=DB::table('users')->where(['company_id'=>$c,'branch_id'=>$id])->select('id','name','email','role','status')->get();$branch->warehouses=$this->branchWarehousesQuery($c,$id)->select('w.*',DB::raw('CASE WHEN w.branch_id IS NULL THEN 1 ELSE 0 END as shared'))->orderBy('w.name')->get();foreach($branch->warehouses as $w)$w->is_sales=(int)$w->id===(int)$branch->default_warehouse_id;$branch->cash_registers=DB::table('cash_registers')->where(['company_id'=>$c,'branch_id'=>$id])->get();$branch->series=DB::table('document_series')->where(['company_id'=>$c,'branch_id'=>$id])->get();$branch->printers=DB::table('printers')->where(['company_id'=>$c,'branch_id'=>$id])->get();return response()->json(['success'=>true,'data'=>$branch]);}
 public function warehouseDetail(Request$r,int$id){$c=$r->user()->company_id;$w=DB::table('warehouses')->where(['id'=>$id,'company_id'=>$c])->firstOrFail();$w->locations=DB::table('warehouse_locations')->where('warehouse_id',$id)->get();$w->stock=DB::table('stocks as s')->join('products as p','p.id','=','s.product_id')->where('s.warehouse_id',$id)->select('p.sku','p.name','s.quantity')->get();return response()->json(['success'=>true,'data'=>$w]);}
}
