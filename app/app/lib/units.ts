// Unidades base de producto y presentaciones de venta (½ kg, docena, rollo…).
// Debe coincidir con ProductPresentationService::UNIT_TYPES en el backend.

export type Family = "count" | "weight" | "volume" | "length" | "area";
export type UnitType = "unit" | "pair" | "set" | "kg" | "g" | "t" | "lb" | "l" | "ml" | "gal" | "m3" | "m" | "cm" | "mm" | "m2";
// `exact` solo vive en el formulario: equivalencia sin redondear para que convertir varias veces
// la unidad base (kg → g → lb) no acumule errores. La API guarda `factor` con 3 decimales.
export type Presentation = { id?: number; name: string; factor: number; sale_price: number; barcode?: string | null; exact?: number };

type UnitDef = {
  label: string;
  symbol: string;
  family: Family;
  toFamilyBase: number; // equivalencia en la unidad de referencia de la familia (kg, L, m, m², unidad)
  initial: number;      // cantidad con la que entra al carrito
  increment: number;    // cuánto suma pulsar de nuevo el producto en el POS
};

export const UNITS: Record<UnitType, UnitDef> = {
  unit: { label: "Unidad", symbol: "und", family: "count", toFamilyBase: 1, initial: 1, increment: 1 },
  pair: { label: "Par", symbol: "par", family: "count", toFamilyBase: 1, initial: 1, increment: 1 },
  set:  { label: "Juego / kit", symbol: "jgo", family: "count", toFamilyBase: 1, initial: 1, increment: 1 },
  kg:   { label: "Kilogramo", symbol: "kg", family: "weight", toFamilyBase: 1, initial: 1, increment: 0.25 },
  g:    { label: "Gramo", symbol: "g", family: "weight", toFamilyBase: 0.001, initial: 100, increment: 50 },
  t:    { label: "Tonelada", symbol: "t", family: "weight", toFamilyBase: 1000, initial: 1, increment: 0.5 },
  lb:   { label: "Libra", symbol: "lb", family: "weight", toFamilyBase: 0.45359237, initial: 1, increment: 0.5 },
  l:    { label: "Litro", symbol: "L", family: "volume", toFamilyBase: 1, initial: 1, increment: 0.25 },
  ml:   { label: "Mililitro", symbol: "ml", family: "volume", toFamilyBase: 0.001, initial: 100, increment: 50 },
  gal:  { label: "Galón", symbol: "gal", family: "volume", toFamilyBase: 3.785411784, initial: 1, increment: 0.5 },
  m3:   { label: "Metro cúbico", symbol: "m³", family: "volume", toFamilyBase: 1000, initial: 1, increment: 0.5 },
  m:    { label: "Metro", symbol: "m", family: "length", toFamilyBase: 1, initial: 1, increment: 0.5 },
  cm:   { label: "Centímetro", symbol: "cm", family: "length", toFamilyBase: 0.01, initial: 10, increment: 10 },
  mm:   { label: "Milímetro", symbol: "mm", family: "length", toFamilyBase: 0.001, initial: 100, increment: 100 },
  m2:   { label: "Metro cuadrado", symbol: "m²", family: "area", toFamilyBase: 1, initial: 1, increment: 1 },
};

export const FAMILIES: Record<Family, string> = {
  count: "Conteo",
  weight: "Peso",
  volume: "Volumen",
  length: "Longitud",
  area: "Área",
};

const def = (type?: string) => UNITS[type as UnitType];
export const unitSymbol = (type?: string) => def(type)?.symbol || "und";
export const unitLabel = (type?: string) => def(type)?.label || "Unidad";
// Solo las unidades de conteo (und, par, juego) son enteras.
export const allowsDecimals = (type?: string) => !!def(type) && def(type).family !== "count";
export const qtyStep = (type?: string) => (allowsDecimals(type) ? 0.001 : 1);
export const qtyIncrement = (type?: string) => def(type)?.increment ?? 1;
export const initialQty = (type?: string) => def(type)?.initial ?? 1;
export const unitsByFamily = (allowed?: (string | undefined)[]) =>
  (Object.keys(FAMILIES) as Family[])
    .map((family) => ({
      family,
      label: FAMILIES[family],
      units: (Object.entries(UNITS) as [UnitType, UnitDef][]).filter(([code, u]) => u.family === family && (!allowed || allowed.includes(code))),
    }))
    .filter((g) => g.units.length > 0);

/** Convierte una cantidad de una unidad a otra de la misma familia (p. ej. 250 g → 0.25 kg). */
export const roundFactor = (n: number) => Math.round(n * 1000) / 1000;

export function convert(quantity: number, from: UnitType, to: UnitType) {
  const a = UNITS[from], b = UNITS[to];
  if (!a || !b || a.family !== b.family) return null;
  return (quantity * a.toFamilyBase) / b.toFamilyBase;
}

// Sugerencias de presentación por familia, expresadas en su propia unidad. Se convierten a la
// unidad base del producto: un producto en kg sugiere "250 g" = 0.25 kg; uno en g, "1 kg" = 1000 g.
const SUGGESTIONS: Record<Family, [string, number, UnitType][]> = {
  count: [["Par", 2, "unit"], ["Media docena", 6, "unit"], ["Docena", 12, "unit"], ["Pack x3", 3, "unit"], ["Pack x4", 4, "unit"], ["Caja x24", 24, "unit"], ["Jaba x30", 30, "unit"], ["Ciento", 100, "unit"], ["Millar", 1000, "unit"]],
  weight: [["100 g", 100, "g"], ["250 g", 250, "g"], ["½ kg", 0.5, "kg"], ["1 kg", 1, "kg"], ["1 lb", 1, "lb"], ["Saco 5 kg", 5, "kg"], ["Saco 25 kg", 25, "kg"], ["Saco 50 kg", 50, "kg"], ["Tonelada", 1, "t"]],
  volume: [["250 ml", 250, "ml"], ["500 ml", 500, "ml"], ["1 litro", 1, "l"], ["Galón", 1, "gal"], ["Bidón 20 L", 20, "l"], ["Cilindro 200 L", 200, "l"], ["1 m³", 1, "m3"]],
  length: [["50 cm", 50, "cm"], ["1 metro", 1, "m"], ["Rollo 25 m", 25, "m"], ["Rollo 50 m", 50, "m"], ["Rollo 100 m", 100, "m"]],
  area: [["Caja 1 m²", 1, "m2"], ["Caja 1.5 m²", 1.5, "m2"], ["Plancha 1.22×2.44 m", 2.9768, "m2"]],
};

// Límites que acepta la API: factor con 3 decimales, entre 0.001 y 100 000.
export function presentationPresets(type: UnitType): [string, number, number][] {
  const u = UNITS[type];
  if (!u) return [];
  return SUGGESTIONS[u.family]
    .map(([name, qty, unit]) => { const exact = convert(qty, unit, type) ?? 0; return [name, roundFactor(exact), exact] as [string, number, number]; })
    // La propia unidad base no es una presentación, y las conteo deben ser enteras.
    .filter(([, f]) => f >= 0.001 && f <= 100000 && Math.abs(f - 1) > 1e-9 && (u.family !== "count" || Number.isInteger(f)))
    // En un producto vendido por par, "Par" (2 pares) confunde: se omite.
    .filter(([name]) => !(type === "pair" && name === "Par"));
}

const trim = (n: number) => String(+n.toFixed(3));
export const equivalence = (factor: number, type?: string) => `${trim(factor)} ${unitSymbol(type)}`;

// Texto de una línea vendida: "2 × Docena (12 und)", "0.385 kg", "3 und".
export function quantityLabel(line: { quantity: number; presentation_name?: string | null; presentation_quantity?: number | null; presentation_factor?: number | null }, type?: string) {
  if (line.presentation_name) return `${trim(+(line.presentation_quantity ?? 0))} × ${line.presentation_name}${line.presentation_factor ? ` (${equivalence(+line.presentation_factor, type)})` : ""}`;
  return `${trim(+line.quantity)} ${unitSymbol(type)}`;
}
