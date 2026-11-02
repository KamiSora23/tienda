<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('user:promote-platform {email}', function (string $email) {
    $updated=DB::table('users')->where('email',$email)->update(['role'=>'platform_admin','updated_at'=>now()]);
    if(!$updated){$this->error('No existe un usuario con ese correo.');return 1;}
    $this->info('Usuario promovido a superadministrador de plataforma.');
    return 0;
})->purpose('Promueve explícitamente un usuario existente para administrar respaldos globales');

// Código de verificación nuevo para un usuario (por ejemplo, si el correo no llega). Requiere acceso al servidor.
Artisan::command('user:login-code {email}', function (string $email) {
    $user=\App\Models\User::where('email',$email)->first();
    if(!$user){$this->error('No existe un usuario con ese correo.');return 1;}
    $code=(string)random_int(100000,999999);
    $user->update(['two_factor_code'=>hash('sha256',$code),'two_factor_expires_at'=>now()->addMinutes(10)]);
    DB::table('audit_events')->insert(['uuid'=>\Illuminate\Support\Str::uuid(),'company_id'=>$user->company_id,'user_id'=>$user->id,'by_platform'=>$user->role==='platform_admin','event'=>'auth.code_issued_console','entity'=>'auth','entity_id'=>$user->id,'created_at'=>now(),'updated_at'=>now()]);
    $this->info("Código para {$email}: {$code} (vence en 10 minutos). Primero ingresa correo y contraseña; luego escribe este código.");
    return 0;
})->purpose('Genera un código de verificación en dos pasos cuando el correo no está disponible');

// Prueba de correo: envía un mensaje y muestra el error exacto si falla.
Artisan::command('mail:test {to}', function (string $to) {
    $this->line('Enviando con '.config('mail.default').' ('.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port').') desde '.config('mail.from.address').'…');
    if(config('mail.default')==='log')$this->warn('MAIL_MAILER=log: el mensaje se guardará en storage/logs/laravel.log, no se enviará.');
    try{Mail::raw('Correo de prueba de Deurstore. Si lo recibes, el envío de códigos de verificación funciona.',fn($m)=>$m->to($to)->subject('Prueba de correo Deurstore'));}
    catch(\Throwable $e){$this->error('No se pudo enviar: '.$e->getMessage());if(str_contains($e->getMessage(),'535')||str_contains(strtolower($e->getMessage()),'username and password'))$this->line('Gmail rechazó el usuario o la contraseña: usa una contraseña de aplicación (no tu contraseña normal) y revisa que no tenga espacios.');return 1;}
    $this->info(config('mail.default')==='log'?"Guardado en el log (no se envió). Cambia MAIL_MAILER a smtp para enviarlo de verdad.":"Enviado a {$to}. Revisa la bandeja de entrada (y la carpeta de spam).");
    return 0;
})->purpose('Envía un correo de prueba con la configuración actual');

// Instalación en un servidor nuevo (sin datos de demostración): permisos, primera empresa con su
// administrador y el superadministrador. Pregunta los datos o los toma de las variables INSTALL_*.
Artisan::command('deurstore:install {--force : Instalar aunque ya existan empresas}', function () {
    \App\Support\PermissionCatalog::ensure();
    if(DB::table('companies')->exists()&&!$this->option('force')){$this->info('Ya hay empresas registradas: solo se verificaron los permisos.');return 0;}
    $ask=function(string $env,string $question,?string $default=null,bool $secret=false){$value=env($env);if($value!==null&&$value!=='')return $value;return $secret?$this->secret($question):$this->ask($question,$default);};
    $data=[
        'business_name'=>$ask('INSTALL_COMPANY_NAME','Razón social de la empresa'),
        'trade_name'=>$ask('INSTALL_COMPANY_TRADE_NAME','Nombre comercial'),
        'ruc'=>$ask('INSTALL_COMPANY_RUC','RUC (11 dígitos)'),
        'admin_name'=>$ask('INSTALL_ADMIN_NAME','Nombre del administrador de la empresa'),
        'admin_email'=>$ask('INSTALL_ADMIN_EMAIL','Correo del administrador'),
        'admin_password'=>$ask('INSTALL_ADMIN_PASSWORD','Contraseña del administrador (mínimo 10 caracteres)',null,true),
        'platform_email'=>$ask('INSTALL_PLATFORM_EMAIL','Correo del superadministrador de la plataforma'),
        'platform_password'=>$ask('INSTALL_PLATFORM_PASSWORD','Contraseña del superadministrador (mínimo 12 caracteres)',null,true),
    ];
    $v=\Illuminate\Support\Facades\Validator::make($data,['business_name'=>'required|max:200','trade_name'=>'required|max:120','ruc'=>'required|digits:11|unique:companies,ruc','admin_name'=>'required|max:120','admin_email'=>'required|email|unique:users,email','admin_password'=>'required|min:10','platform_email'=>'required|email|different:admin_email|unique:users,email','platform_password'=>'required|min:12']);
    if($v->fails()){foreach($v->errors()->all() as $e)$this->error($e);return 1;}
    DB::transaction(function()use($data){
        $now=now();
        $company=DB::table('companies')->insertGetId(['uuid'=>\Illuminate\Support\Str::uuid(),'business_name'=>$data['business_name'],'trade_name'=>$data['trade_name'],'ruc'=>$data['ruc'],'currency'=>'PEN','timezone'=>'America/Lima','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
        $provisioner=new \App\Services\CompanyProvisioner();
        $branch=$provisioner->provisionCompany($company);
        $admin=$provisioner->firstAdmin($company,$branch,$data['admin_name'],$data['admin_email'],$data['admin_password']);
        $role=DB::table('role_user')->where('user_id',$admin)->value('role_id');
        $super=\App\Models\User::create(['name'=>'Superadministrador','email'=>$data['platform_email'],'password'=>$data['platform_password'],'company_id'=>$company,'branch_id'=>$branch,'role'=>'platform_admin','status'=>'active','email_verified_at'=>$now]);
        DB::table('role_user')->insert(['role_id'=>$role,'user_id'=>$super->id]);
    });
    $this->info("Listo. Empresa «{$data['trade_name']}» creada.");
    $this->line("Administrador: {$data['admin_email']} · Superadministrador: {$data['platform_email']} (ingresa con verificación por correo).");
    return 0;
})->purpose('Prepara un servidor nuevo: permisos, primera empresa, su administrador y el superadministrador');

Artisan::command('deurstore:scheduled-backups', function () {
    foreach(DB::table('backup_schedules')->where('active',1)->where('next_run_at','<=',now())->get() as $schedule){
        $filename='deurstore-auto-'.now()->format('Ymd-His').'.sql';$relative='backups/'.$filename;$full=Storage::path($relative);
        if(!is_dir(dirname($full)))mkdir(dirname($full),0775,true);
        $error=\App\Support\DatabaseDump::dump($full);$code=$error?1:0;
        DB::table('backup_records')->insert(['company_id'=>$schedule->company_id,'user_id'=>$schedule->user_id,'filename'=>$filename,'path'=>$relative,'size'=>file_exists($full)?filesize($full):0,'type'=>'automatic','status'=>$code===0?'completed':'failed','error'=>$error?mb_substr($error,0,500):null,'created_at'=>now(),'updated_at'=>now()]);
        $next=match($schedule->frequency){'daily'=>now()->addDay(),'weekly'=>now()->addWeek(),'monthly'=>now()->addMonth()};
        DB::table('backup_schedules')->where('id',$schedule->id)->update(['last_run_at'=>now(),'next_run_at'=>$next,'updated_at'=>now()]);
        $cutoff=now()->subDays($schedule->retention_days);
        foreach(DB::table('backup_records')->where(['company_id'=>$schedule->company_id,'type'=>'automatic'])->where('created_at','<',$cutoff)->get() as $old){if(Storage::exists($old->path))Storage::delete($old->path);DB::table('backup_records')->where('id',$old->id)->delete();}
    }
})->purpose('Crea y depura los respaldos automáticos configurados');

Schedule::command('deurstore:scheduled-backups')->everyMinute()->withoutOverlapping();

$scheduledPdf=function(string$title,array$rows):string{$lines=[$title,'Generado: '.now()->format('d/m/Y H:i')];if($rows){$lines[]=implode(' | ',array_keys($rows[0]));foreach(array_slice($rows,0,180)as$row)$lines[]=implode(' | ',array_map(fn($v)=>substr(preg_replace('/\s+/',' ',(string)$v),0,80),$row));}$content="BT /F1 8 Tf 30 810 Td 10 TL ";foreach($lines as$i=>$line){$safe=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],iconv('UTF-8','Windows-1252//TRANSLIT',$line));$content.=($i?'T* ':'')."({$safe}) Tj ";}$content.='ET';$objects=["1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj","2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj","3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >> endobj","4 0 obj << /Length ".strlen($content)." >> stream\n{$content}\nendstream endobj","5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj"];$pdf="%PDF-1.4\n";$offset=[];foreach($objects as$o){$offset[]=strlen($pdf);$pdf.=$o."\n";}$xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";foreach($offset as$off)$pdf.=sprintf("%010d 00000 n \n",$off);return$pdf."trailer << /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";};
Artisan::command('deurstore:scheduled-reports',function()use($scheduledPdf){
 foreach(DB::table('scheduled_reports')->where('active',1)->where('next_run_at','<=',now())->get()as$s){
  $table=match($s->report_type){'sales'=>'sales','purchases'=>'purchases','cash'=>'cash_sessions','expenses'=>'expenses','customers'=>'customers','suppliers'=>'suppliers','products','inventory'=>'products','promotions'=>'promotions',default=>null};
  if(!$table)continue;$rows=DB::table($table)->where('company_id',$s->company_id)->where('created_at','>=',now()->subMonth())->limit(5000)->get()->map(fn($x)=>(array)$x)->all();$headers=$rows?array_keys($rows[0]):[];$stream=fopen('php://temp','r+');fwrite($stream,"\xEF\xBB\xBF");if($headers){fputcsv($stream,$headers);foreach($rows as$row)fputcsv($stream,array_map(fn($v)=>is_scalar($v)?$v:json_encode($v),$row));}rewind($stream);$csv=stream_get_contents($stream);fclose($stream);$recipients=array_values(array_filter(array_map('trim',explode(',',$s->recipients??''))));if($recipients){$attachment=$s->format==='pdf'?$scheduledPdf($s->name,$rows):$csv;$extension=$s->format==='pdf'?'pdf':'csv';$mime=$s->format==='pdf'?'application/pdf':'text/csv';Mail::raw("Reporte automático {$s->name} generado por Deurstore.",function($m)use($recipients,$attachment,$extension,$mime,$s){$m->to($recipients)->subject("Deurstore · {$s->name}")->attachData($attachment,"{$s->report_type}-".now()->format('Ymd').".{$extension}",['mime'=>$mime]);});}$next=match($s->frequency){'daily'=>now()->addDay(),'weekly'=>now()->addWeek(),default=>now()->addMonth()};DB::table('scheduled_reports')->where('id',$s->id)->update(['next_run_at'=>$next,'updated_at'=>now()]);
 }
})->purpose('Genera y envía los reportes automáticos configurados');
Schedule::command('deurstore:scheduled-reports')->everyMinute()->withoutOverlapping();
