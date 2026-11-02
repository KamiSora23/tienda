<?php

namespace App\Http\Controllers;

use App\Services\CompanyMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Configuración › Correo: SMTP propio de la empresa. La contraseña nunca se devuelve al navegador.
 * PlatformMailController reutiliza todo para el correo general de la plataforma.
 */
class MailSettingsController extends Controller
{
    protected const PRESETS = ['gmail' => ['smtp.gmail.com', 587, 'tls'], 'outlook' => ['smtp.office365.com', 587, 'tls'], 'yahoo' => ['smtp.mail.yahoo.com', 465, 'ssl'], 'zoho' => ['smtp.zoho.com', 465, 'ssl']];

    /** Tabla y clave de la fila que se edita. */
    protected function target(Request $r): array
    {
        return ['company_mail_settings', ['company_id' => $r->user()->company_id]];
    }

    /** Remitente que se usa mientras esta configuración no esté activa. */
    protected function fallbackFrom(): ?string
    {
        return app(CompanyMailer::class)->platformFrom();
    }

    protected function defaultName(Request $r): string
    {
        return (string) DB::table('companies')->where('id', $r->user()->company_id)->value('trade_name');
    }

    /** Empresa con la que se prueba el envío (null = plataforma). */
    protected function scopeCompany(Request $r): ?int
    {
        return $r->user()->company_id;
    }

    protected function audit(Request $r, array $data): void
    {
        $this->recordAudit($r, 'mail.settings_saved', 'settings', null, $data);
    }

    private function row(Request $r): ?object
    {
        [$table, $key] = $this->target($r);

        return DB::table($table)->where($key)->first();
    }

    public function show(Request $r)
    {
        $s = $this->row($r);

        return response()->json(['success' => true, 'data' => [
            'enabled' => (bool) ($s->enabled ?? false), 'host' => $s->host ?? '', 'port' => (int) ($s->port ?? 587), 'encryption' => $s->encryption ?? 'tls',
            'username' => $s->username ?? '', 'has_password' => ! empty($s?->password), 'from_address' => $s->from_address ?? '', 'from_name' => $s->from_name ?? $this->defaultName($r),
            'reply_to' => $s->reply_to ?? '', 'last_test_at' => $s->last_test_at ?? null, 'last_test_ok' => isset($s->last_test_ok) ? (bool) $s->last_test_ok : null, 'last_error' => $s->last_error ?? null,
            'platform_from' => $this->fallbackFrom(), 'presets' => static::PRESETS,
        ]]);
    }

    private function validated(Request $r, bool $requireAll): array
    {
        return $r->validate([
            'enabled' => 'boolean', 'host' => ($requireAll ? 'required' : 'nullable').'|string|max:190', 'port' => 'required|integer|between:1,65535', 'encryption' => 'required|in:tls,ssl,none',
            'username' => 'nullable|string|max:190', 'password' => 'nullable|string|max:255', 'from_address' => ($requireAll ? 'required' : 'nullable').'|email|max:190',
            'from_name' => 'nullable|string|max:120', 'reply_to' => 'nullable|email|max:190', 'to' => 'nullable|email',
        ], ['host.required' => 'Indica el servidor SMTP.', 'from_address.required' => 'Indica el correo remitente.']);
    }

    public function save(Request $r)
    {
        [$table, $key] = $this->target($r);
        $d = $this->validated($r, $r->boolean('enabled'));
        $data = collect($d)->only(['host', 'port', 'encryption', 'username', 'from_address', 'from_name', 'reply_to'])->all() + ['enabled' => $r->boolean('enabled'), 'updated_at' => now()];
        // Contraseña: solo se cambia si se escribe una nueva; los espacios (como los muestra Gmail) se quitan.
        if (filled($d['password'] ?? null)) $data['password'] = Crypt::encryptString(str_replace(' ', '', $d['password']));
        DB::table($table)->updateOrInsert($key, $data + ['created_at' => now()]);
        $this->audit($r, collect($data)->except('password')->all() + ['password_changed' => isset($data['password'])]);

        return response()->json(['success' => true, 'message' => $data['enabled'] ? 'Correo guardado y activo' : 'Correo guardado (desactivado)']);
    }

    /** Prueba con los datos del formulario (aunque no estén guardados) y registra el resultado. */
    public function test(Request $r, CompanyMailer $mailer)
    {
        [$table, $key] = $this->target($r);
        $d = $this->validated($r, true);
        $saved = $this->row($r);
        $password = filled($d['password'] ?? null) ? str_replace(' ', '', $d['password']) : ($saved?->password ? rescue(fn () => Crypt::decryptString($saved->password), null, false) : null);
        $settings = (object) (collect($d)->only(['host', 'port', 'encryption', 'username', 'from_address', 'from_name', 'reply_to'])->all() + ['password' => $password]);
        $to = $d['to'] ?? $r->user()->email;
        try {
            $mailer->send($this->scopeCompany($r), $to, 'Prueba de correo de '.($settings->from_name ?: 'Deurstore'), "Este es un correo de prueba enviado desde Deurstore.\nSi lo recibes, los correos saldrán desde {$settings->from_address}.", null, $settings);
            $ok = true; $error = null;
        } catch (\Throwable $e) {
            $ok = false; $error = $this->friendly($e->getMessage());
        }
        if ($saved) DB::table($table)->where($key)->update(['last_test_at' => now(), 'last_test_ok' => $ok, 'last_error' => $error ? mb_substr($error, 0, 500) : null]);
        abort_unless($ok, 422, $error ?? 'No se pudo enviar el correo de prueba.');

        return response()->json(['success' => true, 'message' => "Correo de prueba enviado a {$to}. Revisa la bandeja de entrada y la carpeta de spam."]);
    }

    private function friendly(string $m): string
    {
        $l = strtolower($m);
        return match (true) {
            str_contains($l, '535') || str_contains($l, 'username and password') || str_contains($l, 'authentication') => 'El servidor rechazó el usuario o la contraseña. En Gmail usa una contraseña de aplicación, no tu contraseña normal.',
            str_contains($l, 'connection could not be established') || str_contains($l, 'timed out') || str_contains($l, 'refused') => 'No se pudo conectar con el servidor de correo. Revisa el servidor, el puerto y el cifrado (587 con TLS o 465 con SSL).',
            str_contains($l, 'sender') || str_contains($l, '553') || str_contains($l, '550') => 'El servidor no permite enviar con ese remitente. Usa como remitente la misma cuenta con la que inicias sesión.',
            default => 'No se pudo enviar: '.mb_substr($m, 0, 200),
        };
    }
}
