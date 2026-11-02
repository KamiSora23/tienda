"use client";
// Panel de plataforma: lo propio del superadministrador, antes de entrar a una empresa.
import { MailSettings } from "@/app/MailSettings";
import { api } from "@/app/lib/api";
import { money } from "@/app/lib/format";
import { eventLabel } from "@/app/lib/labels";
import { useEffect, useState } from "react";

type Props = { token: string; notify: (m: string) => void; me: any; enterCompany: (companyId: number, branchId: number) => Promise<void>; openModule: (m: "Empresas" | "Respaldos" | "Mantenimiento") => void };
type Company = { id: number; trade_name: string; business_name: string; ruc: string; status: string; branches: number; users: number; products: number; sales_30d: number; amount_30d: number; last_sale_at: string | null; last_login_at: string | null; pending_orders: number; own_mail: boolean; main_branch_id: number | null };

const ago = (d?: string | null) => {
  if (!d) return "nunca";
  const days = Math.floor((Date.now() - new Date(d.replace(" ", "T")).getTime()) / 86400000);
  return days <= 0 ? "hoy" : days === 1 ? "ayer" : `hace ${days} días`;
};
const TABS = [["companies", "Empresas"], ["mail", "Correo de la plataforma"], ["admins", "Superadministradores"], ["activity", "Actividad del superadministrador"]] as const;

export function PlatformPanel({ token, notify, me, enterCompany, openModule }: Props) {
  const [tab, setTab] = useState<(typeof TABS)[number][0]>("companies");
  const [overview, setOverview] = useState<{ companies: Company[]; totals: any } | null>(null);
  const [admins, setAdmins] = useState<any[] | null>(null);
  const [activity, setActivity] = useState<any[] | null>(null);
  const [search, setSearch] = useState("");
  const [entering, setEntering] = useState(0);

  useEffect(() => {
    if (tab === "companies") api("/platform/overview", token).then(setOverview).catch((e) => notify(e.message));
    if (tab === "admins") api("/platform/admins", token).then(setAdmins).catch((e) => notify(e.message));
    if (tab === "activity") api("/platform/activity", token).then(setActivity).catch((e) => notify(e.message));
  }, [tab, token]); // eslint-disable-line react-hooks/exhaustive-deps

  const enter = async (c: Company) => {
    if (!c.main_branch_id) return notify(`${c.trade_name} no tiene una sucursal activa.`);
    setEntering(c.id);
    try { await enterCompany(c.id, c.main_branch_id); } finally { setEntering(0); }
  };
  const companies = (overview?.companies ?? []).filter((c) => `${c.trade_name} ${c.business_name} ${c.ruc}`.toLowerCase().includes(search.toLowerCase()));

  return (
    <>
      <div className="page-head">
        <div><p>PLATAFORMA</p><h1>Panel de plataforma</h1><span>Vista global de todas las empresas y la configuración propia de la plataforma. Aquí no estás dentro de ninguna empresa.</span></div>
        <div className="head-actions"><button onClick={() => openModule("Respaldos")}>Respaldos</button><button onClick={() => openModule("Mantenimiento")}>Mantenimiento</button><button className="primary" onClick={() => openModule("Empresas")}>＋ Nueva empresa</button></div>
      </div>
      <div className="module-tabs">{TABS.map(([k, l]) => <button key={k} className={tab === k ? "active" : ""} onClick={() => setTab(k)}>{l}</button>)}</div>

      {tab === "companies" && (!overview ? <section className="panel"><p className="empty-table">Cargando…</p></section> : <>
        <div className="stats platform-stats">
          <article className="stat"><div><small>Empresas</small><strong>{overview.totals.companies}</strong><p>{overview.totals.active} activas</p></div></article>
          <article className="stat"><div><small>Usuarios</small><strong>{overview.totals.users}</strong><p>sin contar superadministradores</p></div></article>
          <article className="stat"><div><small>Ventas (30 días)</small><strong>{overview.totals.sales_30d}</strong><p>{money(overview.totals.amount_30d)} en total</p></div></article>
        </div>
        <section className="panel">
          <div className="table-tools"><label>⌕ <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Buscar empresa por nombre o RUC…" /></label></div>
          <div className="platform-companies">{companies.map((c) => (
            <article key={c.id} className={c.status !== "active" ? "off" : ""}>
              <header>
                <div><strong>{c.trade_name}</strong><small>{c.business_name} · RUC {c.ruc}</small></div>
                <span className={`status ${c.status === "active" ? "" : "bad"}`}>{c.status === "active" ? "Activa" : "Suspendida"}</span>
              </header>
              <dl>
                <div><dt>Ventas 30 días</dt><dd>{c.sales_30d} · {money(c.amount_30d)}</dd></div>
                <div><dt>Última venta</dt><dd>{ago(c.last_sale_at)}</dd></div>
                <div><dt>Sucursales · usuarios</dt><dd>{c.branches} · {c.users}</dd></div>
                <div><dt>Productos activos</dt><dd>{c.products}</dd></div>
                <div><dt>Último ingreso</dt><dd>{ago(c.last_login_at)}</dd></div>
                <div><dt>Correo</dt><dd>{c.own_mail ? "Propio" : "De la plataforma"}</dd></div>
              </dl>
              {c.pending_orders > 0 && <p className="platform-alert">{c.pending_orders} pedido(s) web pendiente(s)</p>}
              <footer>
                {me?.company_id === c.id && <small>Es la empresa en la que estás trabajando</small>}
                <button className="primary" disabled={!!entering || c.status !== "active"} onClick={() => enter(c)}>{entering === c.id ? "Entrando…" : "Entrar a la empresa →"}</button>
              </footer>
            </article>
          ))}{companies.length === 0 && <p className="empty-table">No hay empresas que coincidan.</p>}</div>
          <p className="settings-help platform-foot">Al entrar a una empresa trabajas en <b>solo lectura</b>. Para hacer cambios dentro de ella activa la edición con un motivo. Las empresas se crean, editan y suspenden en el módulo Empresas.</p>
        </section>
      </>)}

      {tab === "mail" && <MailSettings token={token} notify={notify} platform />}

      {tab === "admins" && (
        <section className="panel list-panel">
          <header className="inner-head"><div><h2>Superadministradores</h2><p className="settings-help">Por seguridad solo se crean y se quitan desde la consola del servidor, con <code>php artisan user:promote-platform correo</code>. Todos usan verificación en dos pasos.</p></div></header>
          <div className="table-wrap"><table><thead><tr><th>Nombre</th><th>Correo</th><th>Estado</th><th>Último ingreso</th><th>Sesiones abiertas</th><th>Empresa en la que está</th></tr></thead>
            <tbody>{(admins ?? []).map((a) => <tr key={a.id}><td><strong>{a.name}</strong>{a.id === me?.id && <em className="platform-tag">Tú</em>}</td><td>{a.email}</td><td>{a.status === "active" ? "Activo" : "Inactivo"}</td><td>{a.last_login_at ? new Date(a.last_login_at).toLocaleString("es-PE") : "—"}</td><td>{a.sessions}</td><td>{a.current_company ?? "—"}</td></tr>)}
              {admins === null && <tr><td colSpan={6}>Cargando…</td></tr>}</tbody></table></div>
        </section>
      )}

      {tab === "activity" && (
        <section className="panel list-panel">
          <header className="inner-head"><div><h2>Actividad del superadministrador</h2><p className="settings-help">Todo lo que hicieron los superadministradores dentro de cada empresa. Cada empresa también lo ve en su Auditoría, marcado como «Superadmin».</p></div></header>
          <div className="table-wrap"><table><thead><tr><th>Fecha</th><th>Usuario</th><th>Empresa</th><th>Acción</th><th>Motivo</th><th>IP</th></tr></thead>
            <tbody>{(activity ?? []).map((x) => <tr key={x.id}><td>{new Date(x.created_at).toLocaleString("es-PE")}</td><td>{x.user_name ?? "—"}</td><td>{x.company ?? "—"}</td><td title={x.event}>{eventLabel(x.event)}</td><td>{x.reason ?? "—"}</td><td>{x.ip ?? "—"}</td></tr>)}
              {activity === null && <tr><td colSpan={6}>Cargando…</td></tr>}{activity?.length === 0 && <tr><td colSpan={6}>Sin actividad registrada.</td></tr>}</tbody></table></div>
        </section>
      )}
    </>
  );
}
