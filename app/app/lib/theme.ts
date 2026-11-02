// Apariencia de la aplicación por empresa: colores guardados en Configuración › Apariencia.
import type { CSSProperties } from "react";

export type Appearance = { mode: "light" | "dark"; primary: string; sidebar: string; background: string; panel: string };

export const DEFAULT_APPEARANCE: Appearance = { mode: "light", primary: "#168555", sidebar: "#12251d", background: "#f5f7f5", panel: "#ffffff" };

export const APPEARANCE_PRESETS: { name: string; colors: Omit<Appearance, "mode"> }[] = [
  { name: "Verde Deurstore", colors: { primary: "#168555", sidebar: "#12251d", background: "#f5f7f5", panel: "#ffffff" } },
  { name: "Azul océano", colors: { primary: "#1f6fd1", sidebar: "#13233f", background: "#f3f6fb", panel: "#ffffff" } },
  { name: "Morado", colors: { primary: "#7a4fd0", sidebar: "#241a3d", background: "#f6f4fb", panel: "#ffffff" } },
  { name: "Terracota", colors: { primary: "#c4532d", sidebar: "#2e1b14", background: "#faf5f2", panel: "#ffffff" } },
  { name: "Grafito", colors: { primary: "#3b4a5a", sidebar: "#1b1f24", background: "#f4f5f6", panel: "#ffffff" } },
  { name: "Vino", colors: { primary: "#a3284f", sidebar: "#2b1320", background: "#faf4f6", panel: "#ffffff" } },
];

const HEX = /^#[0-9a-f]{6}$/i;

/** Mezcla lo guardado con los valores por defecto y descarta colores mal formados. */
export function normalizeAppearance(value: any): Appearance {
  const merged = { ...DEFAULT_APPEARANCE } as any;
  for (const key of ["primary", "sidebar", "background", "panel"] as const) if (HEX.test(value?.[key] ?? "")) merged[key] = value[key];
  if (value?.mode === "dark") merged.mode = "dark";
  return merged;
}

// Luminancia relativa y contraste según WCAG.
const luminance = (hex: string) => {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
export const contrast = (a: string, b: string) => { const [x, y] = [luminance(a), luminance(b)].sort((m, n) => n - m); return (x + 0.05) / (y + 0.05); };

/** Texto blanco u oscuro, el que mejor se lea sobre el color dado. */
export const readableInk = (hex: string) => (contrast(hex, "#ffffff") >= contrast(hex, "#17211c") ? "#ffffff" : "#17211c");

/** Variables CSS que aplican la apariencia sobre .app-shell. En modo oscuro se respetan los fondos oscuros del tema. */
export function appearanceStyle(a: Appearance): CSSProperties {
  const vars: Record<string, string> = { "--green": a.primary, "--on-green": readableInk(a.primary), "--sidebar": a.sidebar, "--sidebar-ink": readableInk(a.sidebar) };
  if (a.mode === "light") Object.assign(vars, { "--paper": a.background, "--panel": a.panel });
  return vars as CSSProperties;
}
