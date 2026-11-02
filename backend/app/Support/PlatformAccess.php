<?php
namespace App\Support;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Superadministrador de plataforma. Opera en solo lectura: para modificar datos de una empresa
 * activa la edición en su sesión, con un motivo y por un tiempo limitado (queda en auditoría).
 */
final class PlatformAccess{
    public static function allows(User $user):bool{return $user->role==='platform_admin';}
    public static function authorize(User $user):void{abort_unless(self::allows($user),403,'Acceso exclusivo del superadministrador de plataforma');}

    /** Sesión actual con edición vigente, o null. */
    public static function writeMode(Request $r):?object{
        $id=$r->attributes->get('session_id');
        return $id?DB::table('user_sessions')->where('id',$id)->whereNotNull('platform_write_until')->where('platform_write_until','>',now())->select('platform_write_until','platform_write_reason')->first():null;
    }
}
