"use client";
// Editor de colores de la empresa. Los cambios se ven en vivo en toda la app y solo quedan guardados al pulsar Guardar.
import { api } from "@/app/lib/api";
import { APPEARANCE_PRESETS, contrast, DEFAULT_APPEARANCE, type Appearance } from "@/app/lib/theme";
import { useEffect, useRef, useState } from "react";

type Props = { token: string; notify: (m: string) => void; current: Appearance; onPreview: (a: Appearance | null) => void; onSaved: (a: Appearance) => void };

const FIELDS: { key: keyof Omit<Appearance, "mode">; label: string; hint: string; lightOnly?: boolean }[] = [
  { key: "primary", label: "Color principal", hint: "Botones, pestañas activas, enlaces y realces." },
  { key: "sidebar", label: "Menú lateral", hint: "Fondo del menú de la izquierda." },
  { key: "background", label: "Fondo de la página", hint: "Detrás de los paneles.", lightOnly: true },
  { key: "panel", label: "Paneles y tarjetas", hint: "Tablas, formularios y tarjetas.", lightOnly: true },
];

const INK = "#17211c";

export function AppearanceSettings({ token, notify, current, onPreview, onSaved }: Props) {
  const [draft, setDraft] = useState<Appearance>(current);
  const [saving, setSaving] = useState(false);
  const saved = useRef(current);
  const dirty = JSON.stringify(draft) !== JSON.stringify(saved.current);

  useEffect(() => { onPreview(dirty ? draft : null); }, [draft]); // eslint-disable-line react-hooks/exhaustive-deps
  // Al salir sin guardar, la app vuelve a los colores guardados.
  useEffect(() => () => onPreview(null), []); // eslint-disable-line react-hooks/exhaustive-deps

  const set = (patch: Partial<Appearance>) => setDraft((d) => ({ ...d, ...patch }));
  const warnings = [
    contrast(draft.primary, draft.mode === "light" ? draft.panel : "#19231e") < 2.5 && "El color principal casi no se distingue de los paneles: los botones y enlaces se verán apagados.",
    draft.mode === "light" && contrast(draft.panel, INK) < 7 && "Los paneles son demasiado oscuros para el texto. Usa un tono claro o activa el modo oscuro.",
    draft.mode === "light" && contrast(draft.background, INK) < 7 && "El fondo es demasiado oscuro para el texto. Usa un tono claro o activa el modo oscuro.",
  ].filter(Boolean) as string[];

  const save = async () => {
    setSaving(true);
    try {
      await api("/admin/settings", token, { method: "POST", body: JSON.stringify({ settings: { appearance: draft } }) });
      saved.current = draft; onSaved(draft); onPreview(null); notify("Colores de la empresa guardados");
    } catch (e: any) { notify(e.message); } finally { setSaving(false); }
  };

  return (
    <section className="panel settings-form appearance-settings">
      <h2>Apariencia de la empresa</h2>
      <p className="appearance-intro">Estos colores se aplican a todos los usuarios de esta empresa. Cada empresa tiene los suyos: cambia de empresa en el selector superior para editar otra.</p>

      <h3>Paletas sugeridas</h3>
      <div className="appearance-presets">
        {APPEARANCE_PRESETS.map((p) => {
          const active = FIELDS.every((f) => draft[f.key].toLowerCase() === p.colors[f.key]);
          return (
            <button type="button" key={p.name} className={active ? "active" : ""} onClick={() => set(p.colors)} aria-pressed={active}>
              <span className="swatches">{[p.colors.sidebar, p.colors.primary, p.colors.background].map((c) => <i key={c} style={{ background: c }} />)}</span>
              {p.name}
            </button>
          );
        })}
      </div>

      <h3>Modo</h3>
      <div className="appearance-mode" role="radiogroup">
        {([["light", "☀ Claro"], ["dark", "☾ Oscuro"]] as const).map(([mode, label]) => (
          <button type="button" role="radio" aria-checked={draft.mode === mode} key={mode} className={draft.mode === mode ? "active" : ""} onClick={() => set({ mode })}>{label}</button>
        ))}
      </div>

      <h3>Colores personalizados</h3>
      <div className="appearance-colors">
        {FIELDS.map((f) => {
          const disabled = f.lightOnly && draft.mode === "dark";
          return (
            <label key={f.key} className={disabled ? "disabled" : ""}>
              <span>{f.label}<small>{disabled ? "En modo oscuro se usa el fondo oscuro del sistema." : f.hint}</small></span>
              <div>
                <input type="color" value={draft[f.key]} disabled={disabled} onChange={(e) => set({ [f.key]: e.target.value })} aria-label={f.label} />
                <input disabled={disabled} maxLength={7} spellCheck={false} onChange={(e) => { if (/^#[0-9a-f]{6}$/i.test(e.target.value)) set({ [f.key]: e.target.value.toLowerCase() }); }} onBlur={(e) => { e.target.value = draft[f.key]; }} key={draft[f.key]} defaultValue={draft[f.key]} />
              </div>
            </label>
          );
        })}
      </div>

      {warnings.length > 0 && <ul className="appearance-warnings">{warnings.map((w) => <li key={w}>⚠ {w}</li>)}</ul>}
      {dirty && <p className="appearance-preview-note">Vista previa activa: estás viendo los colores sin guardar en toda la aplicación.</p>}

      <footer>
        <button type="button" onClick={() => set(DEFAULT_APPEARANCE)}>Restablecer colores originales</button>
        <button type="button" disabled={!dirty} onClick={() => setDraft(saved.current)}>Descartar cambios</button>
        <button type="button" className="primary" disabled={!dirty || saving} onClick={save}>{saving ? "Guardando…" : "Guardar colores"}</button>
      </footer>
    </section>
  );
}
