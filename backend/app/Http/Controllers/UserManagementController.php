<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
class UserManagementController extends Controller{
 private function user(Request$r,int$id):User{return User::where(['id'=>$id,'company_id'=>$r->user()->company_id])->when(!\App\Support\PlatformAccess::allows($r->user()),fn($q)=>$q->where('role','!=','platform_admin'))->firstOrFail();}
 
 public function options(Request$r){$c=$r->user()->company_id;return response()->json(['success'=>true,'data'=>['branches'=>DB::table('branches')->where(['company_id'=>$c,'status'=>'active'])->get(),'roles'=>DB::table('roles')->where('company_id',$c)->get()]]);}
 public function show(Request$r,int$id){$u=$this->user($r,$id)->only(['id','name','email','phone','notes','avatar','role','status','branch_id','last_login_at','created_at']);$u['branch']=DB::table('branches')->where('id',$u['branch_id'])->first();$u['roles']=DB::table('roles as r')->join('role_user as ru','ru.role_id','=','r.id')->where('ru.user_id',$id)->select('r.*')->get();$u['permissions']=DB::table('permissions as p')->join('permission_role as pr','pr.permission_id','=','p.id')->join('role_user as ru','ru.role_id','=','pr.role_id')->where('ru.user_id',$id)->select('p.module','p.action','p.slug')->distinct()->get();$u['sessions']=DB::table('user_sessions')->where('user_id',$id)->latest('last_activity_at')->get();$u['access_history']=DB::table('audit_events')->where(['company_id'=>$r->user()->company_id,'entity'=>'auth','entity_id'=>$id])->latest()->limit(100)->get();return response()->json(['success'=>true,'data'=>$u]);}
 public function profile(Request$r,int$id){$u=$this->user($r,$id);$d=$r->validate(['name'=>'required|string|max:150','phone'=>'nullable|string|max:30','notes'=>'nullable|string|max:2000','avatar'=>'nullable|string|max:1500000']);$u->update($d);$this->recordAudit($r,'user.profile_updated','users',$id,array_diff_key($d,['avatar'=>true]));return response()->json(['success'=>true,'message'=>'Perfil actualizado']);}
 public function password(Request$r,int$id){$u=$this->user($r,$id);$d=$r->validate(['password'=>'required|string|min:8|confirmed']);$u->update(['password'=>Hash::make($d['password'])]);DB::table('user_sessions')->where('user_id',$id)->delete();$this->recordAudit($r,'user.password_changed','users',$id);return response()->json(['success'=>true,'message'=>'Contraseña actualizada; se cerraron sus sesiones']);}
 public function revoke(Request$r,int$id,int$session){$this->user($r,$id);DB::table('user_sessions')->where(['id'=>$session,'user_id'=>$id])->delete();$this->recordAudit($r,'user.session_revoked','users',$id,['session_id'=>$session]);return response()->json(['success'=>true,'message'=>'Sesión revocada']);}
}
