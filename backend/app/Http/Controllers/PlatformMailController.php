<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/** Panel de plataforma › Correo: el correo general (lo usan el superadministrador y las empresas sin correo propio). */
class PlatformMailController extends MailSettingsController
{
    protected function target(Request $r): array
    {
        return ['platform_mail_settings', ['id' => 1]];
    }

    /** Sin configuración activa en la app, se usa la de backend/.env. */
    protected function fallbackFrom(): ?string
    {
        return config('mail.default') === 'smtp' ? config('mail.from.address') : null;
    }

    protected function defaultName(Request $r): string
    {
        return 'Deurstore';
    }

    protected function scopeCompany(Request $r): ?int
    {
        return null;
    }

    protected function audit(Request $r, array $data): void
    {
        $this->recordAudit($r, 'platform.mail_saved', 'platform', null, $data);
    }
}
