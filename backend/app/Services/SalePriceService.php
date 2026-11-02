<?php
namespace App\Services;
use App\Models\User;
use App\Support\PlatformAccess;
use Illuminate\Support\Facades\DB;
final class SalePriceService{
 public function resolve(User$user,object$product,?float$requested,?string$reason):array{$catalog=(float)$product->sale_price;$requested=$requested??$catalog;if(abs($requested-$catalog)<.00001)return['price'=>$catalog,'overridden'=>false,'catalog_price'=>$catalog];$allowed=PlatformAccess::allows($user)||DB::table('role_user as ru')->join('permission_role as pr','pr.role_id','=','ru.role_id')->join('permissions as p','p.id','=','pr.permission_id')->where('ru.user_id',$user->id)->where('p.slug','sales.override_price')->exists();// Sin permiso no se cambia el precio: se avisa en lugar de cobrar distinto a lo que ve la pantalla.
abort_unless($allowed,403,"No tienes permiso para cambiar el precio de {$product->name}.");abort_unless(trim((string)$reason)!=='',422,'Indica el motivo de la modificación de precio');abort_if($requested<(float)$product->minimum_price,422,"Precio menor al mínimo para {$product->name}");return['price'=>$requested,'overridden'=>true,'catalog_price'=>$catalog];}
}
