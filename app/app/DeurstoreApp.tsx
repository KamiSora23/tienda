"use client";

import { askConfirm } from "@/app/components/confirm";
import { PlatformBar } from "@/app/PlatformBar";
import { appearanceStyle, DEFAULT_APPEARANCE, normalizeAppearance, type Appearance } from "@/app/lib/theme";
import { API, api as request } from "@/app/lib/api";
import { lazy, Suspense, useEffect, useRef, useState, type ComponentType } from "react";
// Cada módulo se descarga solo cuando se abre; antes el paquete inicial traía los ~25 módulos.
const lazyModule = <K extends string>(factory: () => Promise<Record<K, ComponentType<any>>>, name: K) =>
  lazy(() => factory().then((m) => ({ default: m[name] })));
const AdminModule = lazyModule(() => import("./AdminModules"), "AdminModule");
const RolesPermissionsModule = lazyModule(() => import("./AdminModules"), "RolesPermissionsModule");
const SalesModule = lazyModule(() => import("./SalesModule"), "SalesModule");
const BackupsModule = lazyModule(() => import("./SystemModules"), "BackupsModule");
const FilesModule = lazyModule(() => import("./SystemModules"), "FilesModule");
const HistoriesModule = lazyModule(() => import("./SystemModules"), "HistoriesModule");
const MaintenanceModule = lazyModule(() => import("./SystemModules"), "MaintenanceModule");
const ReportsCenter = lazyModule(() => import("./SystemModules"), "ReportsCenter");
const InventoryModule = lazyModule(() => import("./OperationalModules"), "InventoryModule");
const PosModule = lazyModule(() => import("./OperationalModules"), "PosModule");
const ProductModule = lazyModule(() => import("./OperationalModules"), "ProductModule");
const PurchaseModule = lazyModule(() => import("./OperationalModules"), "PurchaseModule");
const AiModule = lazyModule(() => import("./IntelligenceModules"), "AiModule");
const IntegrationsModule = lazyModule(() => import("./IntelligenceModules"), "IntegrationsModule");
const UnitsModule = lazyModule(() => import("./UnitsModule"), "UnitsModule");
const DashboardModule = lazyModule(() => import("./DashboardModule"), "DashboardModule");
const CrmModule = lazyModule(() => import("./CrmModule"), "CrmModule");
const ExpenseManagementModule = lazyModule(() => import("./ExpenseManagementModule"), "ExpenseManagementModule");
const WebOrdersModule = lazyModule(() => import("./WebOrdersModule"), "WebOrdersModule");
const PortalContentModule = lazyModule(() => import("./PortalContentModule"), "PortalContentModule");
const PlatformPanel = lazyModule(() => import("./PlatformPanel"), "PlatformPanel");
const NotificationsModule = lazyModule(() => import("./NotificationsModule"), "NotificationsModule");
const ConfigurationModule = lazyModule(() => import("./ConfigurationModule"), "ConfigurationModule");
const LocationsModule = lazyModule(() => import("./LocationsModule"), "LocationsModule");
const CashManagementModule = lazyModule(() => import("./CashManagementModule"), "CashManagementModule");
const PromotionsModule = lazyModule(() => import("./PromotionsModule"), "PromotionsModule");
const UsersModule = lazyModule(() => import("./UsersModule"), "UsersModule");
const AuditModule = lazyModule(() => import("./AuditModule"), "AuditModule");
const CompaniesModule = lazyModule(() => import("./CompaniesModule"), "CompaniesModule");

type Module = "Panel de plataforma" | "Dashboard" | "Punto de venta" | "Ventas" | "Productos" | "Inventario" | "Compras" | "Caja" | "Clientes" | "Proveedores" | "Gastos" | "Reportes" | "Historiales" | "Empresas" | "Sucursales" | "Usuarios" | "Roles y permisos" | "Categorías" | "Marcas" | "Unidades" | "Almacenes" | "Promociones" | "Auditoría" | "Notificaciones" | "Pedidos web" | "Portal web" | "IA" | "Integraciones" | "Configuración" | "Archivos" | "Respaldos" | "Mantenimiento";

const nav: { label: Module; icon: string }[] = [
  { label: "Dashboard", icon: "⌂" }, { label: "Punto de venta", icon: "▣" }, { label: "Ventas", icon: "↗" },
  { label: "Productos", icon: "◇" }, { label: "Inventario", icon: "▤" },
  { label: "Compras", icon: "↓" }, { label: "Caja", icon: "□" },
  { label: "Clientes", icon: "♙" }, { label: "Proveedores", icon: "♟" }, { label: "Gastos", icon: "↘" }, { label: "Reportes", icon: "◫" }, { label: "Historiales", icon: "↶" },
  { label: "Empresas", icon: "▦" }, { label: "Sucursales", icon: "⌂" }, { label: "Usuarios", icon: "♟" },
  { label: "Roles y permisos", icon: "◆" }, { label: "Categorías", icon: "≡" }, { label: "Marcas", icon: "◉" },
  { label: "Unidades", icon: "↔" }, { label: "Almacenes", icon: "▥" }, { label: "Promociones", icon: "%" },
  { label: "Auditoría", icon: "◎" }, { label: "Notificaciones", icon: "◇" }, { label: "Pedidos web", icon: "▧" }, { label: "Portal web", icon: "▭" },
  { label: "IA", icon: "✦" }, { label: "Integraciones", icon: "⇄" },
  { label: "Configuración", icon: "⚙" }, { label: "Archivos", icon: "▤" }, { label: "Respaldos", icon: "◫" }, { label: "Mantenimiento", icon: "⌘" },
];

// Permiso que necesita cada módulo del menú ("platform" = solo el superadministrador; null = todos).
const MODULE_PERMISSION: Partial<Record<Module, string | null>> = {
  "Dashboard": "reports.view", "Punto de venta": "sales.create", "Ventas": "sales.view", "Productos": "products.view", "Inventario": "inventory.view",
  "Compras": "purchases.view", "Caja": "cash.view", "Clientes": "customers.view", "Proveedores": "suppliers.view", "Gastos": "expenses.view",
  "Reportes": "reports.view", "Historiales": "audit.view", "Empresas": "companies.view", "Sucursales": "branches.view", "Usuarios": "users.view",
  "Roles y permisos": "roles.view", "Categorías": "categories.view", "Marcas": "brands.view", "Unidades": "units.view", "Almacenes": "warehouses.view",
  "Promociones": "promotions.view", "Auditoría": "audit.view", "Notificaciones": "notifications.view", "Pedidos web": "orders.view", "Portal web": "portal.view", "IA": "ai.view",
  "Integraciones": "integrations.view", "Configuración": null, "Archivos": "files.view", "Respaldos": "platform", "Mantenimiento": "platform", "Panel de plataforma": "platform",
};
const canOpen = (me: any, module: Module) => { const need = MODULE_PERMISSION[module]; if (need === null || need === undefined) return true; if (me?.is_platform) return true; return need !== "platform" && !!me?.permissions?.includes(need); };

// Menú del superadministrador: lo suyo, separado de lo que opera dentro de cada empresa.
const PLATFORM_NAV: { label: Module; icon: string }[] = [{ label: "Panel de plataforma", icon: "◈" }, { label: "Empresas", icon: "▦" }, { label: "Respaldos", icon: "◫" }, { label: "Mantenimiento", icon: "⌘" }];
const inPlatformNav = (me: any, label: Module) => !!me?.is_platform && PLATFORM_NAV.some((x) => x.label === label);

const ROLE_LABELS: Record<string, string> = { platform_admin: "Superadministrador", admin: "Administrador", manager: "Gerente", cashier: "Cajero", seller: "Vendedor", warehouse: "Almacén" };
const initials = (name?: string) => (name || "").split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join("").toUpperCase() || "·";


export function DeurstoreApp() {
  const [token, setToken] = useState("");
  const [loginError, setLoginError] = useState("");
  const [twoFactorEmail, setTwoFactorEmail] = useState("");
  const [devCode, setDevCode] = useState(""); // solo en desarrollo local sin correo
  const [resendIn, setResendIn] = useState(0);
  const [twoFactorNote, setTwoFactorNote] = useState("");
  const [summary, setSummary] = useState<any>(null);
  const [active, setActive] = useState<Module>("Dashboard");
  const [toast, setToast] = useState("");
  const [mobileNav, setMobileNav] = useState(false);
  const [contextOpen,setContextOpen]=useState(false);
  const [contextData,setContextData]=useState<any>({companies:[],branches:[],cash:[]});
  const [contextCompany,setContextCompany]=useState<number|null>(null);
  const [me,setMe]=useState<any>(null);
  const landed = useRef(false); // el superadministrador entra primero a su panel
  // Colores de la empresa activa; "preview" muestra en vivo lo que se edita en Configuración › Apariencia.
  const [appearance,setAppearance]=useState<Appearance>(DEFAULT_APPEARANCE);
  const [preview,setPreview]=useState<Appearance|null>(null);
  const shown=preview??appearance;
  const [globalSearch,setGlobalSearch]=useState("");
  const [searchResults,setSearchResults]=useState<any[]>([]);
  const [searchOpen,setSearchOpen]=useState(false);
  const [helpOpen,setHelpOpen]=useState(false);

  useEffect(() => setToken(sessionStorage.getItem("deurstore_token") || ""), []);
  useEffect(()=>{const handler=(e:KeyboardEvent)=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==="k"){e.preventDefault();document.querySelector<HTMLInputElement>('[aria-label="Búsqueda global"]')?.focus()}};window.addEventListener("keydown",handler);return()=>window.removeEventListener("keydown",handler)},[]);
  useEffect(()=>{if(!token||globalSearch.trim().length<2){setSearchResults([]);return}const timer=setTimeout(()=>request(`/global-search?q=${encodeURIComponent(globalSearch.trim())}`,token).then(x=>{setSearchResults(x);setSearchOpen(true)}).catch(e=>showToast(e.message)),250);return()=>clearTimeout(timer)},[globalSearch,token]);
  const showToast = (message: string) => { setToast(message); setTimeout(() => setToast(""), 2600); };
  const refresh = async () => {
    // Cada recurso se carga por separado: un 403 en un módulo sin permiso no debe dejar vacío el resto.
    const load = (path: string, fallback: any) => request(path, token).catch((e: any) => { if (e.status === 401) throw e; return fallback; });
    const [m,d] = await Promise.all([load("/me",null),load("/dashboard",{})]);
    setMe(m); setSummary(d); setAppearance(normalizeAppearance(m?.appearance));
    // Si el módulo abierto no está permitido (p. ej. un cajero sin reportes), se abre el primero disponible.
    // Se decide antes de actualizar: la función de setActive se ejecuta después, cuando landed ya cambió.
    const firstLoad = !landed.current;
    landed.current = true;
    setActive((current) => (m?.is_platform && firstLoad ? "Panel de plataforma" : m && !canOpen(m, current) ? nav.find((x) => canOpen(m, x.label))?.label ?? "Configuración" : current));
  };
  const endSession = (message = "") => { sessionStorage.removeItem("deurstore_token"); setToken(""); setMe(null); landed.current = false; setActive("Dashboard"); setLoginError(message); };
  const logout = async () => { if (!await askConfirm("¿Cerrar sesión?", { confirmLabel: "Cerrar sesión" })) return; request("/logout", token, { method: "POST" }).catch(() => {}); endSession(); };
  // eslint-disable-next-line react-hooks/exhaustive-deps -- recargar solo al cambiar la sesión
  useEffect(() => { if (token) refresh().catch((e) => e.status === 401 ? endSession(e.message) : showToast(e.message)); }, [token]);
  const openContext=async()=>{try{setContextData(await request("/context/options",token));setContextCompany(me?.company_id??null);setContextOpen(true)}catch(e:any){showToast(e.message)}};
  const enterCompany=async(companyId:number,branchId:number)=>{try{await request("/context",token,{method:"POST",body:JSON.stringify({company_id:companyId,branch_id:branchId,cash_session_id:null})});await refresh();setActive("Dashboard");const name=(await request("/me",token))?.company?.trade_name;showToast(`Entraste a ${name??"la empresa"} (solo lectura)`)}catch(x:any){showToast(x.message)}};
  const saveContext=async(e:React.FormEvent<HTMLFormElement>)=>{e.preventDefault();const d=Object.fromEntries(new FormData(e.currentTarget));try{await request("/context",token,{method:"POST",body:JSON.stringify({company_id:+d.company_id,branch_id:+d.branch_id,cash_session_id:d.cash_session_id&&+d.company_id===me?.company_id?+d.cash_session_id:null})});setContextOpen(false);const company=contextData.companies.find((x:any)=>x.id===+d.company_id),branch=contextData.branches.find((x:any)=>x.id===+d.branch_id);showToast(`Ahora trabajas en ${company?.trade_name??"la empresa"} · ${branch?.name??"sucursal"}`);await refresh()}catch(x:any){showToast(x.message)}};
  const login = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault(); const form=new FormData(event.currentTarget);
    setLoginError("");
    try { const data=await request("/login","",{method:"POST",body:JSON.stringify({email:form.get("email"),password:form.get("password")})}); if(data.two_factor_required){openChallenge(data);return} sessionStorage.setItem("deurstore_token",data.token); setToken(data.token); }
    catch(e:any){ setLoginError(e.message); }
  };
  const openChallenge=(data:any)=>{setTwoFactorEmail(data.email);setDevCode((prev)=>data.dev_code?`${data.mail_failed?"!":""}${data.dev_code}`:data.already_sent?prev:"");setResendIn(+data.resend_in||60);setTwoFactorNote(data.already_sent?"Ya te enviamos un código hace un momento: usa el último correo que recibiste.":"");setLoginError("")};
  const resendTwoFactor=async()=>{try{openChallenge(await request("/two-factor/resend","",{method:"POST",body:JSON.stringify({email:twoFactorEmail})}));setTwoFactorNote("Te enviamos un código nuevo. Los anteriores ya no sirven.")}catch(e:any){setLoginError(e.message)}};
  const cancelChallenge=()=>{setTwoFactorEmail("");setDevCode("");setTwoFactorNote("");setLoginError("")};
  const verifyTwoFactor=async(code:string)=>{try{const data=await request("/two-factor/verify","",{method:"POST",body:JSON.stringify({email:twoFactorEmail,code})});sessionStorage.setItem("deurstore_token",data.token);setToken(data.token)}catch(e:any){setLoginError(e.message)}};
  if (!token) return <Login onSubmit={login} error={loginError} twoFactorEmail={twoFactorEmail} devCode={devCode} verify={verifyTwoFactor} resendIn={resendIn} note={twoFactorNote} resend={resendTwoFactor} cancel={cancelChallenge}/>;

  return (
    <main className={`app-shell theme-${shown.mode==="dark"?"dark":"green"}`} style={appearanceStyle(shown)}>
      <aside className={`sidebar ${mobileNav ? "open" : ""}`}>
        <div className="brand"><span className="brand-mark">D</span><div><strong>deurstore</strong><small>ERP COMERCIAL</small></div></div>
        <button type="button" className="tenant" onClick={openContext} title="Cambiar empresa, sucursal o caja"><span>{initials(me?.company?.trade_name||me?.company?.business_name)}</span><div><strong>{me?.company?.trade_name||"—"}</strong><small>{me?.branch?.name||"Sin sucursal"}</small></div><b>⌄</b></button>
        <nav>
          {me?.is_platform && <><p>PLATAFORMA</p>{PLATFORM_NAV.map((item) => <button key={item.label} onClick={() => { setActive(item.label); setMobileNav(false); }} className={active === item.label ? "active" : ""}><i>{item.icon}</i>{item.label}</button>)}<p className="nav-company">EN {(me?.company?.trade_name || "LA EMPRESA").toUpperCase()}</p></>}
          {!me?.is_platform && <p>OPERACIÓN</p>}
          {nav.slice(0, 6).filter((item) => canOpen(me, item.label) && !inPlatformNav(me, item.label)).map((item) => <button key={item.label} onClick={() => { setActive(item.label); setMobileNav(false); }} className={active === item.label ? "active" : ""}><i>{item.icon}</i>{item.label}{item.label === "Inventario" && +summary?.critical_stock > 0 && <em title={`${summary.critical_stock} producto(s) con stock bajo o agotado. Ver detalle en Inventario › Alertas.`}>{summary.critical_stock}</em>}</button>)}
          <p>{me?.is_platform ? "GESTIÓN DE LA EMPRESA" : "GESTIÓN"}</p>
          {nav.slice(6).filter((item) => canOpen(me, item.label) && !inPlatformNav(me, item.label)).map((item) => <button key={item.label} onClick={() => { setActive(item.label); setMobileNav(false); }} className={active === item.label ? "active" : ""}><i>{item.icon}</i>{item.label}</button>)}
        </nav>
        <div className="sidebar-foot"><div className="user-card"><span>{initials(me?.name)}</span><div><strong>{me?.name||"—"}</strong><small>{ROLE_LABELS[me?.role]||me?.role||""}</small></div><button type="button" className="logout" onClick={logout} title="Cerrar sesión" aria-label="Cerrar sesión">⏻</button></div></div>
      </aside>
      {mobileNav && <div className="nav-backdrop" onClick={() => setMobileNav(false)} />}

      <section className="workspace">
        <header className="topbar">
          <button className="menu" onClick={() => setMobileNav(!mobileNav)}>☰</button>
          <div className="global-search"><span>⌕</span><input aria-label="Búsqueda global" value={globalSearch} onFocus={()=>setSearchOpen(true)} onChange={e=>setGlobalSearch(e.target.value)} placeholder="Buscar productos, ventas, clientes..." /><kbd>Ctrl K</kbd>{searchOpen&&globalSearch.trim().length>=2&&<div className="global-results">{searchResults.length?searchResults.map(x=><button key={`${x.type}-${x.id}`} onClick={()=>{setActive(x.module as Module);setGlobalSearch("");setSearchOpen(false)}}><span>{x.type==="product"?"▤":x.type==="customer"?"♙":"↗"}</span><div><strong>{x.title}</strong><small>{x.subtitle} · {x.module}</small></div></button>):<p>Sin coincidencias</p>}</div>}</div>
          <div className="top-actions"><button aria-label="Ayuda" title="Ayuda" onClick={()=>setHelpOpen(true)}>?</button><button aria-label="Notificaciones" title="Abrir notificaciones" onClick={()=>setActive("Notificaciones")}>♢<i /></button><span className="today">{new Intl.DateTimeFormat("es-PE",{weekday:"short",day:"2-digit",month:"short",year:"numeric"}).format(new Date())}</span></div>
        </header>
        <div className="content">
          <PlatformBar token={token} me={me} refresh={refresh} notify={showToast} global={active === "Panel de plataforma"} />
          {/* key: al cambiar de empresa o sucursal, el módulo abierto se recarga con los datos nuevos. */}
          <div key={`${me?.company_id ?? 0}:${me?.branch_id ?? 0}`} className="context-scope">
          <Suspense fallback={<p className="module-loading">Cargando módulo…</p>}>
          {active === "Dashboard" && <DashboardModule token={token} navigate={setActive} notify={showToast}/>}
          {active === "Punto de venta" && <PosModule token={token} notify={showToast} onChanged={refresh}/>}
          {active === "Ventas" && <SalesModule token={token} notify={showToast}/>}
          {active === "Productos" && <ProductModule token={token} notify={showToast} onChanged={refresh}/>}
          {active === "Inventario" && <InventoryModule token={token} notify={showToast}/>}
          {active === "Compras" && <PurchaseModule token={token} notify={showToast} onChanged={refresh}/>}
          {active === "Caja" && <CashManagementModule token={token} notify={showToast} onChanged={refresh}/>}
          {active === "Clientes" && <CrmModule type="customers" token={token} notify={showToast}/>}
          {active === "Proveedores" && <CrmModule type="suppliers" token={token} notify={showToast}/>}
          {active === "Gastos" && <ExpenseManagementModule token={token} notify={showToast} onChanged={refresh}/>}
          {active === "Reportes" && <ReportsCenter token={token} notify={showToast}/>}
          {active === "Historiales" && <HistoriesModule token={token} notify={showToast}/>}
          {active === "Configuración" && <><ConfigurationModule token={token} notify={showToast} canManage={!!me?.is_platform || !!me?.permissions?.includes("settings.view")} appearance={appearance} onPreview={setPreview} onAppearanceSaved={setAppearance}/></>}
          {active === "Archivos" && <FilesModule token={token} notify={showToast}/>}
          {active === "IA" && <AiModule token={token} notify={showToast}/>}
          {active === "Integraciones" && <IntegrationsModule token={token} notify={showToast}/>}
          {active === "Pedidos web" && <WebOrdersModule token={token} notify={showToast}/>}
          {active === "Portal web" && <PortalContentModule token={token} notify={showToast}/>}
          {active === "Panel de plataforma" && <PlatformPanel token={token} notify={showToast} me={me} enterCompany={enterCompany} openModule={setActive}/>}
          {active === "Notificaciones" && <NotificationsModule token={token} notify={showToast}/>}
          {active === "Sucursales" && <LocationsModule type="branches" token={token} notify={showToast}/>}
          {active === "Almacenes" && <LocationsModule type="warehouses" token={token} notify={showToast}/>}
          {active === "Respaldos" && <BackupsModule token={token} notify={showToast}/>}
          {active === "Mantenimiento" && <MaintenanceModule token={token} notify={showToast}/>}
          {active === "Roles y permisos" && <RolesPermissionsModule token={token} notify={showToast}/>}
          {active === "Promociones" && <PromotionsModule token={token} notify={showToast}/>}
          {active === "Usuarios" && <UsersModule token={token} notify={showToast}/>}
          {active === "Auditoría" && <AuditModule token={token} notify={showToast}/>}
          {active === "Empresas" && <CompaniesModule token={token} notify={showToast}/>}
          {["Categorías","Marcas"].includes(active) && <AdminModule module={active} token={token} notify={showToast}/>}
          {active === "Unidades" && <UnitsModule token={token} notify={showToast}/>}
          </Suspense>
          </div>
        </div>
      </section>
      {toast && <div className="toast"><b>✓</b>{toast}</div>}
      {helpOpen&&<div className="modal-backdrop" onMouseDown={()=>setHelpOpen(false)}><section className="form-modal help-modal" onMouseDown={e=>e.stopPropagation()}><header><div><small>CENTRO DE AYUDA</small><h2>¿Cómo podemos ayudarte?</h2></div><button aria-label="Cerrar ayuda" onClick={()=>setHelpOpen(false)}>×</button></header><div className="help-content"><article><b>Ctrl K</b><div><strong>Búsqueda global</strong><p>Encuentra productos por nombre, SKU o código; clientes por documento; y ventas por comprobante.</p></div></article><article><b>F10</b><div><strong>Punto de venta</strong><p>Registra una venta desde el módulo Punto de venta después de abrir una caja.</p></div></article><article><b>?</b><div><strong>Soporte operativo</strong><p>Usa Auditoría para revisar cambios y Notificaciones para consultar alertas del sistema.</p></div></article></div><footer><button className="primary" onClick={()=>setHelpOpen(false)}>Entendido</button></footer></section></div>}
      {contextOpen&&<div className="modal-backdrop" onMouseDown={()=>setContextOpen(false)}><form className="form-modal" onSubmit={saveContext} onMouseDown={e=>e.stopPropagation()}><header><div><small>CONTEXTO DE TRABAJO</small><h2>Empresa, sucursal y caja</h2></div><button type="button" onClick={()=>setContextOpen(false)}>×</button></header>{me?.is_platform&&<p className="context-note">Trabajas dentro de <b>una empresa a la vez</b>: productos, ventas, configuración, usuarios y reportes que veas serán solo de la empresa elegida. Al cambiar de empresa vuelves a <b>solo lectura</b>: para editar en la nueva empresa activa la edición con su propio motivo.</p>}<div className="form-grid"><label>Empresa<select name="company_id" value={contextCompany??""} onChange={e=>setContextCompany(+e.target.value)}>{contextData.companies.map((x:any)=><option key={x.id} value={x.id}>{x.trade_name}</option>)}</select></label><label>Sucursal<select name="branch_id" key={contextCompany??0} defaultValue={contextCompany===me?.company_id?me?.branch_id:undefined}>{contextData.branches.filter((x:any)=>x.company_id===contextCompany).map((x:any)=><option key={x.id} value={x.id}>{x.name}</option>)}</select></label><label className="wide">Caja abierta<select name="cash_session_id" defaultValue={me?.cash_session_id||""}><option value="">Sin caja seleccionada</option>{contextData.cash.map((x:any)=><option key={x.id} value={x.id}>Caja #{x.id} · abierta {new Date(x.opened_at).toLocaleString("es-PE")}</option>)}</select></label></div><footer><button type="button" onClick={()=>setContextOpen(false)}>Cancelar</button><button className="primary">Aplicar contexto</button></footer></form></div>}
    </main>
  );
}

type LoginProps = { onSubmit: (e: React.FormEvent<HTMLFormElement>) => Promise<void>; error: string; twoFactorEmail: string; devCode?: string; verify: (c: string) => Promise<void>; resendIn: number; note: string; resend: () => Promise<void>; cancel: () => void };

function Login({ onSubmit, error, twoFactorEmail, devCode = "", verify, resendIn, note, resend, cancel }: LoginProps) {
  const [forgot, setForgot] = useState(false);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false); // evita enviar dos veces (y recibir varios códigos)
  const [wait, setWait] = useState(resendIn);
  useEffect(() => setWait(resendIn), [resendIn, twoFactorEmail]);
  useEffect(() => { if (!twoFactorEmail || wait <= 0) return; const t = setTimeout(() => setWait((w) => w - 1), 1000); return () => clearTimeout(t); }, [wait, twoFactorEmail]);
  const run = async (fn: () => Promise<void>) => { if (busy) return; setBusy(true); try { await fn(); } finally { setBusy(false); } };
  const failed = devCode.startsWith("!"), localCode = devCode.replace("!", "");

  if (twoFactorEmail) return (
    <main className="login-page">
      {/* key: formulario nuevo, así el campo del código nunca hereda lo escrito en la contraseña. */}
      <form key="two-factor" className="login-card" onSubmit={(e) => { e.preventDefault(); const code = String(new FormData(e.currentTarget).get("code")); run(() => verify(code)); }}>
        <div className="brand login-brand"><span className="brand-mark">D</span><div><strong>deurstore</strong><small>SEGURIDAD</small></div></div>
        <h1>Verificación</h1>
        <p>Enviamos un código de seis dígitos a <b>{twoFactorEmail}</b>. Vence en 10 minutos.</p>
        {localCode && <div className="dev-code"><b>{failed ? "Entorno local · no se pudo enviar el correo" : "Entorno local · correo no configurado"}</b><span>Tu código es <strong>{localCode}</strong>. {failed ? "Revisa la contraseña de aplicación de Gmail con «php artisan mail:test»." : "En un servidor real llegará por correo."}</span></div>}
        {note && <div className="auth-message">{note}</div>}
        <label>Código<input key={twoFactorEmail + localCode} name="code" type="text" inputMode="numeric" autoComplete="one-time-code" pattern="\d{6}" title="Seis dígitos" maxLength={6} required autoFocus defaultValue={localCode} placeholder="000000" /></label>
        {error && <div className="login-error">{error}</div>}
        <button className="primary" disabled={busy}>{busy ? "Verificando…" : "Verificar identidad"}</button>
        <div className="login-secondary">
          <button type="button" className="link-button" disabled={busy || wait > 0} onClick={() => run(resend)}>{wait > 0 ? `Reenviar código en ${wait} s` : "Reenviar código"}</button>
          <button type="button" className="link-button" onClick={cancel}>Usar otra cuenta</button>
        </div>
      </form>
    </main>
  );

  return (
    <main className="login-page">
      <form key={forgot ? "forgot" : "login"} className="login-card" onSubmit={(e) => {
        e.preventDefault();
        if (!forgot) { const ev = e; run(() => onSubmit(ev)); return; }
        const email = String(new FormData(e.currentTarget).get("email"));
        run(async () => { const r = await fetch(`${API}/forgot-password`, { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ email }) }); const b = await r.json(); setMessage(b.message); });
      }}>
        <div className="brand login-brand"><span className="brand-mark">D</span><div><strong>deurstore</strong><small>ERP COMERCIAL</small></div></div>
        <h1>{forgot ? "Recuperar acceso" : "Bienvenido"}</h1>
        <p>{forgot ? "Recibirás un enlace de recuperación en tu correo." : "Ingresa para administrar tu tienda."}</p>
        <label>Correo<input name="email" type="email" autoComplete="username" required /></label>
        {!forgot && <label>Contraseña<input name="password" type="password" autoComplete="current-password" required /></label>}
        {(error || message) && <div className={error ? "login-error" : "auth-message"}>{error || message}</div>}
        <button className="primary" type="submit" disabled={busy}>{busy ? (forgot ? "Enviando…" : "Ingresando…") : forgot ? "Enviar instrucciones" : "Ingresar"}</button>
        <button className="link-button" type="button" onClick={() => { setForgot(!forgot); setMessage(""); }}>{forgot ? "Volver al inicio" : "Olvidé mi contraseña"}</button>
        <small>Acceso seguro Deurstore</small>
      </form>
    </main>
  );
}

