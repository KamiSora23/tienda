<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PermissionGate {
    public function handle(Request $request, Closure $next): Response {
        $path = trim($request->path(), '/');
        $relative = preg_replace('#^api/v1/#', '', $path);
        if (in_array($relative, ['logout','me','context','two-factor','context/options','sessions','email-verification/send','platform/write-mode'], true) || str_starts_with($relative,'sessions/')) return $next($request);
        // El superadministrador tiene acceso completo en la empresa en la que esté trabajando.
        if (\App\Support\PlatformAccess::allows($request->user())) {
            // Solo lectura salvo edición activada, excepto sus tareas propias de plataforma (empresas, respaldos, mantenimiento).
            $platformTask = preg_match('#^(admin/companies|backups|backup-schedules|maintenance|notifications|platform)(/|$)#', $relative);
            if (!in_array($request->method(), ['GET','HEAD'], true) && !$platformTask && !\App\Support\PlatformAccess::writeMode($request))
                return response()->json(['success'=>false,'code'=>'platform_read_only','message'=>'Modo solo lectura del superadministrador: activa la edición indicando el motivo para hacer cambios en esta empresa.'],403);
            return $next($request);
        }
        $parts = explode('/', $relative);
        $resource = $parts[0] === 'admin' ? ($parts[1] ?? 'settings') : $parts[0];
        if($resource==='crm')$resource=$parts[1]??'customers';
        $resource = [
            'dashboard'=>'reports','dashboards'=>'reports','suspended-sales'=>'sales','inventory'=>'inventory',
            'expenses'=>'expenses','customers'=>'customers','suppliers'=>'suppliers',
            'files'=>'files','backups'=>'backups','maintenance'=>'maintenance',
            'permissions'=>'roles','user-options'=>'users','global-search'=>'reports','web-orders'=>'orders','ai-logs'=>'ai','histories'=>'audit','backup-schedules'=>'backups','expense-categories'=>'expenses','recurring-expenses'=>'expenses','meters'=>'expenses','configuration'=>'settings','portal-content'=>'portal','mail-settings'=>'settings',
        ][$resource] ?? $resource;
        $action = match ($request->method()) {
            'GET', 'HEAD' => str_contains($relative, '/export') ? 'export' : 'view',
            'POST' => preg_match('#/(cancel|return|close|optimize|recover|permissions|toggle|release|payments)$#', $relative) ? 'update' : 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'view',
        };
        $allowed = DB::table('role_user as ru')
            ->join('permission_role as pr','pr.role_id','=','ru.role_id')
            ->join('permissions as p','p.id','=','pr.permission_id')
            ->where('ru.user_id',$request->user()->id)
            ->where('p.slug',"{$resource}.{$action}")->exists();
        if (!$allowed) return response()->json(['success'=>false,'message'=>"No tienes permiso para {$action} en {$resource}"],403);
        return $next($request);
    }
}
