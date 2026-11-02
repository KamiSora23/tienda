"use client";
// Sucursales y almacenes. Todo almacén es de la empresa: exclusivo de una sucursal o compartido
// entre varias. Cada sucursal elige su almacén de venta (del que salen POS, ventas y compras).
import { FormEvent, useEffect, useState } from "react";
import { Modal, Table } from "@/app/components/ui";
import { api } from "@/app/lib/api";

type Props = { type: "branches" | "warehouses"; token: string; notify: (s: string) => void };

const SERIES_LABELS: Record<string, string> = { ticket: "Ticket", receipt: "Boleta", invoice: "Factura", credit_note: "Nota de crédito", debit_note: "Nota de débito", purchase: "Compra" };
const ROLE_LABELS: Record<string, string> = { admin: "Administrador", manager: "Gerente", cashier: "Cajero", seller: "Vendedor", warehouse: "Almacén", platform_admin: "Plataforma" };
const statusBadge = (s: string) => <span className={`status ${s === "active" ? "" : "bad"}`}>{s === "active" ? "Activo" : "Inactivo"}</span>;

export function LocationsModule({ type, token, notify }: Props) {
  const isBranch = type === "branches", title = isBranch ? "Sucursales" : "Almacenes";
  const [rows, setRows] = useState<any[]>([]), [branches, setBranches] = useState<any[]>([]);
  const [form, setForm] = useState<any | null>(null), [detail, setDetail] = useState<any | null>(null);

  const load = () => Promise.all([api(`/admin/${type}`, token), isBranch ? Promise.resolve(null) : api("/admin/branches", token)])
    .then(([r, b]) => { setRows(r); if (b) setBranches(b); }).catch((e) => notify(e.message));
  useEffect(() => { load(); }, [type, token]); // eslint-disable-line react-hooks/exhaustive-deps

  const run = async (action: () => Promise<any>, message: string) => {
    try { await action(); notify(message); load(); } catch (e: any) { notify(e.message); }
  };
  const show = async (id: number) => { try { setDetail(await api(`/${type}/${id}/detail`, token)); } catch (e: any) { notify(e.message); } };
  const activeBranches = branches.filter((b) => b.status === "active");
  const patch = (p: any) => setForm((f: any) => ({ ...f, ...p }));

  // Cuerpo de un almacén para la API, a partir del formulario o de una fila.
  const warehouseBody = (x: any, extra: any = {}) => ({
    code: x.code, name: x.name, status: x.status, scope: x.scope,
    ...(x.scope === "shared" ? { branch_ids: x.branch_ids } : { branch_id: x.branch_id ?? x.branch_ids?.[0] }),
    default_for: x.default_for ?? [], ...extra,
  });
  const branchBody = (x: any, extra: any = {}) => ({ code: x.code, name: x.name, address: x.address, phone: x.phone, status: x.status, default_warehouse_id: x.default_warehouse_id ?? null, ...extra });

  const save = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const fields: any = Object.fromEntries(new FormData(e.currentTarget));
    const body = isBranch
      ? branchBody({ ...form, ...fields, default_warehouse_id: fields.default_warehouse_id ? +fields.default_warehouse_id : form.default_warehouse_id ?? null })
      : warehouseBody({ ...form, ...fields, branch_id: form.scope === "branch" ? +fields.branch_id : null });
    if (isBranch && !form.id) delete body.default_warehouse_id;
    if (!isBranch && form.scope === "shared" && (form.branch_ids || []).length < 2) return notify("Marca al menos dos sucursales para compartir el almacén");
    try {
      await api(`/admin/${type}${form.id ? `/${form.id}` : ""}`, token, { method: form.id ? "PUT" : "POST", body: JSON.stringify(body) });
      notify(form.id ? "Cambios guardados" : isBranch ? "Sucursal creada con su almacén, caja y series" : "Almacén creado");
      setForm(null); load();
    } catch (x: any) { notify(x.message); }
  };
  const toggle = (x: any) => run(() => x.status === "active"
    ? api(`/admin/${type}/${x.id}`, token, { method: "DELETE" })
    : api(`/admin/${type}/${x.id}`, token, { method: "PUT", body: JSON.stringify(isBranch ? branchBody(x, { status: "active" }) : warehouseBody(x, { status: "active" })) }),
    x.status === "active" ? "Desactivado" : "Activado");
  const addLocation = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const d = Object.fromEntries(new FormData(e.currentTarget));
    try { await api("/configuration/warehouse-locations", token, { method: "POST", body: JSON.stringify({ ...d, warehouse_id: detail.id }) }); notify("Ubicación guardada"); show(detail.id); } catch (x: any) { notify(x.message); }
  };
  const newWarehouse = () => setForm({ status: "active", scope: "branch", branch_id: activeBranches[0]?.id, branch_ids: [], default_for: [] });
  const toggleIn = (list: number[] = [], id: number) => (list.includes(id) ? list.filter((x) => x !== id) : [...list, id]);
  // Sucursales con acceso al almacén según el formulario (para ofrecerle ser su almacén de venta).
  const reach = form && !isBranch ? (form.scope === "shared" ? form.branch_ids || [] : form.branch_id ? [+form.branch_id] : []) : [];

  return (
    <>
      <div className="page-head"><div><p>ESTRUCTURA OPERATIVA</p><h1>{title}</h1>
        <span>{isBranch ? "Cada sucursal vende desde su almacén de venta, que puede ser propio o compartido con otras sucursales." : "Los almacenes son de la empresa: exclusivos de una sucursal o compartidos entre varias."}</span>
      </div><button className="primary" onClick={() => (isBranch ? setForm({ status: "active" }) : newWarehouse())}>＋ {isBranch ? "Nueva sucursal" : "Nuevo almacén"}</button></div>

      <section className="panel list-panel">
        {isBranch ? (
          <Table columns={["Código", "Nombre", "Dirección", "Almacén de venta", "Almacenes", "Usuarios", "Estado", "Acciones"]}
            rows={rows.map((x) => [x.code, <strong key="n">{x.name}</strong>, x.address || "—", x.default_warehouse_name || <span className="status bad" key="d">Sin almacén</span>, x.warehouses_count, x.users_count, statusBadge(x.status),
              <div className="row-actions" key="a"><button onClick={() => show(x.id)}>Detalle</button><button onClick={() => setForm(x)}>Editar</button><button onClick={() => toggle(x)}>{x.status === "active" ? "Desactivar" : "Activar"}</button></div>])} />
        ) : (
          <Table columns={["Código", "Nombre", "Alcance", "Almacén de venta de", "Estado", "Acciones"]}
            rows={rows.map((x) => [x.code, <strong key="n">{x.name}</strong>,
              x.scope === "shared" ? <span key="s"><span className="status">Compartido</span><small className="cell-sub">{x.branch_name}</small></span> : <span key="s">Exclusivo<small className="cell-sub">{x.branch_name}</small></span>,
              x.default_for_names.length ? x.default_for_names.join(", ") : "—", statusBadge(x.status),
              <div className="row-actions" key="a"><button onClick={() => show(x.id)}>Detalle</button><button onClick={() => setForm({ ...x })}>Editar</button>
                {!x.default_for.length && <button onClick={() => toggle(x)}>{x.status === "active" ? "Desactivar" : "Activar"}</button>}</div>])} />
        )}
      </section>

      {form && (
        <Modal eyebrow={form.id ? "EDITAR" : "NUEVO"} title={isBranch ? (form.id ? "Sucursal" : "Nueva sucursal") : (form.id ? "Almacén" : "Nuevo almacén")} close={() => setForm(null)}>
          <form className="professional-form" onSubmit={save}>
            <div className="form-grid">
              <label>Código<input name="code" defaultValue={form.code || ""} maxLength={20} required /></label>
              <label>Nombre<input name="name" defaultValue={form.name || ""} maxLength={120} required /></label>
              {isBranch && <>
                <label className="wide">Dirección<input name="address" defaultValue={form.address || ""} /></label>
                <label>Teléfono<input name="phone" defaultValue={form.phone || ""} /></label>
                {form.id && <label>Almacén de venta<select name="default_warehouse_id" defaultValue={form.default_warehouse_id || ""}>
                  <option value="" disabled>Elige un almacén</option>
                  {(form.warehouses || []).map((w: any) => <option value={w.id} key={w.id}>{w.name}{w.shared ? " · compartido" : ""}</option>)}
                </select></label>}
              </>}
              {!isBranch && <>
                <fieldset className="wide scope-picker">
                  <legend>Pertenece a</legend>
                  <label><input type="radio" checked={form.scope === "branch"} onChange={() => patch({ scope: "branch", default_for: [] })} /> Una sola sucursal</label>
                  <label><input type="radio" checked={form.scope === "shared"} onChange={() => patch({ scope: "shared", branch_ids: form.branch_ids?.length ? form.branch_ids : form.branch_id ? [+form.branch_id] : [], default_for: [] })} /> Compartido entre sucursales</label>
                </fieldset>
                {form.scope === "branch"
                  ? <label>Sucursal<select name="branch_id" value={form.branch_id ?? ""} onChange={(e) => patch({ branch_id: +e.target.value, default_for: [] })} required>{activeBranches.map((b) => <option value={b.id} key={b.id}>{b.name}</option>)}</select></label>
                  : <fieldset className="wide branch-checks"><legend>Sucursales que lo usan</legend>
                      {activeBranches.map((b) => <label key={b.id}><input type="checkbox" checked={(form.branch_ids || []).includes(b.id)} onChange={() => patch({ branch_ids: toggleIn(form.branch_ids, b.id), default_for: (form.default_for || []).filter((x: number) => x !== b.id || !(form.branch_ids || []).includes(b.id)) })} /> {b.name}</label>)}
                    </fieldset>}
                {reach.length > 0 && <fieldset className="wide branch-checks"><legend>Usar como almacén de venta en</legend>
                  {activeBranches.filter((b) => reach.includes(b.id)).map((b) => <label key={b.id}><input type="checkbox" checked={(form.default_for || []).includes(b.id)} onChange={() => patch({ default_for: toggleIn(form.default_for, b.id) })} /> {b.name}{b.default_warehouse_name && b.default_warehouse_id !== form.id ? <small> (hoy: {b.default_warehouse_name})</small> : null}</label>)}
                </fieldset>}
              </>}
              <label>Estado<select name="status" defaultValue={form.status || "active"}><option value="active">Activo</option><option value="inactive">Inactivo</option></select></label>
            </div>
            <div className="form-actions"><button type="button" onClick={() => setForm(null)}>Cancelar</button><button className="primary">Guardar</button></div>
          </form>
        </Modal>
      )}

      {detail && (
        <Modal eyebrow="DETALLE" title={detail.name} className="location-detail" close={() => setDetail(null)}>
          <div className="location-content">
            {isBranch ? (<>
              <section><h3>Almacenes a su alcance</h3><Table columns={["Código", "Nombre", "Tipo", "Uso", "Estado"]} rows={detail.warehouses.map((w: any) => [w.code, w.name, w.shared ? "Compartido" : "Exclusivo", w.is_sales ? "Almacén de venta" : "—", statusBadge(w.status)])} /></section>
              <section><h3>Usuarios</h3><Table columns={["Nombre", "Correo", "Rol", "Estado"]} rows={detail.users.map((u: any) => [u.name, u.email, ROLE_LABELS[u.role] || u.role, statusBadge(u.status)])} /></section>
              <section><h3>Cajas</h3><Table columns={["Código", "Nombre", "Estado"]} rows={detail.cash_registers.map((c: any) => [c.code, c.name, statusBadge(c.status)])} /></section>
              <section><h3>Series de comprobantes</h3><Table columns={["Comprobante", "Serie", "Último número", "Estado"]} rows={detail.series.map((s: any) => [SERIES_LABELS[s.document_type] || s.document_type, s.series, String(s.current_number).padStart(8, "0"), s.active ? "Activa" : "Inactiva"])} /></section>
              <section><h3>Impresoras</h3><Table columns={["Nombre", "Tipo", "Papel", "Estado"]} rows={detail.printers.map((p: any) => [p.name, p.type, p.paper_size || "—", statusBadge(p.status)])} empty="Sin impresoras configuradas." /></section>
            </>) : (<>
              <section><h3>Stock almacenado</h3><Table columns={["SKU", "Producto", "Cantidad"]} rows={detail.stock.filter((s: any) => +s.quantity !== 0).map((s: any) => [s.sku, s.name, String(+s.quantity)])} empty="Este almacén no tiene stock." /></section>
              <section><h3>Mapa y ubicaciones internas</h3>
                <div className="warehouse-map">{detail.locations.map((x: any) => <article key={x.id}><b>{x.code}</b><strong>{x.name}</strong><span>{[x.zone, x.aisle, x.rack, x.level].filter(Boolean).join(" · ")}</span></article>)}</div>
                <form className="crm-inline-form" onSubmit={addLocation}><input name="code" placeholder="Código" required /><input name="name" placeholder="Nombre" required /><input name="zone" placeholder="Zona" /><input name="aisle" placeholder="Pasillo" /><input name="rack" placeholder="Rack" /><input name="level" placeholder="Nivel" /><input type="hidden" name="status" value="active" /><button className="primary">Agregar ubicación</button></form>
              </section>
            </>)}
          </div>
        </Modal>
      )}
    </>
  );
}
