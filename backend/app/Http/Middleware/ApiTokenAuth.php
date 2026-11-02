<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenAuth {
    public function handle(Request $request, Closure $next): Response {
        $token = $request->bearerToken();
        $hash=$token?hash('sha256',$token):null;
        $session=$hash?DB::table('user_sessions')->where('token_hash',$hash)->where(fn($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()))->first():null;
        $user = $session ? User::where('id',$session->user_id)->where('status','active')->first() : ($hash?User::where('api_token',$hash)->where('status','active')->first():null);
        if (!$user) return response()->json(['success'=>false,'message'=>'No autenticado'], 401);
        if($session){DB::table('user_sessions')->where('id',$session->id)->update(['last_activity_at'=>now(),'updated_at'=>now()]);$request->attributes->set('session_id',$session->id);}
        auth()->setUser($user);
        return $next($request);
    }
}
