<?php

namespace App\Http\Controllers;

use App\Services\CompanyProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Sucursales y almacenes de la empresa del usuario. Todo almacén es de la empresa; puede ser
 * exclusivo de una sucursal o compartido entre varias. Cada sucursal vende desde el almacén que
 * elija entre los que tiene a su alcance.
 */
class LocationController extends Controller
{
    // --- Sucursales -------------------------------------------------------------------

    public function branches(Request $r)
    {
        $c = $r->user()->company_id;
        $rows = DB::table('branches')->where('company_id', $c)->orderBy('id')->get();
        $users = DB::table('users')->where(['company_id' => $c, 'status' => 'active'])->selectRaw('branch_id, COUNT(*) total')->groupBy('branch_id')->pluck('total', 'branch_id');
        foreach ($rows as $row) {
            // Almacenes activos a su alcance (exclusivos y compartidos), para elegir el de venta.
            $row->warehouses = $this->branchWarehousesQuery($c, $row->id)->where('w.status', 'active')
                ->select('w.id', 'w.code', 'w.name', DB::raw('CASE WHEN w.branch_id IS NULL THEN 1 ELSE 0 END as shared'))->orderBy('w.name')->get();
            $row->warehouses_count = $row->warehouses->count();
            $row->default_warehouse_name = $row->warehouses->firstWhere('id', $row->default_warehouse_id)?->name;
            $row->users_count = (int) ($users[$row->id] ?? 0);
        }
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function storeBranch(Request $r, CompanyProvisioner $provisioner)
    {
        $c = $r->user()->company_id;
        $d = $this->validateBranch($r, $c);
        unset($d['default_warehouse_id']);
        return DB::transaction(function () use ($r, $c, $d, $provisioner) {
            $id = DB::table('branches')->insertGetId($d + ['company_id' => $c, 'status' => $d['status'] ?? 'active', 'created_at' => now(), 'updated_at' => now()]);
            $provisioner->provisionBranch($c, $id, $d['code'], $d['name']);
            $this->recordAudit($r, 'created', 'branches', $id, $d);
            return response()->json(['success' => true, 'message' => 'Sucursal creada con su almacén, caja y series', 'data' => ['id' => $id]], 201);
        });
    }

    public function updateBranch(Request $r, int $id)
    {
        $c = $r->user()->company_id;
        $branch = DB::table('branches')->where(['id' => $id, 'company_id' => $c])->firstOrFail();
        $d = $this->validateBranch($r, $c, $id);
        $deactivating = ($d['status'] ?? 'active') === 'inactive' && $branch->status === 'active';
        $reactivating = ($d['status'] ?? 'active') === 'active' && $branch->status === 'inactive';
        if (array_key_exists('default_warehouse_id', $d) && $d['default_warehouse_id']) {
            $warehouse = $this->branchWarehousesQuery($c, $id)->where('w.id', $d['default_warehouse_id'])->select('w.*')->first();
            // Un almacén exclusivo inactivo vale si la sucursal se está reactivando: vuelve con ella.
            $usable = $warehouse && ($warehouse->status === 'active' || ($reactivating && (int) $warehouse->branch_id === $id));
            abort_unless($usable, 422, 'El almacén de venta debe ser uno activo al que la sucursal tenga acceso.');
        }
        if ($deactivating) $this->assertCanDeactivateBranch($c, $id);
        DB::transaction(function () use ($id, $d, $deactivating, $reactivating, $branch) {
            DB::table('branches')->where('id', $id)->update($d + ['updated_at' => now()]);
            if ($deactivating) $this->setOwnWarehouses($id, 'inactive');
            // Al reactivarla vuelve a operar con su almacén de venta exclusivo.
            $sales = $d['default_warehouse_id'] ?? $branch->default_warehouse_id;
            if ($reactivating && $sales) DB::table('warehouses')->where(['id' => $sales, 'branch_id' => $id])->update(['status' => 'active', 'updated_at' => now()]);
        });
        $this->recordAudit($r, 'updated', 'branches', $id, $d);
        return response()->json(['success' => true, 'message' => 'Sucursal actualizada']);
    }

    public function destroyBranch(Request $r, int $id)
    {
        $c = $r->user()->company_id;
        DB::table('branches')->where(['id' => $id, 'company_id' => $c])->firstOrFail();
        $this->assertCanDeactivateBranch($c, $id);
        DB::transaction(function () use ($id) {
            DB::table('branches')->where('id', $id)->update(['status' => 'inactive', 'updated_at' => now()]);
            $this->setOwnWarehouses($id, 'inactive');
        });
        $this->recordAudit($r, 'deleted', 'branches', $id);
        return response()->json(['success' => true, 'message' => 'Sucursal desactivada']);
    }

    private function validateBranch(Request $r, int $c, ?int $id = null): array
    {
        $r->merge(['code' => mb_strtoupper(trim((string) $r->code))]);
        return $r->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('branches', 'code')->where('company_id', $c)->ignore($id)],
            'name' => 'required|string|max:120', 'address' => 'nullable|string|max:255', 'phone' => 'nullable|string|max:30',
            'status' => 'nullable|in:active,inactive', 'default_warehouse_id' => 'sometimes|nullable|integer',
        ], ['code.unique' => 'Ya existe una sucursal con ese código.']);
    }

    /** Una sucursal con gente trabajando, caja abierta o stock propio no puede quedar inactiva. */
    private function assertCanDeactivateBranch(int $c, int $id): void
    {
        abort_unless(DB::table('branches')->where(['company_id' => $c, 'status' => 'active'])->where('id', '!=', $id)->exists(), 422, 'Es la única sucursal activa de la empresa.');
        $users = DB::table('users')->where(['company_id' => $c, 'branch_id' => $id, 'status' => 'active'])->count();
        abort_if($users > 0, 422, "Tiene {$users} usuario(s) asignado(s). Cámbialos de sucursal antes de desactivarla.");
        abort_if(DB::table('cash_sessions')->where(['company_id' => $c, 'branch_id' => $id, 'status' => 'open'])->exists(), 422, 'Tiene una caja abierta. Ciérrala antes de desactivar la sucursal.');
        // Solo cuenta el stock de sus almacenes exclusivos: los compartidos siguen operando para las demás.
        abort_if(DB::table('stocks as s')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')->where(['w.company_id' => $c, 'w.branch_id' => $id])->where('s.quantity', '>', 0)->exists(), 422, 'Sus almacenes todavía tienen stock. Transfiérelo antes de desactivarla.');
    }

    /** Los almacenes exclusivos siguen el estado de su sucursal; los compartidos no se tocan. */
    private function setOwnWarehouses(int $branch, string $status): void
    {
        DB::table('warehouses')->where('branch_id', $branch)->update(['status' => $status, 'updated_at' => now()]);
    }

    // --- Almacenes --------------------------------------------------------------------

    public function warehouses(Request $r)
    {
        $c = $r->user()->company_id;
        $branchNames = DB::table('branches')->where('company_id', $c)->pluck('name', 'id');
        $access = DB::table('branch_warehouse')->where('company_id', $c)->get()->groupBy('warehouse_id');
        $defaults = DB::table('branches')->where('company_id', $c)->whereNotNull('default_warehouse_id')->get()->groupBy('default_warehouse_id');
        $rows = DB::table('warehouses')->where('company_id', $c)->orderByRaw('CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END')->orderBy('branch_id')->orderBy('id')->get();
        foreach ($rows as $w) {
            $w->scope = $w->branch_id ? 'branch' : 'shared';
            $w->branch_ids = $w->branch_id ? [(int) $w->branch_id] : $access->get($w->id, collect())->pluck('branch_id')->map(fn ($id) => (int) $id)->values()->all();
            $w->branch_names = array_map(fn ($id) => $branchNames[$id] ?? '—', $w->branch_ids);
            $w->branch_name = implode(', ', $w->branch_names);
            $w->default_for = $defaults->get($w->id, collect())->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            $w->default_for_names = array_map(fn ($id) => $branchNames[$id] ?? '—', $w->default_for);
        }
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function storeWarehouse(Request $r)
    {
        $c = $r->user()->company_id;
        $d = $this->validateWarehouse($r, $c);
        return DB::transaction(function () use ($r, $c, $d) {
            $id = DB::table('warehouses')->insertGetId(['company_id' => $c, 'branch_id' => $d['scope'] === 'branch' ? $d['branch_id'] : null, 'code' => $d['code'], 'name' => $d['name'], 'status' => $d['status'] ?? 'active', 'created_at' => now(), 'updated_at' => now()]);
            $this->syncAccess($c, $id, $d);
            $this->applyDefaults($c, $id, $d, true);
            $this->recordAudit($r, 'created', 'warehouses', $id, $d);
            return response()->json(['success' => true, 'message' => 'Almacén creado', 'data' => ['id' => $id]], 201);
        });
    }

    public function updateWarehouse(Request $r, int $id)
    {
        $c = $r->user()->company_id;
        $warehouse = DB::table('warehouses')->where(['id' => $id, 'company_id' => $c])->firstOrFail();
        $d = $this->validateWarehouse($r, $c, $id);
        $allowed = $d['scope'] === 'branch' ? [(int) $d['branch_id']] : array_map('intval', $d['branch_ids']);
        // Ninguna sucursal puede perder el acceso al almacén desde el que vende.
        $losing = DB::table('branches')->where(['company_id' => $c, 'default_warehouse_id' => $id])->whereNotIn('id', $allowed)->pluck('name');
        abort_if($losing->isNotEmpty(), 422, 'Es el almacén de venta de '.$losing->implode(', ').'. Elige otro almacén de venta para esa sucursal antes de quitarle el acceso.');
        if (($d['status'] ?? 'active') === 'inactive' && $warehouse->status === 'active') $this->assertCanDeactivateWarehouse($warehouse);
        return DB::transaction(function () use ($r, $c, $id, $d) {
            DB::table('warehouses')->where('id', $id)->update(['branch_id' => $d['scope'] === 'branch' ? $d['branch_id'] : null, 'code' => $d['code'], 'name' => $d['name'], 'status' => $d['status'] ?? 'active', 'updated_at' => now()]);
            $this->syncAccess($c, $id, $d);
            $this->applyDefaults($c, $id, $d, false);
            $this->recordAudit($r, 'updated', 'warehouses', $id, $d);
            return response()->json(['success' => true, 'message' => 'Almacén actualizado']);
        });
    }

    public function destroyWarehouse(Request $r, int $id)
    {
        $warehouse = DB::table('warehouses')->where(['id' => $id, 'company_id' => $r->user()->company_id])->firstOrFail();
        $this->assertCanDeactivateWarehouse($warehouse);
        DB::table('warehouses')->where('id', $id)->update(['status' => 'inactive', 'updated_at' => now()]);
        $this->recordAudit($r, 'deleted', 'warehouses', $id);
        return response()->json(['success' => true, 'message' => 'Almacén desactivado']);
    }

    private function validateWarehouse(Request $r, int $c, ?int $id = null): array
    {
        $r->merge(['code' => mb_strtoupper(trim((string) $r->code)), 'scope' => $r->input('scope', $r->filled('branch_ids') && !$r->filled('branch_id') ? 'shared' : 'branch')]);
        $activeBranch = Rule::exists('branches', 'id')->where(fn ($q) => $q->where(['company_id' => $c, 'status' => 'active']));
        return $r->validate([
            'scope' => 'required|in:branch,shared',
            'branch_id' => ['required_if:scope,branch', 'nullable', 'integer', $activeBranch],
            'branch_ids' => 'required_if:scope,shared|array|min:2',
            'branch_ids.*' => ['integer', 'distinct', $activeBranch],
            'default_for' => 'nullable|array', 'default_for.*' => ['integer', $activeBranch],
            'code' => ['required', 'string', 'max:20', Rule::unique('warehouses', 'code')->where('company_id', $c)->ignore($id)],
            'name' => 'required|string|max:120', 'status' => 'nullable|in:active,inactive',
        ], [
            'branch_id.exists' => 'Elige una sucursal activa de tu empresa.', 'branch_ids.*.exists' => 'Solo puedes compartirlo con sucursales activas de tu empresa.',
            'branch_ids.min' => 'Un almacén compartido debe tener al menos dos sucursales.', 'code.unique' => 'Ya existe un almacén con ese código en la empresa.',
        ]);
    }

    /** Acceso de las sucursales a un almacén compartido. Uno exclusivo no lleva lista. */
    private function syncAccess(int $c, int $warehouse, array $d): void
    {
        DB::table('branch_warehouse')->where('warehouse_id', $warehouse)->delete();
        if ($d['scope'] !== 'shared') return;
        DB::table('branch_warehouse')->insert(array_map(fn ($b) => ['company_id' => $c, 'branch_id' => (int) $b, 'warehouse_id' => $warehouse, 'created_at' => now(), 'updated_at' => now()], array_unique($d['branch_ids'])));
    }

    /**
     * Sucursales que pasan a vender desde este almacén. Al crear uno exclusivo en una sucursal sin
     * almacén de venta, lo adopta automáticamente.
     */
    private function applyDefaults(int $c, int $warehouse, array $d, bool $creating): void
    {
        $allowed = $d['scope'] === 'branch' ? [(int) $d['branch_id']] : array_map('intval', $d['branch_ids']);
        $targets = array_map('intval', $d['default_for'] ?? []);
        abort_if(array_diff($targets, $allowed), 422, 'Solo las sucursales con acceso al almacén pueden usarlo como almacén de venta.');
        if ($creating && $d['scope'] === 'branch' && !DB::table('branches')->where('id', $d['branch_id'])->value('default_warehouse_id')) $targets[] = (int) $d['branch_id'];
        if (($d['status'] ?? 'active') !== 'active') return;
        if ($targets) DB::table('branches')->where('company_id', $c)->whereIn('id', array_unique($targets))->update(['default_warehouse_id' => $warehouse, 'updated_at' => now()]);
    }

    private function hasStock(int $warehouse): bool
    {
        return DB::table('stocks')->where('warehouse_id', $warehouse)->where('quantity', '>', 0)->exists();
    }

    private function assertCanDeactivateWarehouse(object $warehouse): void
    {
        $users = DB::table('branches')->where('default_warehouse_id', $warehouse->id)->where('status', 'active')->pluck('name');
        abort_if($users->isNotEmpty(), 422, 'Es el almacén de venta de '.$users->implode(', ').'. Elige otro almacén de venta antes de desactivarlo.');
        abort_if($this->hasStock($warehouse->id), 422, 'El almacén todavía tiene stock. Transfiérelo o ajústalo antes de desactivarlo.');
    }
}
