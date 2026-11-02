<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Unidades de medida estándar (debe coincidir con app/lib/units.ts). Cada empresa tiene una
 * fila por unidad en `units` y decide cuáles usa (status active/inactive); el código es fijo
 * porque las conversiones (g ↔ kg ↔ lb, ml ↔ L…) dependen de él.
 */
final class UnitCatalog
{
    /** código => [nombre, símbolo, familia] */
    public const UNITS = [
        'unit' => ['Unidad', 'und', 'count'],
        'pair' => ['Par', 'par', 'count'],
        'set' => ['Juego / kit', 'jgo', 'count'],
        'kg' => ['Kilogramo', 'kg', 'weight'],
        'g' => ['Gramo', 'g', 'weight'],
        't' => ['Tonelada', 't', 'weight'],
        'lb' => ['Libra', 'lb', 'weight'],
        'l' => ['Litro', 'L', 'volume'],
        'ml' => ['Mililitro', 'ml', 'volume'],
        'gal' => ['Galón', 'gal', 'volume'],
        'm3' => ['Metro cúbico', 'm³', 'volume'],
        'm' => ['Metro', 'm', 'length'],
        'cm' => ['Centímetro', 'cm', 'length'],
        'mm' => ['Milímetro', 'mm', 'length'],
        'm2' => ['Metro cuadrado', 'm²', 'area'],
    ];

    /** Activas al crear el catálogo de una empresa (además de las que ya usen sus productos). */
    public const DEFAULT_ACTIVE = ['unit', 'kg', 'l'];

    public static function codes(): array
    {
        return array_keys(self::UNITS);
    }

    /** Solo las unidades de conteo se venden en cantidades enteras. */
    public static function allowsDecimals(string $code): bool
    {
        return isset(self::UNITS[$code]) && self::UNITS[$code][2] !== 'count';
    }

    /**
     * Crea las unidades estándar que falten para la empresa. Las filas antiguas creadas a mano
     * se enlazan por símbolo («un» → unidad, «kg» → kilogramo) para no duplicarlas.
     */
    public function ensureFor(int $company): void
    {
        $rows = DB::table('units')->where('company_id', $company)->get();
        $inUse = DB::table('products')->where('company_id', $company)->distinct()->pluck('unit_type')->all();
        $aliases = ['un' => 'unit', 'und' => 'unit', 'pza' => 'unit', 'l' => 'l', 'lt' => 'l', 'm²' => 'm2', 'm³' => 'm3'];

        foreach ($rows->whereNull('code') as $row) {
            $symbol = mb_strtolower(trim($row->symbol));
            $code = $aliases[$symbol] ?? collect(self::UNITS)->search(fn ($u) => mb_strtolower($u[1]) === $symbol);
            if ($code && !$rows->firstWhere('code', $code)) {
                DB::table('units')->where('id', $row->id)->update(['code' => $code, 'symbol' => self::UNITS[$code][1], 'measure_type' => self::UNITS[$code][2], 'allows_decimals' => self::allowsDecimals($code), 'updated_at' => now()]);
                $row->code = $code;
            }
        }

        $existing = $rows->pluck('code')->filter()->all();
        foreach (self::UNITS as $code => [$name, $symbol, $family]) {
            if (in_array($code, $existing, true)) continue;
            DB::table('units')->insert([
                'company_id' => $company, 'code' => $code, 'name' => $name, 'symbol' => $symbol, 'measure_type' => $family,
                'allows_decimals' => self::allowsDecimals($code),
                'status' => in_array($code, self::DEFAULT_ACTIVE, true) || in_array($code, $inUse, true) ? 'active' : 'inactive',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Códigos activos de la empresa. */
    public function activeCodes(int $company): array
    {
        $this->ensureFor($company);
        return DB::table('units')->where(['company_id' => $company, 'status' => 'active'])->whereNotNull('code')->pluck('code')->all();
    }
}
