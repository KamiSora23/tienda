"use client";
// Catálogo de unidades de la empresa: las estándar del sistema, con cuáles usa la tienda.
// Docena, caja, saco o rollo no se crean aquí: son presentaciones de cada producto.
import { useEffect, useState } from "react";
import { api } from "@/app/lib/api";
import { FAMILIES, type Family } from "@/app/lib/units";
import { Table } from "@/app/components/ui";

type Unit = { code: string; name: string; symbol: string; family: Family; allows_decimals: boolean; status: "active" | "inactive"; products: number };

export function UnitsModule({ token, notify }: { token: string; notify: (m: string) => void }) {
  const [units, setUnits] = useState<Unit[]>([]);
  const [saving, setSaving] = useState("");
  const load = () => api("/units", token).then(setUnits).catch((e) => notify(e.message));
  useEffect(() => { load(); }, [token]); // eslint-disable-line react-hooks/exhaustive-deps

  const toggle = async (u: Unit) => {
    setSaving(u.code);
    try {
      await api(`/units/${u.code}`, token, { method: "PUT", body: JSON.stringify({ status: u.status === "active" ? "inactive" : "active" }) });
      await load();
    } catch (e: any) { notify(e.message); } finally { setSaving(""); }
  };

  const active = units.filter((u) => u.status === "active").length;
  return (
    <>
      <div className="page-head"><div><p>CATÁLOGO</p><h1>Unidades de medida</h1>
        <span>Activa las unidades con las que vendes. Solo las activas aparecen al crear productos; agrupaciones como docena, caja o saco se configuran como presentaciones de cada producto.</span>
      </div></div>
      <p className="units-summary">{active} de {units.length} unidades activas</p>
      {(Object.keys(FAMILIES) as Family[]).map((family) => {
        const rows = units.filter((u) => u.family === family);
        if (!rows.length) return null;
        return (
          <section className="panel list-panel units-family" key={family}>
            <header className="panel-title"><div><h2>{FAMILIES[family]}</h2><p>{family === "count" ? "Se venden en cantidades enteras" : "Admiten decimales y venta por importe"}</p></div></header>
            <Table
              columns={["Unidad", "Símbolo", "Productos que la usan", "Estado", ""]}
              rows={rows.map((u) => [
                <strong key="n">{u.name}</strong>,
                u.symbol,
                u.products ? `${u.products} producto${u.products === 1 ? "" : "s"}` : "—",
                <span key="s" className={`status ${u.status === "active" ? "" : "bad"}`}>{u.status === "active" ? "Activa" : "Inactiva"}</span>,
                <button key="b" className="table-action" disabled={saving === u.code} title={u.status === "active" && u.products ? "Los productos que ya la usan la conservan" : undefined} onClick={() => toggle(u)}>
                  {u.status === "active" ? "Desactivar" : "Activar"}
                </button>,
              ])}
            />
          </section>
        );
      })}
    </>
  );
}
