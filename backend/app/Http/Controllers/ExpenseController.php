<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    private function context(Request $request): array { return [$request->user()->company_id, $request->user()->branch_id, $request->user()->id]; }

    public function index(Request $request)
    {
        [$company,$branch]=$this->context($request);$period=$request->string('period')->toString()?:now()->format('Y-m');
        DB::table('expenses')->where(['company_id'=>$company,'branch_id'=>$branch,'financial_status'=>'pending'])->whereDate('due_date','<',today())->update(['financial_status'=>'overdue','updated_at'=>now()]);
        $query=DB::table('expenses as e')->leftJoin('recurring_expenses as r','r.id','=','e.recurring_expense_id')->leftJoin('meter_readings as mr','mr.id','=','e.meter_reading_id')->leftJoin('utility_meters as m','m.id','=','mr.utility_meter_id')->where(['e.company_id'=>$company,'e.branch_id'=>$branch,'e.status'=>'active'])->select('e.*','r.name as recurring_name','m.name as meter_name');
        $query->where('e.period',$period);if($request->filled('type'))$query->where('e.expense_type',$request->type);if($request->filled('financial_status'))$query->where('e.financial_status',$request->financial_status);if($request->filled('category'))$query->where('e.category',$request->category);
        return response()->json(['success'=>true,'data'=>$query->latest('e.expense_date')->get()]);
    }

    public function summary(Request $request)
    {
        [$company,$branch]=$this->context($request);$period=$request->string('period')->toString()?:now()->format('Y-m');$base=DB::table('expenses')->where(['company_id'=>$company,'branch_id'=>$branch,'period'=>$period,'status'=>'active']);$sum=fn($field,$value)=>(float)(clone $base)->where($field,$value)->sum('amount');
        return response()->json(['success'=>true,'data'=>['period'=>$period,'pending'=>$sum('financial_status','pending'),'paid'=>$sum('financial_status','paid'),'overdue'=>$sum('financial_status','overdue'),'fixed'=>$sum('expense_type','fixed'),'variable'=>(float)(clone $base)->whereIn('expense_type',['variable_metered','variable_manual'])->sum('amount')]]);
    }

    public function store(Request $request)
    {
        [$company,$branch,$user]=$this->context($request);$data=$request->validate(['category'=>'required','description'=>'required','amount'=>'required|numeric|min:0.01','expense_date'=>'required|date','due_date'=>'nullable|date']);
        $id=DB::table('expenses')->insertGetId($data+['company_id'=>$company,'branch_id'=>$branch,'user_id'=>$user,'expense_type'=>'variable_manual','financial_status'=>'pending','period'=>Carbon::parse($data['expense_date'])->format('Y-m'),'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['success'=>true,'message'=>'Gasto pendiente registrado','data'=>['id'=>$id]]);
    }

    public function update(Request $request,int $id)
    {
        [$company,$branch]=$this->context($request);$expense=DB::table('expenses')->where(['id'=>$id,'company_id'=>$company,'branch_id'=>$branch])->firstOrFail();abort_unless(in_array($expense->financial_status,['pending','overdue']),422,'Solo se pueden editar gastos pendientes');
        $data=$request->validate(['category'=>'required','description'=>'required','amount'=>'required|numeric|min:0.01','expense_date'=>'required|date','due_date'=>'nullable|date','status'=>'nullable']);$data['period']=Carbon::parse($data['expense_date'])->format('Y-m');DB::table('expenses')->where('id',$id)->update($data+['updated_at'=>now()]);return response()->json(['success'=>true,'message'=>'Gasto actualizado']);
    }

    public function recurring(Request $request){[$company,$branch]=$this->context($request);return response()->json(['success'=>true,'data'=>DB::table('recurring_expenses')->where(['company_id'=>$company,'branch_id'=>$branch])->orderBy('name')->get()]);}

    public function saveRecurring(Request $request,?int $id=null)
    {
        [$company,$branch]=$this->context($request);$data=$request->validate(['name'=>'required','category'=>'required','description'=>'required','usual_amount'=>'required|numeric|min:0.01','due_day'=>'required|integer|min:1|max:28','starts_on'=>'required|date','ends_on'=>'nullable|date|after_or_equal:starts_on','usual_payment_method'=>'nullable|in:cash,yape,card,transfer','status'=>'required|in:active,inactive']);$payload=$data+['company_id'=>$company,'branch_id'=>$branch,'updated_at'=>now()];
        if($id)DB::table('recurring_expenses')->where(['id'=>$id,'company_id'=>$company,'branch_id'=>$branch])->update($payload);else{$payload['created_at']=now();$id=DB::table('recurring_expenses')->insertGetId($payload);}return response()->json(['success'=>true,'message'=>'Gasto recurrente guardado','data'=>['id'=>$id]],$request->isMethod('post')?201:200);
    }

    public function generateRecurring(Request $request)
    {
        [$company,$branch,$user]=$this->context($request);$data=$request->validate(['period'=>['required','regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);$start=Carbon::createFromFormat('Y-m-d',$data['period'].'-01')->startOfMonth();$end=$start->copy()->endOfMonth();$templates=DB::table('recurring_expenses')->where(['company_id'=>$company,'branch_id'=>$branch,'status'=>'active'])->whereDate('starts_on','<=',$end)->where(fn($q)=>$q->whereNull('ends_on')->orWhereDate('ends_on','>=',$start))->get();$created=0;
        DB::transaction(function()use($templates,$company,$branch,$user,$data,$start,&$created){foreach($templates as$template)$created+=DB::table('expenses')->insertOrIgnore(['company_id'=>$company,'branch_id'=>$branch,'user_id'=>$user,'category'=>$template->category,'description'=>$template->description,'expense_type'=>'fixed','amount'=>$template->usual_amount,'financial_status'=>'pending','expense_date'=>$start->toDateString(),'period'=>$data['period'],'due_date'=>$start->copy()->day($template->due_day)->toDateString(),'payment_method'=>$template->usual_payment_method,'recurring_expense_id'=>$template->id,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);});
        return response()->json(['success'=>true,'message'=>"$created gasto(s) generado(s)",'data'=>['created'=>$created]]);
    }

    public function confirmPayment(Request $request,int $id)
    {
        [$company,$branch,$user]=$this->context($request);$data=$request->validate(['paid_at'=>'required|date','amount'=>'required|numeric|min:0.01','payment_method'=>'required|in:cash,yape,card,transfer','payment_reference'=>'nullable|string|max:255']);
        DB::transaction(function()use($id,$company,$branch,$user,$data){$expense=DB::table('expenses')->where(['id'=>$id,'company_id'=>$company,'branch_id'=>$branch])->lockForUpdate()->firstOrFail();abort_unless(in_array($expense->financial_status,['pending','overdue']),422,'El gasto no está pendiente');$cash=null;if($data['payment_method']==='cash'){$cash=DB::table('cash_sessions')->where(['company_id'=>$company,'branch_id'=>$branch,'status'=>'open'])->lockForUpdate()->first();abort_unless($cash,422,'Debe abrir una caja antes de confirmar un pago en efectivo');}DB::table('expenses')->where('id',$id)->update(['amount'=>$data['amount'],'financial_status'=>'paid','paid_at'=>$data['paid_at'],'payment_method'=>$data['payment_method'],'payment_reference'=>$data['payment_reference']??null,'updated_at'=>now()]);if($cash)DB::table('cash_movements')->insert(['cash_session_id'=>$cash->id,'type'=>'expense','amount'=>$data['amount'],'description'=>$expense->description,'reference'=>$data['payment_reference']??"G-$id",'user_id'=>$user,'created_at'=>now(),'updated_at'=>now()]);});
        return response()->json(['success'=>true,'message'=>'Pago confirmado']);
    }

    public function cancel(Request $request,int $id){[$company,$branch]=$this->context($request);$expense=DB::table('expenses')->where(['id'=>$id,'company_id'=>$company,'branch_id'=>$branch])->firstOrFail();abort_unless(in_array($expense->financial_status,['pending','overdue']),422,'Solo se pueden anular gastos pendientes');DB::table('expenses')->where('id',$id)->update(['financial_status'=>'cancelled','updated_at'=>now()]);return response()->json(['success'=>true,'message'=>'Gasto anulado']);}

    public function categories(Request $request){return response()->json(['success'=>true,'data'=>DB::table('expense_categories')->where('company_id',$request->user()->company_id)->orderBy('name')->get()]);}
    public function saveCategory(Request $request,?int $id=null){$data=$request->validate(['name'=>'required','color'=>'nullable','status'=>'required']);$data['company_id']=$request->user()->company_id;$data['updated_at']=now();if($id)DB::table('expense_categories')->where(['id'=>$id,'company_id'=>$data['company_id']])->update($data);else{$data['created_at']=now();$id=DB::table('expense_categories')->insertGetId($data);}return response()->json(['success'=>true,'message'=>'Categoría guardada','data'=>['id'=>$id]]);}
    public function meters(Request $request){[$company,$branch]=$this->context($request);return response()->json(['success'=>true,'data'=>DB::table('utility_meters')->where(['company_id'=>$company,'branch_id'=>$branch])->latest()->get()]);}
    public function saveMeter(Request $request,?int $id=null){[$company,$branch]=$this->context($request);$data=$request->validate(['name'=>'required','type'=>'required|in:electricity,water,gas,other','unit'=>'required','identifier'=>'nullable','status'=>'required']);$payload=$data+['company_id'=>$company,'branch_id'=>$branch,'updated_at'=>now()];if($id)DB::table('utility_meters')->where(['id'=>$id,'company_id'=>$company,'branch_id'=>$branch])->update($payload);else{$payload['created_at']=now();$id=DB::table('utility_meters')->insertGetId($payload);}return response()->json(['success'=>true,'message'=>'Medidor guardado','data'=>['id'=>$id]]);}
    public function readings(Request $request,int $meter){[$company,$branch]=$this->context($request);$record=DB::table('utility_meters')->where(['id'=>$meter,'company_id'=>$company,'branch_id'=>$branch])->firstOrFail();return response()->json(['success'=>true,'data'=>DB::table('meter_readings as mr')->leftJoin('expenses as e','e.meter_reading_id','=','mr.id')->where('mr.utility_meter_id',$record->id)->select('mr.*','e.id as expense_id','e.financial_status as expense_status')->latest('mr.reading_date')->get()]);}

    public function saveReading(Request $request,int $meter)
    {
        [$company,$branch,$user]=$this->context($request);$record=DB::table('utility_meters')->where(['id'=>$meter,'company_id'=>$company,'branch_id'=>$branch])->firstOrFail();$data=$request->validate(['reading_date'=>'required|date','value'=>'required|numeric|min:0','amount'=>'nullable|numeric|min:0']);abort_if(DB::table('meter_readings')->where(['utility_meter_id'=>$meter,'reading_date'=>$data['reading_date']])->exists(),422,'Ya existe una lectura en esa fecha');$latest=DB::table('meter_readings')->where('utility_meter_id',$meter)->orderByDesc('reading_date')->first();if($latest){abort_if($data['reading_date']<=$latest->reading_date,422,'La lectura debe ser posterior a la última registrada');abort_if((float)$data['value']<(float)$latest->value,422,'La lectura no puede ser menor que la anterior');}$previous=(float)($latest->value??0);$consumption=(float)$data['value']-$previous;
        $readingId=DB::transaction(function()use($data,$meter,$record,$company,$branch,$user,$consumption){$readingId=DB::table('meter_readings')->insertGetId($data+['utility_meter_id'=>$meter,'user_id'=>$user,'consumption'=>$consumption,'created_at'=>now(),'updated_at'=>now()]);if(($data['amount']??0)>0)DB::table('expenses')->insert(['company_id'=>$company,'branch_id'=>$branch,'user_id'=>$user,'category'=>'Servicios','description'=>$record->name.' · recibo '.$data['reading_date'],'expense_type'=>'variable_metered','amount'=>$data['amount'],'financial_status'=>'pending','expense_date'=>$data['reading_date'],'period'=>Carbon::parse($data['reading_date'])->format('Y-m'),'due_date'=>$data['reading_date'],'meter_reading_id'=>$readingId,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);return$readingId;});
        return response()->json(['success'=>true,'message'=>'Lectura registrada','data'=>['id'=>$readingId,'consumption'=>$consumption]],201);
    }
}
