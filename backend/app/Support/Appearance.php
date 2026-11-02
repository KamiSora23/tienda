<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Colores de la aplicación de cada empresa (grupo "appearance" de settings). */
class Appearance
{
    public const DEFAULTS = ['mode' => 'light', 'primary' => '#168555', 'sidebar' => '#12251d', 'background' => '#f5f7f5', 'panel' => '#ffffff'];

    public static function rules(): array
    {
        $hex = ['sometimes', 'required', 'regex:/^#[0-9a-fA-F]{6}$/'];

        return [
            'settings.appearance.mode' => ['sometimes', 'required', 'in:light,dark'],
            'settings.appearance.primary' => $hex,
            'settings.appearance.sidebar' => $hex,
            'settings.appearance.background' => $hex,
            'settings.appearance.panel' => $hex,
        ];
    }

    public static function for(int $companyId): array
    {
        $rows = DB::table('settings')->where('company_id', $companyId)->whereIn('group', ['appearance', 'system'])->get();
        $saved = $rows->where('group', 'appearance')->pluck('value', 'key')->only(array_keys(self::DEFAULTS))->all();
        // Antes solo existía system.theme (verde u oscuro).
        if (! isset($saved['mode']) && $rows->firstWhere('key', 'theme')?->value === 'dark') {
            $saved['mode'] = 'dark';
        }

        return array_merge(self::DEFAULTS, $saved);
    }
}
