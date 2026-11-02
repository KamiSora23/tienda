// Formatos y utilidades de navegador compartidas por todos los módulos.

// Crear un Intl.NumberFormat es costoso; se reutiliza una sola instancia.
const currency = new Intl.NumberFormat("es-PE", { style: "currency", currency: "PEN" });
export const money = (value: number) => currency.format(value || 0);

// Fechas en hora local. toISOString() usa UTC: en Perú (UTC-5), después de las
// 7 p. m. devolvía la fecha del día siguiente.
const pad = (n: number) => String(n).padStart(2, "0");
export const localDate = (d = new Date()) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
export const today = () => localDate();
// Valor para <input type="datetime-local"> (también en hora local).
export const localDateTime = (value?: string) => {
  if (!value) return "";
  const d = new Date(value);
  return `${localDate(d)}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};
export const currentPeriod = () => today().slice(0, 7);
export const monthStart = () => `${currentPeriod()}-01`;

export function saveBlob(blob: Blob, filename: string) {
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
  // Revocar en el mismo tick puede cancelar la descarga en algunos navegadores.
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

export function downloadCsv(rows: Record<string, unknown>[], name: string) {
  if (!rows.length) return;
  const keys = Object.keys(rows[0]);
  const cell = (v: unknown) => `"${String(v ?? "").replaceAll('"', '""')}"`;
  const csv = [keys.join(","), ...rows.map((r) => keys.map((k) => cell(r[k])).join(","))].join("\n");
  saveBlob(new Blob(["﻿" + csv], { type: "text/csv" }), `${name}.csv`);
}
