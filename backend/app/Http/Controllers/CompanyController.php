<?php
namespace App\Http\Controllers;
use App\Support\PlatformAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class CompanyController extends Controller{
 private function authorizeCompany(Request$r,int$id):void{abort_unless(PlatformAccess::allows($r->user())||$r->user()->company_id===$id,403);}
 public function show(Request$r,int$id){$this->authorizeCompany($r,$id);$x=DB::table('companies')->where('id',$id)->firstOrFail();$x->branches=DB::table('branches')->where('company_id',$id)->get();$x->users_count=DB::table('users')->where('company_id',$id)->count();$x->warehouses_count=DB::table('warehouses')->where('company_id',$id)->count();$x->settings=DB::table('settings')->where('company_id',$id)->get()->groupBy('group');return response()->json(['success'=>true,'data'=>$x]);}
public function profile(Request$r,int$id){
    $this->authorizeCompany($r,$id);
    DB::table('companies')->where('id',$id)->firstOrFail();
    $d=$r->validate(['business_name'=>'required|string|max:200','trade_name'=>'required|string|max:120','ruc'=>['required','digits:11',\Illuminate\Validation\Rule::unique('companies','ruc')->ignore($id)],'email'=>'nullable|email','phone'=>'nullable','address'=>'nullable','currency'=>'required|size:3','timezone'=>'required|timezone','locale'=>'required','date_format'=>'required','tax_regime'=>'nullable','logo'=>'nullable|string|max:1500000','status'=>'required|in:active,inactive'],['ruc.unique'=>'Ya existe una empresa registrada con ese RUC.']);
    // Una tienda no puede suspender su propia empresa (se quedaría sin acceso): lo decide la plataforma.
    if(!PlatformAccess::allows($r->user()))unset($d['status']);
    DB::table('companies')->where('id',$id)->update($d+['updated_at'=>now()]);
    $this->recordAudit($r,'company.saved','companies',$id,array_diff_key($d,['logo'=>true]));
    return response()->json(['success'=>true,'message'=>'Empresa actualizada']);
}
}
