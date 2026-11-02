"use client";
// Configuración › Correo: servidor SMTP propio de la empresa para comprobantes, códigos y avisos.
import { api } from "@/app/lib/api";
import { useEffect, useState } from "react";

type Settings = { enabled: boolean; host: string; port: number; encryption: "tls" | "ssl" | "none"; username: string; has_password: boolean; from_address: string; from_name: string; reply_to: string; last_test_at?: string | null; last_test_ok?: boolean | null; last_error?: string | null; platform_from?: string | null; presets: Record<string, [string, number, string]> };

const PRESET_LABELS: Record<string, string> = { gmail: "Gmail", outlook: "Outlook / Microsoft 365", yahoo: "Yahoo", zoho: "Zoho" };

// platform: correo general de la plataforma (panel del superadministrador) en vez del de la empresa.
export function MailSettings({ token, notify, platform = false }: { token: string; notify: (m: string) => void; platform?: boolean }) {
  const base = platform ? "/platform/mail" : "/mail-settings";
  const [s, setS] = useState<Settings | null>(null);
  const [password, setPassword] = useState("");
  const [testTo, setTestTo] = useState("");
  const [busy, setBusy] = useState<"" | "save" | "test">("");
  const [readOnly, setReadOnly] = useState(false); // superadministrador en solo lectura dentro de la empresa
  const [result, setResult] = useState<{ ok: boolean; text: string } | null>(null); // queda escrito en el formulario
  const blocked = (x: any) => { if (!platform && x?.status === 403) setReadOnly(true); setResult({ ok: false, text: x.message }); notify(x.message); };
  // Tras probar solo se actualiza el resultado de la prueba: recargar todo borraba lo escrito sin guardar.
  const refreshTest = () => api(base, token).then((d: Settings) => setS((cur) => (cur ? { ...cur, last_test_at: d.last_test_at, last_test_ok: d.last_test_ok, last_error: d.last_error } : d))).catch(() => {});

  const load = () => api(base, token).then(setS).catch((e) => notify(e.message));
  useEffect(() => { load(); }, [token]); // eslint-disable-line react-hooks/exhaustive-deps
  if (!s) return <section className="panel settings-form"><p>Cargando…</p></section>;

  const set = (patch: Partial<Settings>) => setS({ ...s, ...patch });
  const payload = () => ({ enabled: s.enabled, host: s.host, port: s.port, encryption: s.encryption, username: s.username, password: password || null, from_address: s.from_address, from_name: s.from_name, reply_to: s.reply_to || null });
  const preset = (key: string) => { const [host, port, encryption] = s.presets[key]; set({ host, port, encryption: encryption as Settings["encryption"], from_address: s.from_address || s.username, username: s.username || s.from_address }); };
  const isGmail = s.host.includes("gmail");

  const save = async (e: React.FormEvent) => {
    e.preventDefault(); setBusy("save");
    try { await api(base, token, { method: "PUT", body: JSON.stringify(payload()) }); const text = s.enabled ? "Guardado y activo: los correos saldrán desde " + s.from_address + "." : "Guardado, pero desactivado: marca «" + (platform ? "Usar esta configuración" : "Usar el correo propio de la empresa") + "» y vuelve a guardar para usarlo."; setResult({ ok: true, text }); notify("Correo guardado"); setPassword(""); await load(); } catch (x: any) { blocked(x); } finally { setBusy(""); }
  };
  const test = async () => {
    setBusy("test");
    try { await api(`${base}/test`, token, { method: "POST", body: JSON.stringify({ ...payload(), to: testTo || null }) }); setResult({ ok: true, text: "Correo de prueba enviado. Revisa la bandeja (y spam). Si llegó, pulsa «Guardar correo»: la prueba no guarda los datos." }); notify("Correo de prueba enviado"); await refreshTest(); } catch (x: any) { blocked(x); await refreshTest(); } finally { setBusy(""); }
  };

  return (
    <form className="panel settings-form mail-settings" onSubmit={save}>
      <h2>{platform ? "Correo de la plataforma" : "Correo de la empresa"}</h2>
      {readOnly && <div className="mail-tip">Este es el correo de <b>esta empresa</b> y estás en solo lectura. Si quieres configurar el correo general con el que llegan tus códigos de verificación, ve a <b>Panel de plataforma › Correo de la plataforma</b>. Para cambiar el correo de esta empresa, activa la edición en la franja de arriba.</div>}
      <p className="settings-help">{platform
        ? <>Correo general del sistema: lo usan los superadministradores y todas las empresas que no tienen correo propio (códigos de verificación, contraseñas olvidadas y comprobantes). {s.platform_from ? <>Mientras no lo actives aquí, se usa el de <code>backend/.env</code> (<b>{s.platform_from}</b>).</> : "Mientras no lo actives aquí, se usa el de backend/.env."}</>
        : <>Desde este correo se envían los comprobantes a tus clientes, los códigos de verificación y los enlaces para recuperar la contraseña de tus usuarios. {s.platform_from ? <>Mientras no lo actives, se usa el correo general de la plataforma (<b>{s.platform_from}</b>).</> : "Mientras no lo actives, se usa el correo general de la plataforma."}</>}</p>

      <label className={`mail-toggle ${s.enabled ? "on" : ""}`}><input type="checkbox" checked={s.enabled} onChange={(e) => set({ enabled: e.target.checked })} /><span><b>{platform ? "Usar esta configuración" : "Usar el correo propio de la empresa"}</b><small>{s.enabled ? "Los correos saldrán desde " + (s.from_address || "el remitente indicado") : platform ? "Desactivado: se usa el correo de backend/.env" : "Desactivado: se usa el correo de la plataforma"}</small></span></label>

      <h3>Proveedor</h3>
      <div className="mail-presets">{Object.keys(s.presets).map((k) => <button type="button" key={k} className={s.host === s.presets[k][0] ? "active" : ""} onClick={() => preset(k)}>{PRESET_LABELS[k] ?? k}</button>)}<span>u otro servidor (escribe sus datos)</span></div>

      <div className="form-grid">
        <label>Servidor SMTP<input value={s.host} onChange={(e) => set({ host: e.target.value.trim() })} placeholder="smtp.gmail.com" required={s.enabled} /></label>
        <label>Puerto y cifrado<select value={`${s.port}|${s.encryption}`} onChange={(e) => { const [port, encryption] = e.target.value.split("|"); set({ port: +port, encryption: encryption as Settings["encryption"] }); }}>
          <option value="587|tls">587 · TLS (recomendado)</option><option value="465|ssl">465 · SSL</option><option value="25|none">25 · sin cifrado</option>
          {![587, 465, 25].includes(s.port) && <option value={`${s.port}|${s.encryption}`}>{s.port} · {s.encryption.toUpperCase()}</option>}
        </select></label>
        <label>Usuario (correo con el que inicias sesión)<input type="email" value={s.username} onChange={(e) => set({ username: e.target.value.trim() })} placeholder="ventas@tuempresa.com" autoComplete="off" /></label>
        <label>Contraseña{isGmail ? " de aplicación" : ""}<input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder={s.has_password ? "•••••••• (guardada; escribe solo para cambiarla)" : isGmail ? "16 letras de la contraseña de aplicación" : "Contraseña del correo"} autoComplete="new-password" /></label>
        <label>Remitente (correo)<input type="email" value={s.from_address} onChange={(e) => set({ from_address: e.target.value.trim() })} placeholder="ventas@tuempresa.com" required={s.enabled} /></label>
        <label>Nombre del remitente<input value={s.from_name} onChange={(e) => set({ from_name: e.target.value })} placeholder="Mi Tienda" maxLength={120} /></label>
        <label className="wide">Responder a (opcional)<input type="email" value={s.reply_to} onChange={(e) => set({ reply_to: e.target.value.trim() })} placeholder="Si quieres que las respuestas lleguen a otro correo" /></label>
      </div>
      {isGmail && <p className="mail-tip">Gmail no acepta tu contraseña normal: crea una <b>contraseña de aplicación</b> en <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noreferrer">myaccount.google.com/apppasswords</a> (requiere la verificación en dos pasos de Google) y pégala aquí. El remitente debe ser la misma cuenta.</p>}

      <h3>Probar</h3>
      <div className="mail-test">
        <input type="email" value={testTo} onChange={(e) => setTestTo(e.target.value)} placeholder="Enviar la prueba a… (por defecto, tu correo)" />
        <button type="button" onClick={test} disabled={!!busy || !s.host || !s.from_address}>{busy === "test" ? "Enviando…" : "Enviar correo de prueba"}</button>
      </div>
      {s.last_test_at && <p className={`mail-status ${s.last_test_ok ? "ok" : "bad"}`}>{s.last_test_ok ? "✓ Última prueba correcta" : `✗ Última prueba fallida: ${s.last_error ?? ""}`} · {new Date(s.last_test_at.replace(" ", "T")).toLocaleString("es-PE")}</p>}
      <p className="settings-help">La contraseña se guarda cifrada y nunca se vuelve a mostrar. Puedes probar antes de guardar.</p>
      {result && <p className={`mail-status ${result.ok ? "ok" : "bad"}`} role="status">{result.ok ? "✓ " : "✗ "}{result.text}</p>}
      <footer><button className="primary" disabled={!!busy}>{busy === "save" ? "Guardando…" : "Guardar correo"}</button></footer>
    </form>
  );
}
