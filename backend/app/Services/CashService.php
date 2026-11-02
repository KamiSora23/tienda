<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Turno de caja del usuario y su efectivo esperado: una sola fórmula para todo el sistema. */
final class CashService
{
    /** Entradas y salidas de efectivo según el tipo de movimiento. */
    public const IN = ['sale', 'income'];
    public const OUT = ['expense', 'withdrawal', 'deposit', 'refund'];

    /** Caja abierta con la que trabaja el usuario (la elegida en su contexto o la que abrió). */
    public function current(Request $r, bool $lock = false): ?object
    {
        $u = $r->user();
        $q = DB::table('cash_sessions')->where(['company_id' => $u->company_id, 'branch_id' => $u->branch_id, 'status' => 'open']);
        $u->cash_session_id ? $q->where('id', $u->cash_session_id) : $q->where('user_id', $u->id);
        if ($lock) $q->lockForUpdate();
        return $q->latest()->first();
    }

    /** Apertura + ventas en efectivo e ingresos − gastos, retiros, depósitos y devoluciones. */
    public function expected(int $session): float
    {
        $opening = (float) DB::table('cash_sessions')->where('id', $session)->value('opening_amount');
        $in = (float) DB::table('cash_movements')->where('cash_session_id', $session)->whereIn('type', self::IN)->sum('amount');
        $out = (float) DB::table('cash_movements')->where('cash_session_id', $session)->whereIn('type', self::OUT)->sum('amount');
        return round($opening + $in - $out, 2);
    }
}
