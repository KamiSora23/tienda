<?php

namespace App\Services;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Envía correos con el SMTP de la empresa si lo tiene configurado y activo; si no, con el correo
 * general de la plataforma: el configurado en el panel del superadministrador o, si no hay, el de .env.
 */
final class CompanyMailer
{
    /** Ajustes de correo de la empresa con la contraseña descifrada, o null si no usa correo propio. */
    public function settings(?int $companyId): ?object
    {
        return $companyId ? $this->load(DB::table('company_mail_settings')->where(['company_id' => $companyId, 'enabled' => true])->first()) : null;
    }

    /** Correo general configurado desde el panel de plataforma (null si se usa el de .env). */
    public function platformSettings(): ?object
    {
        return $this->load(DB::table('platform_mail_settings')->where(['id' => 1, 'enabled' => true])->first());
    }

    /** Remitente del correo general, el que verán las empresas sin correo propio. */
    public function platformFrom(): ?string
    {
        return $this->platformSettings()?->from_address ?? (config('mail.default') === 'smtp' ? config('mail.from.address') : null);
    }

    private function load(?object $s): ?object
    {
        if (! $s || ! $s->host || ! $s->from_address) return null;
        try { $s->password = $s->password ? Crypt::decryptString($s->password) : null; } catch (\Throwable) { $s->password = null; }

        return $s;
    }

    /** Transporte SMTP construido con los datos dados (también sirve para probar antes de guardar). */
    public function smtp(object $s): Mailer
    {
        return Mail::build([
            'transport' => 'smtp',
            'scheme' => ($s->encryption ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
            'host' => $s->host,
            'port' => (int) $s->port,
            'username' => ($s->username ?? null) ?: null,
            'password' => ($s->password ?? null) ?: null,
            'timeout' => 15,
            'auto_tls' => ($s->encryption ?? 'tls') !== 'none',
        ]);
    }

    /**
     * Envía un correo. $html es opcional (si se omite, va solo texto). $settings fuerza una configuración (pruebas).
     * Devuelve "company" o "platform" según el remitente usado.
     */
    public function send(?int $companyId, string $to, string $subject, string $text, ?string $html = null, ?object $settings = null): string
    {
        $own = $settings ?? $this->settings($companyId);
        $s = $own ?? $this->platformSettings();
        $mailer = $s ? $this->smtp($s) : Mail::mailer();
        $build = function ($m) use ($to, $subject, $s) {
            $m->to($to)->subject($subject);
            if ($s) {
                $m->from($s->from_address, ($s->from_name ?? null) ?: null);
                if (! empty($s->reply_to)) $m->replyTo($s->reply_to);
            }
        };
        if ($html) $mailer->send([], [], fn ($m) => $build($m->html($html)->text($text)));
        else $mailer->raw($text, $build);

        return $own && $companyId ? 'company' : 'platform';
    }

    /** ¿Hay un correo real (no solo el registro en log) para este destinatario? */
    public function delivers(?int $companyId): bool
    {
        return $this->settings($companyId) || $this->platformSettings() || ! in_array(config('mail.default'), ['log', 'array'], true);
    }
}
