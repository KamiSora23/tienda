<?php
namespace App\Http\Middleware;
use App\Support\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class PlatformAdmin{public function handle(Request$request,Closure$next):Response{PlatformAccess::authorize($request->user());return$next($request);}}
