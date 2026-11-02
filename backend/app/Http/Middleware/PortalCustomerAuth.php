<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PortalCustomerAuth {
    public function handle(Request $request, Closure $next): Response {
        $token=$request->bearerToken();
        $customer=$token?DB::table('web_customers')->where('api_token',hash('sha256',$token))->where('status','active')->first():null;
        if(!$customer)return response()->json(['success'=>false,'message'=>'Sesión de cliente no válida'],401);
        $request->attributes->set('web_customer',$customer);
        return $next($request);
    }
}
