<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Panel de plataforma: vista global del superadministrador, sin entrar a ninguna empresa. */
class PlatformController extends Controller
{
    /** Todas las empresas con sus cifras principales de los últimos 30 días. */
    public function overview()
    {
        $since = now()->subDays(30);
        $count = fn (string $table, array $where = []) => DB::table($table)->where($where)->selectRaw('company_id, COUNT(*) total')->groupBy('company_id')->pluck('total', 'company_id');
        $sales = DB::table('sales')->where('status', '!=', 'cancelled')->where('created_at', '>=', $since)->selectRaw('company_id, COUNT(*) n, SUM(total) amount, MAX(created_at) last_at')->groupBy('company_id')->get()->keyBy('company_id');
        $lastSale = DB::table('sales')->selectRaw('company_id, MAX(created_at) last_at')->groupBy('company_id')->pluck('last_at', 'company_id');
        $users = DB::table('users')->where('role', '!=', 'platform_admin')->selectRaw('company_id, COUNT(*) total, MAX(last_login_at) last_login')->groupBy('company_id')->get()->keyBy('company_id');
        $branches = $count('branches', ['status' => 'active']);
        $products = $count('products', ['status' => 'active']);
        $orders = $count('web_orders', ['status' => 'pending']);
        $mail = DB::table('company_mail_settings')->where('enabled', true)->pluck('company_id')->flip();
        $mainBranch = DB::table('branches')->where('status', 'active')->orderBy('id')->get()->groupBy('company_id')->map(fn ($b) => $b->first()->id);

        $companies = DB::table('companies')->orderBy('trade_name')->get(['id', 'trade_name', 'business_name', 'ruc', 'status', 'created_at'])
            ->map(function ($c) use ($sales, $lastSale, $users, $branches, $products, $orders, $mail, $mainBranch) {
                $s = $sales->get($c->id);
                $c->branches = (int) ($branches[$c->id] ?? 0);
                $c->users = (int) ($users->get($c->id)->total ?? 0);
                $c->products = (int) ($products[$c->id] ?? 0);
                $c->sales_30d = (int) ($s->n ?? 0);
                $c->amount_30d = round((float) ($s->amount ?? 0), 2);
                $c->last_sale_at = $lastSale[$c->id] ?? null;
                $c->last_login_at = $users->get($c->id)->last_login ?? null;
                $c->pending_orders = (int) ($orders[$c->id] ?? 0);
                $c->own_mail = $mail->has($c->id);
                $c->main_branch_id = $mainBranch[$c->id] ?? null;

                return $c;
            });

        return response()->json(['success' => true, 'data' => [
            'companies' => $companies,
            'totals' => ['companies' => $companies->count(), 'active' => $companies->where('status', 'active')->count(), 'users' => $companies->sum('users'), 'sales_30d' => $companies->sum('sales_30d'), 'amount_30d' => round($companies->sum('amount_30d'), 2)],
        ]]);
    }

    /** Lo que hicieron los superadministradores en cada empresa (inicios de sesión, ediciones, motivos). */
    public function activity(Request $r)
    {
        $rows = DB::table('audit_events as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->leftJoin('companies as c', 'c.id', '=', 'a.company_id')
            ->where('a.by_platform', true)->when($r->company_id, fn ($q) => $q->where('a.company_id', $r->company_id))
            ->select('a.id', 'a.created_at', 'a.event', 'a.entity', 'a.entity_id', 'a.after', 'a.ip', 'u.name as user_name', 'u.email as user_email', 'c.trade_name as company')
            ->orderByDesc('a.created_at')->orderByDesc('a.id')->limit(200)->get()
            ->map(function ($x) { $after = json_decode($x->after ?? 'null', true); $x->reason = $after['reason'] ?? null; unset($x->after); return $x; });

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /** Cuentas de superadministrador (se crean solo por consola). */
    public function admins()
    {
        return response()->json(['success' => true, 'data' => DB::table('users as u')->leftJoin('companies as c', 'c.id', '=', 'u.company_id')->where('u.role', 'platform_admin')
            ->select('u.id', 'u.name', 'u.email', 'u.status', 'u.last_login_at', 'c.trade_name as current_company', DB::raw('(select count(*) from user_sessions s where s.user_id=u.id and (s.expires_at is null or s.expires_at > CURRENT_TIMESTAMP)) as sessions'))
            ->orderBy('u.name')->get()]);
    }
}
