"use client";
// Franja del superadministrador: siempre visible, indica en qué empresa opera y si puede editar.
// Por defecto trabaja en solo lectura; la edición se activa con motivo y por tiempo limitado.
import { Modal } from "@/app/components/ui";
import { api } from "@/app/lib/api";
import { useEffect, useState } from "react";

type Props = { token: string; me: any; refresh: () => Promise<void> | void; notify: (m: string) => void; global?: boolean };

export function PlatformBar({ token, me, refresh, notify, global = false }: Props) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState("");
  const [minutes, setMinutes] = useState(30);
  const [busy, setBusy] = useState(false);
  const [, tick] = useState(0);
  const until = me?.platform_write?.platform_write_until ? new Date(me.platform_write.platform_write_until.replace(" ", "T")) : null;
  const editing = !!until && until.getTime() > Date.now();

  // Al vencer el plazo, la franja vuelve sola a "solo lectura".
  useEffect(() => {
    if (!editing) return;
    const t = setInterval(() => { if (until!.getTime() <= Date.now()) { refresh(); } else tick((n) => n + 1); }, 30000);
    return () => clearInterval(t);
  }, [editing, until?.getTime()]); // eslint-disable-line react-hooks/exhaustive-deps

  if (!me?.is_platform) return null;
  if (global) return <div className="platform-bar global" role="status"><span className="platform-icon" aria-hidden>◈</span><div><strong>Panel de plataforma</strong><small>Vista global: aquí no estás dentro de ninguna empresa. Usa «Entrar a la empresa» para trabajar en una.</small></div></div>;
  const company = me.company?.trade_name || me.company?.business_name || "—";

  const start = async (e: React.FormEvent) => {
    e.preventDefault(); setBusy(true);
    try { await api("/platform/write-mode", token, { method: "POST", body: JSON.stringify({ reason, minutes }) }); notify(`Edición activada en ${company}`); setOpen(false); setReason(""); await refresh(); } catch (x: any) { notify(x.message); } finally { setBusy(false); }
  };
  const stop = async () => {
    try { await api("/platform/write-mode", token, { method: "DELETE" }); notify("Volviste al modo solo lectura"); await refresh(); } catch (x: any) { notify(x.message); }
  };

  return (
    <>
      <div className={`platform-bar ${editing ? "editing" : ""}`} role="status">
        <span className="platform-icon" aria-hidden>🛡</span>
        <div>
          <strong>Superadministrador en {company}</strong>
          <small>{editing ? `Edición activa hasta las ${until!.toLocaleTimeString("es-PE", { hour: "2-digit", minute: "2-digit" })} · Motivo: ${me.platform_write.platform_write_reason}` : "Solo lectura: puedes consultar todo; para hacer cambios en esta empresa activa la edición."}</small>
        </div>
        {editing ? <button onClick={stop}>Terminar edición</button> : <button className="activate" onClick={() => setOpen(true)}>Activar edición</button>}
      </div>
      {open && (
        <Modal title="Activar edición" eyebrow={`SUPERADMINISTRADOR · ${company.toUpperCase()}`} close={() => setOpen(false)}>
          <form className="platform-form" onSubmit={start}>
            <p>Vas a poder modificar datos de <b>{company}</b>. El motivo y todo lo que hagas quedan registrados en la auditoría de la empresa, marcados como acciones del superadministrador.</p>
            <label>Motivo<textarea required minLength={10} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ej.: Ticket 123 – la tienda pidió corregir una serie de comprobantes" /></label>
            <label>Duración<select value={minutes} onChange={(e) => setMinutes(+e.target.value)}><option value={15}>15 minutos</option><option value={30}>30 minutos</option><option value={60}>1 hora</option><option value={120}>2 horas</option></select></label>
            <footer className="form-actions"><button type="button" onClick={() => setOpen(false)}>Cancelar</button><button className="primary" disabled={busy || reason.trim().length < 10}>{busy ? "Activando…" : "Activar edición"}</button></footer>
          </form>
        </Modal>
      )}
    </>
  );
}
