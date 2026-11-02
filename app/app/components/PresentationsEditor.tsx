// Editor de presentaciones de venta dentro del formulario de producto.
// Muestra costo y margen de cada presentación a partir del costo promedio por unidad base.
import { money } from "@/app/lib/format";
import { internalEan13 } from "@/app/lib/barcode";
import { equivalence, presentationPresets, qtyStep, unitSymbol, type Presentation, type UnitType } from "@/app/lib/units";

type Props = { unitType: UnitType; unitCost: number; basePrice: number; rows: Presentation[]; onChange: (rows: Presentation[]) => void };

export function PresentationsEditor({ unitType, unitCost, basePrice, rows, onChange }: Props) {
  const update = (i: number, patch: Partial<Presentation>) => onChange(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));
  const add = (name = "", factor = 0, exact?: number) =>
    onChange([...rows, { name, factor, exact, sale_price: factor ? +(factor * basePrice).toFixed(2) : 0, barcode: "" }]);
  const used = new Set(rows.map((r) => r.name.trim().toLowerCase()));
  const symbol = unitSymbol(unitType);

  return (
    <section className="presentations-editor">
      <header>
        <div>
          <strong>Presentaciones de venta</strong>
          <small>Opcional. Formas de vender este producto además de por {symbol}: comparten el mismo stock.</small>
        </div>
        <button type="button" onClick={() => add()}>＋ Agregar</button>
      </header>
      <div className="presentation-presets">
        {presentationPresets(unitType).filter(([name]) => !used.has(name.toLowerCase())).map(([name, factor, exact]) => (
          <button type="button" key={name} onClick={() => add(name, factor, exact)}>{name}</button>
        ))}
      </div>
      {rows.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead><tr><th>Nombre</th><th>Equivale a</th><th>Precio</th><th>Costo</th><th>Margen</th><th>Código de barras</th><th /></tr></thead>
            <tbody>
              {rows.map((r, i) => {
                const cost = r.factor * unitCost, margin = r.sale_price - cost;
                const vsBase = r.factor * basePrice - r.sale_price;
                return (
                  <tr key={r.id ?? `new-${i}`}>
                    <td><input aria-label="Nombre de la presentación" value={r.name} maxLength={60} required onChange={(e) => update(i, { name: e.target.value })} /></td>
                    <td><label className="input-suffix"><input aria-label="Equivalencia" type="number" min={qtyStep(unitType)} step={qtyStep(unitType)} value={r.factor || ""} required onChange={(e) => update(i, { factor: +e.target.value, exact: undefined })} /><span>{symbol}</span></label></td>
                    <td>
                      <input aria-label="Precio de la presentación" type="number" min="0" step=".01" value={r.sale_price} required onChange={(e) => update(i, { sale_price: +e.target.value })} />
                      {vsBase > 0.005 && <small className="cell-sub">Ahorro {money(vsBase)} vs. {equivalence(r.factor, unitType)} sueltos</small>}
                    </td>
                    <td>{money(cost)}</td>
                    <td className={margin < 0 ? "negative" : ""}>{money(margin)}{r.sale_price > 0 && <small className="cell-sub">{((margin / r.sale_price) * 100).toFixed(0)}%</small>}</td>
                    <td><div className="barcode-cell"><input aria-label="Código de barras" value={r.barcode || ""} maxLength={64} onChange={(e) => update(i, { barcode: e.target.value })} /><button type="button" className="table-action" title="Generar código interno" onClick={() => update(i, { barcode: internalEan13() })}>Generar</button></div></td>
                    <td><button type="button" className="table-action" aria-label={`Quitar ${r.name}`} onClick={() => onChange(rows.filter((_, j) => j !== i))}>×</button></td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
