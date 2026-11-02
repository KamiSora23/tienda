<?php

namespace App\Http\Controllers;

use App\Services\UnitCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Catálogo de unidades de la empresa: las estándar, con cuáles usa la tienda. */
class UnitController extends Controller
{
    public function __construct(private UnitCatalog $catalog) {}

    public function index(Request $r)
    {
        $c = $r->user()->company_id;
        $this->catalog->ensureFor($c);
        $usage = DB::table('products')->where('company_id', $c)->selectRaw('unit_type, COUNT(*) total')->groupBy('unit_type')->pluck('total', 'unit_type');
        $order = array_flip(UnitCatalog::codes());
        $rows = DB::table('units')->where('company_id', $c)->whereNotNull('code')->get()
            ->sortBy(fn ($u) => $order[$u->code] ?? 99)->values()
            ->map(fn ($u) => [
                'code' => $u->code, 'name' => $u->name, 'symbol' => $u->symbol, 'family' => UnitCatalog::UNITS[$u->code][2],
                'allows_decimals' => UnitCatalog::allowsDecimals($u->code), 'status' => $u->status,
                'products' => (int) ($usage[$u->code] ?? 0),
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function update(Request $r, string $code)
    {
        $c = $r->user()->company_id;
        $d = $r->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        abort_unless(in_array($code, UnitCatalog::codes(), true), 404, 'Unidad no encontrada');
        $this->catalog->ensureFor($c);

        if ($d['status'] === 'inactive') {
            $others = DB::table('units')->where(['company_id' => $c, 'status' => 'active'])->where('code', '!=', $code)->whereNotNull('code')->exists();
            abort_unless($others, 422, 'Debe quedar al menos una unidad activa para registrar productos.');
        }
        DB::table('units')->where(['company_id' => $c, 'code' => $code])->update(['status' => $d['status'], 'updated_at' => now()]);
        $this->recordAudit($r, 'unit.status_changed', 'units', null, ['code' => $code, 'status' => $d['status']]);

        // Desactivar no afecta a los productos que ya la usan: solo deja de ofrecerse para productos nuevos.
        return response()->json(['success' => true, 'message' => $d['status'] === 'active' ? 'Unidad activada' : 'Unidad desactivada']);
    }
}
