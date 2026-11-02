<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;
class NotificationController extends Controller{
 public function index(Request$r){$q=DB::table('notifications')->where('company_id',$r->user()->company_id)->where(fn($x)=>$x->whereNull('user_id')->orWhere('user_id',$r->user()->id));if($r->status==='pending')$q->whereNull('read_at');if($r->status==='read')$q->whereNotNull('read_at');return response()->json(['success'=>true,'data'=>['rows'=>$q->latest()->limit(500)->get(),'pending'=>DB::table('notifications')->where('company_id',$r->user()->company_id)->whereNull('read_at')->count(),'settings'=>DB::table('settings')->where(['company_id'=>$r->user()->company_id,'group'=>'notifications'])->pluck('value','key')]]);}
 public function read(Request$r,int$id){DB::table('notifications')->where(['id'=>$id,'company_id'=>$r->user()->company_id])->update(['read_at'=>now(),'updated_at'=>now()]);return response()->json(['success'=>true,'message'=>'Notificación leída']);}
 public function readAll(Request$r){DB::table('notifications')->where('company_id',$r->user()->company_id)->whereNull('read_at')->update(['read_at'=>now(),'updated_at'=>now()]);return response()->json(['success'=>true,'message'=>'Notificaciones marcadas como leídas']);}
 public function configure(Request$r){$d=$r->validate(['stock'=>'boolean','orders'=>'boolean','cash'=>'boolean','security'=>'boolean','email'=>'boolean']);foreach($d as$k=>$v)DB::table('settings')->updateOrInsert(['company_id'=>$r->user()->company_id,'group'=>'notifications','key'=>$k],['value'=>$v?'1':'0','created_at'=>now(),'updated_at'=>now()]);return response()->json(['success'=>true,'message'=>'Preferencias guardadas']);}
}
