"use client";
// Envía el comprobante de una venta al cliente por correo (con el correo de la empresa).
import { api } from "@/app/lib/api";
import { useState } from "react";

export function SaleEmailForm({ token, saleId, defaultEmail = "", notify }: { token: string; saleId: number; defaultEmail?: string; notify: (m: string) => void }) {
  const [email, setEmail] = useState(defaultEmail);
  const [busy, setBusy] = useState(false);
  const [sentTo, setSentTo] = useState("");
  const send = async (e: React.FormEvent) => {
    e.preventDefault(); setBusy(true);
    try { const r = await api(`/sales/${saleId}/email`, token, { method: "POST", body: JSON.stringify({ email: email || null }) }); setSentTo(email || "el correo del cliente"); notify(r?.message ?? "Comprobante enviado"); }
    catch (x: any) { notify(x.message); } finally { setBusy(false); }
  };
  return (
    <form className="sale-email" onSubmit={send}>
      <span>✉ Enviar comprobante</span>
      <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="correo@cliente.com (vacío = el del cliente)" aria-label="Correo del cliente" />
      <button disabled={busy}>{busy ? "Enviando…" : "Enviar por correo"}</button>
      {sentTo && <small>✓ Enviado a {sentTo}</small>}
    </form>
  );
}
