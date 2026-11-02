<?php

namespace App\Http\Controllers;

use App\Services\CompanyMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Envía el comprobante de una venta al cliente, con el correo de la empresa. */
class SaleEmailController extends Controller
{
    private const DOCS = ['ticket' => 'Ticket', 'receipt' => 'Boleta', 'invoice' => 'Factura'];
    private const METHODS = ['cash' => 'Efectivo', 'yape' => 'Yape', 'card' => 'Tarjeta', 'transfer' => 'Transferencia'];

    public function send(Request $r, CompanyMailer $mailer, int $id)
    {
        $c = $r->user()->company_id;
        $sale = DB::table('sales')->where(['id' => $id, 'company_id' => $c])->firstOrFail();
        $customer = $sale->customer_id ? DB::table('customers')->where(['id' => $sale->customer_id, 'company_id' => $c])->first() : null;
        $to = $r->validate(['email' => 'nullable|email|max:190'])['email'] ?? $customer?->email;
        abort_unless($to, 422, 'Indica el correo del cliente.');

        $company = DB::table('companies')->where('id', $c)->first();
        $branch = DB::table('branches')->where('id', $sale->branch_id)->first();
        $items = DB::table('sale_details')->where('sale_id', $id)->orderBy('id')->get();
        $payments = DB::table('sale_payments')->where('sale_id', $id)->get();
        $doc = self::DOCS[$sale->document_type] ?? 'Comprobante';
        $name = $company->trade_name ?: $company->business_name;

        try {
            $via = $mailer->send($c, $to, "{$doc} {$sale->number} – {$name}", $this->text($sale, $doc, $name, $items, $payments), $this->html($sale, $doc, $company, $branch, $customer, $items, $payments));
        } catch (\Throwable $e) {
            report($e);
            abort(422, 'No se pudo enviar el correo. Revisa la configuración en Configuración › Correo.');
        }
        // Se guarda el correo en la ficha del cliente si no lo tenía.
        if ($customer && ! $customer->email) DB::table('customers')->where('id', $customer->id)->update(['email' => $to, 'updated_at' => now()]);
        $this->recordAudit($r, 'sale.emailed', 'sale', $id, ['to' => $to, 'via' => $via]);

        return response()->json(['success' => true, 'message' => "{$doc} enviado a {$to}", 'data' => ['via' => $via]]);
    }

    private function money($v): string
    {
        return 'S/ '.number_format((float) $v, 2);
    }

    private function line(object $i): string
    {
        return $i->presentation_name ? (+$i->presentation_quantity).' × '.$i->presentation_name : (+$i->quantity).' × '.$this->money($i->unit_price);
    }

    private function text($sale, $doc, $name, $items, $payments): string
    {
        $lines = $items->map(fn ($i) => "- {$i->product_name} ({$this->line($i)}): {$this->money($i->total)}")->implode("\n");

        return "{$name}\n{$doc} {$sale->number} · ".date('d/m/Y H:i', strtotime($sale->created_at))."\n\n{$lines}\n\nDescuento: {$this->money($sale->discount)}\nTotal: {$this->money($sale->total)}\n\nGracias por tu compra.";
    }

    private function html($sale, $doc, $company, $branch, $customer, $items, $payments): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $rows = $items->map(fn ($i) => '<tr><td style="padding:8px 0;border-bottom:1px solid #eee">'.$e($i->product_name).'<br><small style="color:#777">'.$e($this->line($i)).($i->discount > 0 ? ' · desc. '.$this->money($i->discount) : '').'</small></td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;white-space:nowrap">'.$this->money($i->total).'</td></tr>')->implode('');
        $pays = $payments->map(fn ($p) => $e(self::METHODS[$p->method] ?? $p->method).' '.$this->money($p->amount))->implode(' · ');
        $name = $company->trade_name ?: $company->business_name;
        $who = $customer ? '<p style="margin:0 0 14px;color:#555">Cliente: <b>'.$e($customer->name).'</b>'.($customer->document_number ? ' · '.$e($customer->document_type).' '.$e($customer->document_number) : '').'</p>' : '';
        $status = $sale->status === 'cancelled' ? '<p style="background:#fdecea;color:#a64537;padding:8px 10px;border-radius:6px">Este comprobante fue anulado.</p>' : '';

        return '<!doctype html><html><body style="margin:0;background:#f4f6f5;font-family:Arial,Helvetica,sans-serif;color:#17211c">'
            .'<div style="max-width:520px;margin:24px auto;background:#fff;border-radius:12px;padding:24px;border:1px solid #e6e9e7">'
            .'<h2 style="margin:0">'.$e($name).'</h2><p style="margin:4px 0 16px;color:#777;font-size:13px">'.$e($company->business_name).' · RUC '.$e($company->ruc).'<br>'.$e($branch->address ?? $company->address).'</p>'
            .'<p style="margin:0 0 4px;font-size:15px"><b>'.$e($doc).' '.$e($sale->number).'</b></p><p style="margin:0 0 14px;color:#777;font-size:13px">'.date('d/m/Y H:i', strtotime($sale->created_at)).'</p>'
            .$who.$status.'<table style="width:100%;border-collapse:collapse;font-size:14px">'.$rows.'</table>'
            .'<table style="width:100%;font-size:14px;margin-top:12px">'.($sale->discount > 0 ? '<tr><td>Descuento</td><td style="text-align:right">- '.$this->money($sale->discount).'</td></tr>' : '').'<tr><td>IGV incluido</td><td style="text-align:right">'.$this->money($sale->tax).'</td></tr><tr><td style="font-size:18px;padding-top:6px"><b>Total</b></td><td style="text-align:right;font-size:18px;padding-top:6px"><b>'.$this->money($sale->total).'</b></td></tr></table>'
            .($pays ? '<p style="color:#555;font-size:13px;margin-top:10px">Pagado con: '.$pays.'</p>' : '')
            .'<p style="margin-top:20px;color:#777;font-size:12px">Gracias por tu compra. Este correo es una copia de tu comprobante.</p></div></body></html>';
    }
}
