<?php

namespace App\Support;

/** Validación de documentos de identidad peruanos para clientes y proveedores. */
final class DocumentRules
{
    /** Regla de Laravel que valida el número según el tipo de documento elegido. */
    public static function number(?string $type): array
    {
        return ['nullable', 'string', function (string $attribute, $value, $fail) use ($type) {
            $value = trim((string) $value);
            if ($value === '') return;
            $ok = match ($type) {
                'DNI' => (bool) preg_match('/^\d{8}$/', $value),
                // RUC: 11 dígitos; 10 persona natural, 15/17 no domiciliados y entidades, 20 persona jurídica.
                'RUC' => (bool) preg_match('/^(10|15|17|20)\d{9}$/', $value),
                'CE' => (bool) preg_match('/^[A-Za-z0-9]{9,12}$/', $value),
                'PASSPORT' => (bool) preg_match('/^[A-Za-z0-9]{6,12}$/', $value),
                default => true,
            };
            if (!$ok) $fail(match ($type) {
                'DNI' => 'El DNI debe tener 8 dígitos.',
                'RUC' => 'El RUC debe tener 11 dígitos y empezar con 10, 15, 17 o 20.',
                'CE' => 'El carné de extranjería debe tener entre 9 y 12 caracteres.',
                default => 'El pasaporte debe tener entre 6 y 12 caracteres.',
            });
        }];
    }
}
