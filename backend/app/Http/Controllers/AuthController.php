<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Appearance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Support\PlatformAccess;

class AuthController extends Controller {
    private function issueToken(User $user,Request $r):string{$token=Str::random(64);$hash=hash('sha256',$token);$user->update(['api_token'=>$hash,'last_login_at'=>now(),'failed_login_attempts'=>0,'locked_until'=>null]);DB::table('user_sessions')->insert(['user_id'=>$user->id,'token_hash'=>$hash,'ip_address'=>$r->ip(),'user_agent'=>substr((string)$r->userAgent(),0,1000),'last_activity_at'=>now(),'expires_at'=>now()->addDays(30),'created_at'=>now(),'updated_at'=>now()]);DB::table('audit_events')->insert(['uuid'=>Str::uuid(),'company_id'=>$user->company_id,'user_id'=>$user->id,'by_platform'=>PlatformAccess::allows($user),'event'=>'auth.login','entity'=>'auth','entity_id'=>$user->id,'after'=>json_encode(['user_agent'=>substr((string)$r->userAgent(),0,300)]),'ip'=>$r->ip(),'created_at'=>now(),'updated_at'=>now()]);return$token;}
    // Espera mínima entre códigos: varios clics en «Ingresar» no envían varios correos.
    private const CODE_COOLDOWN = 60;
    private const CODE_MINUTES = 10;

    /** Segundos que faltan para poder enviar otro código (0 si ya se puede). */
    private function codeCooldown(User $user): int{
        if(!$user->two_factor_expires_at?->isFuture())return 0;
        $issued=$user->two_factor_expires_at->copy()->subMinutes(self::CODE_MINUTES);
        return max(0,self::CODE_COOLDOWN-(int)$issued->diffInSeconds(now()));
    }

    /** Envía un código nuevo, salvo que ya se haya enviado uno hace menos de un minuto. */
    private function twoFactorChallenge(User $user,bool $resend=false){
        $wait=$this->codeCooldown($user);
        if($wait>0){
            abort_if($resend,429,"Espera {$wait} segundos para pedir otro código.");
            return response()->json(['success'=>true,'data'=>['two_factor_required'=>true,'email'=>$user->email,'already_sent'=>true,'resend_in'=>$wait]]);
        }
        $code=(string)random_int(100000,999999);
        $user->update(['two_factor_code'=>hash('sha256',$code),'two_factor_expires_at'=>now()->addMinutes(self::CODE_MINUTES),'failed_login_attempts'=>0,'locked_until'=>null]);
        // Si el correo falla (contraseña de Gmail incorrecta, sin internet…), el ingreso no se cae.
        $mailer=app(\App\Services\CompanyMailer::class);$company=PlatformAccess::allows($user)?null:$user->company_id;$own=$mailer->settings($company);
        $sent=true;try{$mailer->send($company,$user->email,'Código de verificación Deurstore',"Su código de verificación de Deurstore es {$code}. Vence en ".self::CODE_MINUTES." minutos. Si no intentaste ingresar, cambia tu contraseña.",null,$own);}catch(\Throwable $e){$sent=false;report($e);}
        // En desarrollo local, sin correo real o si el envío falló, el código se muestra en pantalla.
        $local=app()->isLocal()&&(!$sent||!$mailer->delivers($company));
        abort_if(!$sent&&!$local,503,'No se pudo enviar el código por correo. Pide al administrador del servidor un código con «php artisan user:login-code».');
        return response()->json(['success'=>true,'data'=>['two_factor_required'=>true,'email'=>$user->email,'resend_in'=>self::CODE_COOLDOWN]+($local?['dev_code'=>$code,'mail_failed'=>!$sent]:[])]);
    }

    /** «Reenviar código»: solo con un ingreso pendiente (contraseña ya validada en los últimos 10 minutos). */
    public function resendTwoFactor(Request $r){
        $d=$r->validate(['email'=>'required|email']);
        $user=User::where('email',$d['email'])->where('status','active')->first();
        abort_unless($user&&$user->two_factor_expires_at?->isFuture(),422,'El ingreso venció. Vuelve a escribir tu correo y contraseña.');
        return $this->twoFactorChallenge($user,true);
    }
    public function login(Request $request) {
        $data = $request->validate(['email'=>'required|email','password'=>'required|string']);
        $user = User::where('email',$data['email'])->first();
        if($user?->locked_until && $user->locked_until->isFuture()) return response()->json(['success'=>false,'message'=>'Cuenta bloqueada temporalmente. Intente después de '.$user->locked_until->format('H:i')],423);
        if (!$user || !Hash::check($data['password'],$user->password)) {if($user){$attempts=$user->failed_login_attempts+1;$user->update(['failed_login_attempts'=>$attempts,'locked_until'=>$attempts>=5?now()->addMinutes(15):null]);}return response()->json(['success'=>false,'message'=>'Credenciales incorrectas'],422);}
        if ($user->status !== 'active') return response()->json(['success'=>false,'message'=>'La cuenta está suspendida'],403);
        if($user->two_factor_enabled||PlatformAccess::allows($user))return $this->twoFactorChallenge($user);
        $token = $this->issueToken($user,$request);
        return response()->json(['success'=>true,'data'=>['token'=>$token,'user'=>$user->only('id','name','email','role','company_id','branch_id')]]);
    }
    // Activar o terminar la edición del superadministrador en esta sesión (con motivo, queda en auditoría).
    public function startPlatformWrite(Request $r){PlatformAccess::authorize($r->user());$d=$r->validate(['reason'=>'required|string|min:10|max:500','minutes'=>'nullable|integer|in:15,30,60,120'],['reason.min'=>'Describe el motivo con al menos 10 caracteres.']);$until=now()->addMinutes($d['minutes']??30);DB::table('user_sessions')->where('id',$r->attributes->get('session_id'))->update(['platform_write_until'=>$until,'platform_write_reason'=>$d['reason'],'updated_at'=>now()]);$this->recordAudit($r,'platform.write_enabled','platform',null,['reason'=>$d['reason'],'until'=>$until->toDateTimeString()]);return response()->json(['success'=>true,'message'=>'Edición activada hasta las '.$until->format('H:i'),'data'=>['until'=>$until->toIso8601String()]]);}
    public function stopPlatformWrite(Request $r){PlatformAccess::authorize($r->user());DB::table('user_sessions')->where('id',$r->attributes->get('session_id'))->update(['platform_write_until'=>null,'platform_write_reason'=>null,'updated_at'=>now()]);$this->recordAudit($r,'platform.write_disabled','platform',null);return response()->json(['success'=>true,'message'=>'Volviste al modo solo lectura']);}
    public function logout(Request $request) {if($request->attributes->get('session_id'))DB::table('user_sessions')->where('id',$request->attributes->get('session_id'))->delete();$request->user()->update(['api_token'=>null]);return response()->json(['success'=>true,'message'=>'Sesión cerrada']); }
    public function me(Request $request) {$u=$request->user();return response()->json(['success'=>true,'data'=>$u->only('id','name','email','role','company_id','branch_id','cash_session_id','email_verified_at','two_factor_enabled')+['can'=>['sales.discount'=>$this->userCan($request,'sales.discount'),'sales.override_price'=>$this->userCan($request,'sales.override_price'),'units.update'=>$this->userCan($request,'units.update')],'company'=>DB::table('companies')->where('id',$u->company_id)->first(),'branch'=>DB::table('branches')->where('id',$u->branch_id)->first(),'appearance'=>Appearance::for($u->company_id),'is_platform'=>PlatformAccess::allows($u),'platform_write'=>PlatformAccess::allows($u)?PlatformAccess::writeMode($request):null,'permissions'=>PlatformAccess::allows($u)?DB::table('permissions')->orderBy('slug')->pluck('slug'):DB::table('role_user as ru')->join('permission_role as pr','pr.role_id','=','ru.role_id')->join('permissions as p','p.id','=','pr.permission_id')->where('ru.user_id',$u->id)->distinct()->orderBy('p.slug')->pluck('p.slug')]]); }
    public function verifyTwoFactor(Request $r){$d=$r->validate(['email'=>'required|email','code'=>'required|digits:6']);$user=User::where('email',$d['email'])->first();abort_unless($user&&$user->two_factor_expires_at?->isFuture()&&hash_equals((string)$user->two_factor_code,hash('sha256',$d['code'])),422,'Código inválido o vencido');$user->update(['two_factor_code'=>null,'two_factor_expires_at'=>null]);$token=$this->issueToken($user,$r);return response()->json(['success'=>true,'data'=>['token'=>$token,'user'=>$user->only('id','name','email','role','company_id','branch_id')]]);}
    public function forgot(Request $r){$email=$r->validate(['email'=>'required|email'])['email'];$user=User::where('email',$email)->first();if($user){$token=Str::random(64);DB::table('password_reset_tokens')->updateOrInsert(['email'=>$email],['token'=>hash('sha256',$token),'created_at'=>now()]);$url=rtrim(config('app.frontend_url'),'/')."/reset-password?email=".urlencode($email)."&token={$token}";try{app(\App\Services\CompanyMailer::class)->send(PlatformAccess::allows($user)?null:$user->company_id,$email,'Restablecer contraseña',"Recibimos una solicitud para restablecer tu contraseña de Deurstore.\n\nAbre este enlace (vence en 30 minutos):\n{$url}\n\nSi no la pediste, ignora este correo.");}catch(\Throwable $e){report($e);}}return response()->json(['success'=>true,'message'=>'Si el correo existe, recibirá las instrucciones']);}
    public function reset(Request $r){$d=$r->validate(['email'=>'required|email','token'=>'required','password'=>'required|min:8|confirmed']);$row=DB::table('password_reset_tokens')->where('email',$d['email'])->first();abort_unless($row&&hash_equals($row->token,hash('sha256',$d['token']))&&\Illuminate\Support\Carbon::parse($row->created_at)->addMinutes(30)->isFuture(),422,'Token inválido o vencido');User::where('email',$d['email'])->update(['password'=>Hash::make($d['password']),'api_token'=>null]);DB::table('password_reset_tokens')->where('email',$d['email'])->delete();return response()->json(['success'=>true,'message'=>'Contraseña actualizada']);}
    public function context(Request $r){$d=$r->validate(['company_id'=>'required|integer','branch_id'=>'required|integer','cash_session_id'=>'nullable|integer']);abort_unless(PlatformAccess::allows($r->user())||$r->user()->company_id===$d['company_id'],403);abort_unless(DB::table('branches')->where(['id'=>$d['branch_id'],'company_id'=>$d['company_id'],'status'=>'active'])->exists(),422,'Sucursal inválida');if(!empty($d['cash_session_id']))abort_unless(DB::table('cash_sessions')->where(['id'=>$d['cash_session_id'],'company_id'=>$d['company_id'],'branch_id'=>$d['branch_id'],'status'=>'open'])->exists(),422,'La caja seleccionada no está abierta en esa sucursal');$changed=(int)$r->user()->company_id!==(int)$d['company_id'];$r->user()->update($d);
        // La edición del superadministrador se activó con un motivo para una empresa: al cambiar de empresa vuelve a solo lectura.
        if($changed&&PlatformAccess::allows($r->user()))DB::table('user_sessions')->where('id',$r->attributes->get('session_id'))->update(['platform_write_until'=>null,'platform_write_reason'=>null]);
        return response()->json(['success'=>true,'message'=>'Contexto actualizado','data'=>$d]);}
    public function enableTwoFactor(Request $r){$r->user()->update(['two_factor_enabled'=>(bool)$r->boolean('enabled')]);return response()->json(['success'=>true,'message'=>$r->boolean('enabled')?'Segundo factor activado':'Segundo factor desactivado']);}
    public function contextOptions(Request $r){$u=$r->user();$companies=PlatformAccess::allows($u)?DB::table('companies')->where('status','active')->get():DB::table('companies')->where('id',$u->company_id)->get();$branches=DB::table('branches')->whereIn('company_id',$companies->pluck('id'))->where('status','active')->orderBy('company_id')->orderBy('name')->get();$cash=DB::table('cash_sessions')->where(['company_id'=>$u->company_id,'branch_id'=>$u->branch_id,'status'=>'open'])->get();return response()->json(['success'=>true,'data'=>compact('companies','branches','cash')]);}
    // Las sesiones vencidas ya no sirven para entrar: se borran en lugar de listarse.
    public function sessions(Request $r){$u=$r->user()->id;DB::table('user_sessions')->where('user_id',$u)->whereNotNull('expires_at')->where('expires_at','<=',now())->delete();$current=$r->attributes->get('session_id');return response()->json(['success'=>true,'data'=>DB::table('user_sessions')->where('user_id',$u)->select('id','ip_address','user_agent','last_activity_at','expires_at','created_at')->orderByRaw('id = ? desc',[$current??0])->latest('last_activity_at')->get()->map(function($s)use($current){$s->current=$s->id===$current;return$s;})]);}
    public function revokeOtherSessions(Request $r){$n=DB::table('user_sessions')->where('user_id',$r->user()->id)->where('id','!=',$r->attributes->get('session_id')??0)->delete();return response()->json(['success'=>true,'message'=>$n?"Se cerraron {$n} sesiones.":'No había otras sesiones abiertas.','data'=>['closed'=>$n]]);}
    public function revokeSession(Request $r,int $id){DB::table('user_sessions')->where(['id'=>$id,'user_id'=>$r->user()->id])->delete();return response()->json(['success'=>true,'message'=>'Sesión revocada']);}
    public function sendVerification(Request $r){$u=$r->user();if($u->email_verified_at)return response()->json(['success'=>true,'message'=>'El correo ya está verificado']);$token=Str::random(64);DB::table('email_verification_tokens')->where('user_id',$u->id)->delete();DB::table('email_verification_tokens')->insert(['user_id'=>$u->id,'token_hash'=>hash('sha256',$token),'expires_at'=>now()->addHour(),'created_at'=>now(),'updated_at'=>now()]);$url=rtrim(config('app.frontend_url'),'/')."/verify-email?token={$token}";app(\App\Services\CompanyMailer::class)->send(PlatformAccess::allows($u)?null:$u->company_id,$u->email,'Verificar correo Deurstore',"Confirma tu correo de Deurstore abriendo este enlace (vence en 1 hora):\n{$url}");return response()->json(['success'=>true,'message'=>'Enlace de verificación enviado']);}
    public function verifyEmail(Request $r){$token=$r->validate(['token'=>'required'])['token'];$row=DB::table('email_verification_tokens')->where('token_hash',hash('sha256',$token))->where('expires_at','>',now())->first();abort_unless($row,422,'Enlace inválido o vencido');User::where('id',$row->user_id)->update(['email_verified_at'=>now()]);DB::table('email_verification_tokens')->where('user_id',$row->user_id)->delete();return response()->json(['success'=>true,'message'=>'Correo verificado']);}
}
