<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminController extends Controller {
    private array $resources=[
        'categories'=>['table'=>'categories','fields'=>['name','parent_id','sort_order','status']],
        'brands'=>['table'=>'brands','fields'=>['name','logo','status']],
        
        
        
        'suppliers'=>['table'=>'suppliers','fields'=>['document_type','document_number','name','contact','phone','email','address','status']],
        'roles'=>['table'=>'roles','fields'=>['name','slug','description']],
        'promotions'=>['table'=>'promotions','fields'=>['name','type','value','rules','starts_at','ends_at','status']],
        'notifications'=>['table'=>'notifications','fields'=>['user_id','type','title','message','link','read_at']],
        'web-orders'=>['table'=>'web_orders','fields'=>['customer_id','customer_name','customer_phone','items','total','status','delivery_address']],
        'integrations'=>['table'=>'integration_logs','fields'=>['provider','operation','status','request_data','response_data','error']],
        'ai-logs'=>['table'=>'ai_logs','fields'=>['user_id','feature','model','tokens','cost','prompt','response','status']],
    ];
    private function definition(string $resource): array {abort_unless(isset($this->resources[$resource]),404);return $this->resources[$resource];}
    // Una subcategoría cuelga de una categoría principal de la misma empresa (un solo nivel) y nunca de sí misma.
    private function parentRule(int $companyId,?int $self=null): array{return ['nullable','integer',Rule::exists('categories','id')->where(fn($q)=>$q->where('company_id',$companyId)->whereNull('parent_id')->when($self,fn($q)=>$q->where('id','!=',$self)))];}
    public function index(Request $r,string $resource){$d=$this->definition($resource);$q=DB::table($d['table'])->where('company_id',$r->user()->company_id);$rows=$q->latest('id')->get();if($resource==='categories'){$names=$rows->pluck('name','id');foreach($rows as $row)$row->parent_name=$row->parent_id?($names[$row->parent_id]??null):null;}if($resource==='roles')foreach($rows as $row)$row->permission_ids=DB::table('permission_role')->where('role_id',$row->id)->pluck('permission_id')->map(fn($id)=>(int)$id)->values();return response()->json(['success'=>true,'data'=>$rows]);}
    public function store(Request $r,string $resource){$d=$this->definition($resource);$companyId=$r->user()->company_id;if($resource==='categories'){$r->merge(['name'=>trim((string)$r->name)]);$r->validate(['name'=>['required','string','max:120',function($a,$v,$fail)use($companyId){if(DB::table('categories')->where('company_id',$companyId)->whereRaw('LOWER(name)=?',[mb_strtolower($v)])->exists())$fail('Ya existe una categoría con ese nombre.');}],'parent_id'=>$this->parentRule($companyId)]);}
        if($resource==='suppliers')$r->validate(['name'=>'required|string|max:200','document_type'=>'nullable|in:DNI,RUC,CE,PASSPORT','document_number'=>[...\App\Support\DocumentRules::number($r->input('document_type')),function($a,$v,$fail)use($companyId){if($v&&DB::table('suppliers')->where(['company_id'=>$companyId,'document_number'=>trim($v)])->exists())$fail('Ya existe un proveedor con ese documento.');}],'email'=>'nullable|email']);
        if($resource==='brands'){$r->merge(['name'=>trim((string)$r->name)]);$r->validate(['name'=>['required','string','max:255',Rule::unique('brands','name')->where(fn($q)=>$q->where('company_id',$companyId))]]);} $data=$r->only($d['fields']);foreach(['rules','items','request_data','response_data'] as $json)if(isset($data[$json])&&is_array($data[$json]))$data[$json]=json_encode($data[$json]);$data['company_id']=$companyId;if($resource==='categories'){$r->validate(['name'=>'required|string|max:255']);$base=Str::slug($data['name']);$slug=$base;$suffix=2;while(DB::table('categories')->where(['company_id'=>$data['company_id'],'slug'=>$slug])->exists())$slug=$base.'-'.$suffix++;$data['slug']=$slug;$data['status']=$data['status']??'active';$data['sort_order']=$data['sort_order']??0;}if($resource==='brands')$data['status']=$data['status']??'active';if($resource==='web-orders'){$data['uuid']=Str::uuid();$data['number']='WEB-'.str_pad((string)(DB::table('web_orders')->count()+1),6,'0',STR_PAD_LEFT);}if($resource==='warehouses')$data['branch_id']??=$r->user()->branch_id;$data['created_at']=$data['updated_at']=now();$id=DB::table($d['table'])->insertGetId($data);$this->recordAudit($r,'created',$resource,$id,$data);return response()->json(['success'=>true,'message'=>'Registro creado','data'=>['id'=>$id]],201);}
    public function update(Request $r,string $resource,int $id){$d=$this->definition($resource);if($resource==='categories'){$r->merge(['name'=>trim((string)$r->name)]);$companyId=$r->user()->company_id;$r->validate(['name'=>['required','string','max:120',function($a,$v,$fail)use($companyId,$id){if(DB::table('categories')->where('company_id',$companyId)->where('id','!=',$id)->whereRaw('LOWER(name)=?',[mb_strtolower($v)])->exists())$fail('Ya existe una categoría con ese nombre.');}],'parent_id'=>$this->parentRule($companyId,$id)]);}$data=$r->only($d['fields']);foreach(['rules','items','request_data','response_data'] as $json)if(isset($data[$json])&&is_array($data[$json]))$data[$json]=json_encode($data[$json]);$data['updated_at']=now();DB::table($d['table'])->where(['id'=>$id,'company_id'=>$r->user()->company_id])->update($data);$this->recordAudit($r,'updated',$resource,$id,$data);return response()->json(['success'=>true,'message'=>'Registro actualizado']);}
    public function destroy(Request $r,string $resource,int $id){$d=$this->definition($resource);$q=DB::table($d['table'])->where(['id'=>$id,'company_id'=>$r->user()->company_id]);if(in_array('status',$d['fields']))$q->update(['status'=>'inactive','updated_at'=>now()]);else $q->delete();$this->recordAudit($r,'deleted',$resource,$id);return response()->json(['success'=>true,'message'=>'Registro desactivado']);}
public function companies(Request $r){
    // Solo el superadministrador de la plataforma ve todas las empresas; una tienda ve la suya.
    $q=DB::table('companies')->orderBy('trade_name');
    if(!\App\Support\PlatformAccess::allows($r->user()))$q->where('id',$r->user()->company_id);
    return response()->json(['success'=>true,'data'=>$q->get()]);
}
public function saveCompany(Request $r,?int $id=null){
    $platform=\App\Support\PlatformAccess::allows($r->user());
    // Crear empresas es tarea de la plataforma; una tienda solo edita la suya.
    abort_unless($id?($platform||$r->user()->company_id===$id):$platform,403,$id?'No puedes modificar otra empresa':'Solo el superadministrador de la plataforma puede crear empresas');
    if($id)DB::table('companies')->where('id',$id)->firstOrFail();
    $data=$r->validate(['business_name'=>'required|string|max:200','trade_name'=>'required|string|max:120','ruc'=>['required','digits:11',\Illuminate\Validation\Rule::unique('companies','ruc')->ignore($id)],'email'=>'nullable|email','phone'=>'nullable','address'=>'nullable','currency'=>'required|size:3','timezone'=>'required|timezone','locale'=>'nullable','date_format'=>'nullable','tax_regime'=>'nullable','logo'=>'nullable|string|max:1500000','status'=>'required|in:active,inactive','admin_name'=>$id?'prohibited':'required|string|max:120','admin_email'=>$id?'prohibited':['required','email','max:190',\Illuminate\Validation\Rule::unique('users','email')],'admin_password'=>$id?'prohibited':'required|string|min:8'],['ruc.unique'=>'Ya existe una empresa registrada con ese RUC.','admin_email.unique'=>'Ya existe un usuario con ese correo.']);
    $owner=array_intersect_key($data,array_flip(['admin_name','admin_email','admin_password']));$data=array_diff_key($data,$owner);
    // Activar o suspender una empresa lo decide la plataforma, no la propia tienda.
    if(!$platform)unset($data['status']);
    $data['updated_at']=now();
    return DB::transaction(function()use($r,$id,$data,$owner){
        if($id){DB::table('companies')->where('id',$id)->update($data);}
        else{$id=DB::table('companies')->insertGetId($data+['uuid'=>Str::uuid(),'created_at'=>now()]);$provisioner=new \App\Services\CompanyProvisioner();$branch=$provisioner->provisionCompany($id);$provisioner->firstAdmin($id,$branch,$owner['admin_name'],$owner['admin_email'],$owner['admin_password']);}
        $this->recordAudit($r,'company.saved','companies',$id,array_diff_key($data,['logo'=>true]));
        return response()->json(['success'=>true,'message'=>'Empresa guardada','data'=>['id'=>$id]]);
    });
}
    public function users(Request $r){return response()->json(['success'=>true,'data'=>User::where('company_id',$r->user()->company_id)->when(!\App\Support\PlatformAccess::allows($r->user()),fn($q)=>$q->where('role','!=','platform_admin'))->select('id','name','email','phone','avatar','role','status','branch_id','last_login_at','created_at')->get()]);}
public function saveUser(Request $r,?int $id=null){
    $c=$r->user()->company_id;
    $target=$id?User::where(['id'=>$id,'company_id'=>$c])->firstOrFail():null;
    // Un administrador de empresa no puede tocar la cuenta del superadministrador (contraseña, rol o estado).
    abort_if($target&&$target->role==='platform_admin'&&!\App\Support\PlatformAccess::allows($r->user()),404);
    $data=$r->validate([
        'name'=>'required|string|max:120',
        'email'=>['required','email','max:190',Rule::unique('users','email')->ignore($id)],
        'password'=>($id?'nullable':'required').'|string|min:8',
        'phone'=>'nullable|max:30','notes'=>'nullable|max:2000',
        // Solo roles de la propia empresa: "platform_admin" no es un rol de empresa y nunca se asigna desde aquí.
        'role'=>['required',Rule::exists('roles','slug')->where('company_id',$c)],
        'branch_id'=>['required','integer',Rule::exists('branches','id')->where(fn($q)=>$q->where(['company_id'=>$c,'status'=>'active']))],
        'status'=>'nullable|in:active,inactive,suspended',
    ],['email.unique'=>'Ya existe un usuario con ese correo.','role.exists'=>'Elige uno de los roles de la empresa.','branch_id.exists'=>'Elige una sucursal activa de la empresa.']);
    abort_if($id===$r->user()->id&&($data['status']??'active')==='inactive',422,'No puedes desactivar tu propio usuario.');
    $data['company_id']=$c;
    if(!empty($data['password']))$data['password']=Hash::make($data['password']);else unset($data['password']);
    // El rol de empresa elegido define sus permisos; la marca de superadministrador se conserva.
    $companyRole=$data['role'];if($target&&$target->role==='platform_admin')$data['role']='platform_admin';
    return DB::transaction(function()use($r,$id,$data,$c,$companyRole){
        if($id)User::where(['id'=>$id,'company_id'=>$c])->update($data);
        else $id=User::create($data)->id;
        $role=DB::table('roles')->where(['company_id'=>$c,'slug'=>$companyRole])->first();
        DB::table('role_user')->where('user_id',$id)->delete();
        DB::table('role_user')->insert(['role_id'=>$role->id,'user_id'=>$id]);
        $this->recordAudit($r,'user.saved','users',$id,array_diff_key($data,['password'=>true]));
        return response()->json(['success'=>true,'message'=>'Usuario guardado','data'=>['id'=>$id]]);
    });
}
    public function permissions(){return response()->json(['success'=>true,'data'=>DB::table('permissions')->orderBy('module')->orderBy('action')->get()]);}
    public function assignPermissions(Request $r,int $role){$roleRow=DB::table('roles')->where(['id'=>$role,'company_id'=>$r->user()->company_id])->firstOrFail();$ids=$r->validate(['permission_ids'=>'array','permission_ids.*'=>'integer|exists:permissions,id'])['permission_ids']??[];DB::transaction(function()use($role,$ids){DB::table('permission_role')->where('role_id',$role)->delete();if($ids)DB::table('permission_role')->insert(array_map(fn($id)=>['role_id'=>$role,'permission_id'=>$id],$ids));});$this->recordAudit($r,'permissions.assigned','roles',$role,['permission_ids'=>$ids]);return response()->json(['success'=>true,'message'=>'Permisos actualizados']);}
    public function duplicateRole(Request $r,int $role){$source=DB::table('roles')->where(['id'=>$role,'company_id'=>$r->user()->company_id])->firstOrFail();return DB::transaction(function()use($r,$source){$id=DB::table('roles')->insertGetId(['company_id'=>$source->company_id,'name'=>$source->name.' (copia)','slug'=>$source->slug.'-copia-'.now()->format('His'),'description'=>$source->description,'created_at'=>now(),'updated_at'=>now()]);$permissions=DB::table('permission_role')->where('role_id',$source->id)->pluck('permission_id');if($permissions->isNotEmpty())DB::table('permission_role')->insert($permissions->map(fn($p)=>['role_id'=>$id,'permission_id'=>$p])->all());$this->recordAudit($r,'role.duplicated','roles',$id,['source_role_id'=>$source->id]);return response()->json(['success'=>true,'message'=>'Rol duplicado','data'=>['id'=>$id]],201);});}
    public function roleHistory(Request $r,int $role){DB::table('roles')->where(['id'=>$role,'company_id'=>$r->user()->company_id])->firstOrFail();return response()->json(['success'=>true,'data'=>DB::table('audit_events as a')->leftJoin('users as u','u.id','=','a.user_id')->where(['a.company_id'=>$r->user()->company_id,'a.entity'=>'roles','a.entity_id'=>$role])->select('a.*','u.name as user_name')->latest('a.created_at')->get()]);}
    public function auditLog(Request $r){return response()->json(['success'=>true,'data'=>DB::table('audit_events as a')->leftJoin('users as u','u.id','=','a.user_id')->where('a.company_id',$r->user()->company_id)->select('a.*','u.name as user_name')->latest('a.created_at')->limit(1000)->get()]);}
    public function settings(Request $r){return response()->json(['success'=>true,'data'=>DB::table('settings')->where('company_id',$r->user()->company_id)->get()->groupBy('group')]);}
    public function saveSettings(Request $r){$r->validate(['settings'=>'required|array','settings.portal.delivery_fee'=>'sometimes|nullable|numeric|min:0|max:9999','settings.portal.free_delivery_from'=>'sometimes|nullable|numeric|min:0','settings.portal.store_lat'=>'sometimes|nullable|numeric|between:-90,90','settings.portal.store_lng'=>'sometimes|nullable|numeric|between:-180,180','settings.portal.pickup_address'=>'sometimes|nullable|string|max:255','settings.portal.pickup_hours'=>'sometimes|nullable|string|max:120','settings.portal.delivery_city'=>'sometimes|nullable|string|max:80','settings.portal.delivery_radius_km'=>'sometimes|nullable|numeric|min:0|max:200']+\App\Support\Appearance::rules(),['regex'=>'Los colores deben tener el formato #RRGGBB.']);foreach($r->input('settings',[]) as $group=>$values)foreach($values as $key=>$value)DB::table('settings')->updateOrInsert(['company_id'=>$r->user()->company_id,'group'=>$group,'key'=>$key],['value'=>is_bool($value)?($value?'1':'0'):($value===null?'':(is_scalar($value)?(string)$value:json_encode($value))),'updated_at'=>now(),'created_at'=>now()]);return response()->json(['success'=>true,'message'=>'Configuración guardada']);}
    
}
