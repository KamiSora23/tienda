import { codeLabel, columnLabel } from "@/app/lib/labels";
// Componentes de interfaz compartidos. Antes cada módulo tenía su propia copia
// de Modal y de las tablas, con pequeñas diferencias de comportamiento.
import type { ReactNode } from "react";

type ModalProps = { title: string; close: () => void; children: ReactNode; eyebrow?: string; className?: string };

// Se cierra con la X o al pulsar fuera; onMouseDown evita cerrar al arrastrar
// una selección de texto desde un campo hasta el fondo.
export function Modal({ title, close, children, eyebrow, className = "" }: ModalProps) {
  return (
    <div className="modal-backdrop" onMouseDown={(e) => e.target === e.currentTarget && close()}>
      <section className={`modal-card ${className}`} role="dialog" aria-modal="true" aria-label={title}>
        <header>
          <div>{eyebrow && <small>{eyebrow}</small>}<h2>{title}</h2></div>
          <button type="button" onClick={close} aria-label="Cerrar">×</button>
        </header>
        {children}
      </section>
    </div>
  );
}

// Tabla con columnas y celdas definidas por el módulo.
export function Table({ columns, rows, empty = "Sin registros." }: { columns: string[]; rows: ReactNode[][]; empty?: string }) {
  return (
    <div className="table-wrap">
      <table>
        <thead><tr>{columns.map((c) => <th key={c}>{c}</th>)}</tr></thead>
        <tbody>
          {rows.length
            ? rows.map((r, i) => <tr key={i}>{r.map((v, j) => <td key={j}>{v}</td>)}</tr>)
            : <tr><td colSpan={columns.length} className="empty-cell">{empty}</td></tr>}
        </tbody>
      </table>
    </div>
  );
}

// Campos internos que nunca deben mostrarse en tablas generadas automáticamente.
const HIDDEN_FIELDS = ["uuid", "company_id", "password", "updated_at"];
const cellText = (v: unknown) => (v !== null && typeof v === "object" ? JSON.stringify(v) : String(codeLabel(v) ?? "—"));

type AutoTableProps = {
  rows: any[];
  exclude?: string[];
  limit?: number;
  empty?: string;
  action?: { label: string; onClick: (row: any) => void };
};

// Tabla que deduce las columnas del primer registro (listados genéricos de la API).
export function AutoTable({ rows, exclude = [], limit = 8, empty = "Sin registros.", action }: AutoTableProps) {
  if (!rows?.length) return <p className="empty-table">{empty}</p>;
  const hidden = [...HIDDEN_FIELDS, ...exclude];
  const cols = Object.keys(rows[0]).filter((k) => !hidden.includes(k)).slice(0, limit);
  return (
    <div className="table-wrap">
      <table>
        <thead><tr>{cols.map((c) => <th key={c}>{columnLabel(c)}</th>)}{action && <th />}</tr></thead>
        <tbody>
          {rows.map((r, i) => (
            <tr key={r.id ?? i}>
              {cols.map((c) => <td key={c}>{cellText(/_at$/.test(c) && r[c] ? new Date(r[c]).toLocaleString("es-PE") : r[c])}</td>)}
              {action && <td><button className="table-action" onClick={() => action.onClick(r)}>{action.label}</button></td>}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function StatCard({ label, value, note, icon, tone }: { label: string; value: ReactNode; note: string; icon: string; tone: string }) {
  return <article className="stat"><span className={`stat-icon ${tone}`}>{icon}</span><div><small>{label}</small><strong>{value}</strong><p>{note}</p></div></article>;
}
