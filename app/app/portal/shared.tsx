"use client";
// Piezas compartidas del portal: datos de la tienda, bolsa guardada en el navegador, cabecera e imágenes.
import { API } from "@/app/lib/api";
import { normalizeAppearance, readableInk } from "@/app/lib/theme";
import type { Presentation } from "@/app/lib/units";
import { useEffect, useState, type CSSProperties } from "react";

export type PortalProduct = { id: number; name: string; category: string; brand?: string | null; unit_type: string; sale_price: number | string; stock: number; sku: string; image?: string | null; presentations?: Presentation[] };
export type CartItem = PortalProduct & { key: string; quantity: number; entry_mode: "quantity" | "amount"; line_total: number; factor: number; presentation_id?: number; presentation_name?: string };

// ---- Datos públicos de la tienda (una sola petición por página).
let homeRequest: Promise<any> | null = null;
export function usePortalHome() {
  const [home, setHome] = useState<any>(null);
  useEffect(() => {
    homeRequest ??= fetch(`${API}/portal`, { headers: { Accept: "application/json" } }).then((r) => r.json()).then((b) => b.data ?? {});
    homeRequest.then(setHome).catch(() => setHome({}));
  }, []);
  return home;
}

/** Colores de la empresa aplicados al portal. */
export function portalStyle(home: any): CSSProperties {
  const a = normalizeAppearance(home?.appearance);
  return { "--brand": a.primary, "--brand-ink": readableInk(a.primary), "--brand-dark": a.sidebar, "--brand-dark-ink": readableInk(a.sidebar) } as CSSProperties;
}

// ---- Bolsa: se guarda en el navegador para no perderla al recargar o al abrir un producto.
const CART_KEY = "deurstore_portal_cart";
const readCart = (): CartItem[] => { try { const v = JSON.parse(localStorage.getItem(CART_KEY) || "[]"); return Array.isArray(v) ? v : []; } catch { return []; } };
const writeCart = (items: CartItem[]) => { try { localStorage.setItem(CART_KEY, JSON.stringify(items)); } catch { /* modo privado */ } window.dispatchEvent(new Event("portal-cart")); };

export function useCart() {
  const [cart, setCartState] = useState<CartItem[]>([]);
  useEffect(() => {
    const sync = () => setCartState(readCart());
    sync();
    window.addEventListener("portal-cart", sync);
    window.addEventListener("storage", sync);
    return () => { window.removeEventListener("portal-cart", sync); window.removeEventListener("storage", sync); };
  }, []);
  const setCart = (next: CartItem[] | ((c: CartItem[]) => CartItem[])) => writeCart(typeof next === "function" ? next(readCart()) : next);
  return [cart, setCart] as const;
}

export const cartCount = (cart: CartItem[]) => cart.reduce((n, x) => n + (x.presentation_id || x.unit_type === "unit" ? x.quantity : 1), 0);

// ---- Estados de pedido.
export const ORDER_STATUS: Record<string, { label: string; step: number }> = {
  pending: { label: "Recibido", step: 0 }, preparing: { label: "En preparación", step: 1 }, packed: { label: "Empacado", step: 2 },
  ready: { label: "Listo para entrega", step: 3 }, delivered: { label: "Entregado", step: 4 }, cancelled: { label: "Cancelado", step: -1 },
};
export const ORDER_STEPS = ["Recibido", "En preparación", "Empacado", "Listo", "Entregado"];
export const statusLabel = (s?: string) => (s && ORDER_STATUS[s]?.label) || s || "—";

export function OrderTimeline({ status }: { status: string }) {
  const step = ORDER_STATUS[status]?.step ?? 0;
  if (step < 0) return <div className="order-cancelled">Este pedido fue cancelado.</div>;
  return <ol className="order-steps">{ORDER_STEPS.map((x, i) => <li key={x} className={i < step ? "done" : i === step ? "current" : ""}><i>{i < step ? "✓" : i + 1}</i><span>{x}</span></li>)}</ol>;
}

// ---- Imagen del producto con respaldo de iniciales si no tiene foto o no carga.
const initials = (name: string) => name.replace(/[^\p{L}\p{N} ]/gu, "").split(/\s+/).filter(Boolean).map((x) => x[0]).join("").slice(0, 2).toUpperCase();
export function ProductImage({ src, name, className = "" }: { src?: string | null; name: string; className?: string }) {
  const [failed, setFailed] = useState(false);
  return src && !failed
    ? <img className={`product-img ${className}`} src={src} alt={name} loading="lazy" onError={() => setFailed(true)} />
    : <span className={`product-img placeholder ${className}`} aria-hidden>{initials(name)}</span>;
}

// ---- Cabecera común.
export function PortalHeader({ home, cart, onCart, children }: { home: any; cart?: CartItem[]; onCart?: () => void; children?: React.ReactNode }) {
  const shopping = home?.portal?.mode !== "catalog";
  const name = home?.company?.trade_name || "Tienda";
  return (
    <header className="portal-header">
      <a className="portal-brand" href="/portal"><b>{name[0]}</b><span>{name}<small>{shopping ? "TIENDA ONLINE" : "CATÁLOGO Y STOCK"}</small></span></a>
      {children}
      <nav>
        <a href="/portal#seguimiento">Seguir pedido</a>
        <a href="/portal/cuenta">Mi cuenta</a>
        {shopping && cart && <button className="cart-button" onClick={onCart ?? (() => { location.href = "/portal#bolsa"; })} aria-label={`Bolsa con ${cartCount(cart)} productos`}>Bolsa <b key={cartCount(cart)}>{cartCount(cart)}</b></button>}
      </nav>
    </header>
  );
}

export function PortalFooter({ home }: { home: any }) {
  const name = home?.company?.trade_name || "Tienda";
  return (
    <footer className="portal-footer">
      <div className="portal-brand"><b>{name[0]}</b><span>{name}<small>{home?.company?.address || home?.portal?.delivery_zone || "PERÚ"}</small></span></div>
      <p>{[["nosotros", "Nosotros"], ["mision", "Misión"], ["vision", "Visión"], ["valores", "Valores"], ["contacto", "Contacto"], ["preguntas", "Preguntas frecuentes"], ["politicas", "Políticas"], ["terminos", "Términos"], ["privacidad", "Privacidad"]].map(([slug, label], i) => <span key={slug}>{i > 0 && " · "}<a href={`/portal/${slug}`}>{label}</a></span>)}</p>
      <small>© {new Date().getFullYear()} {home?.company?.business_name || name}</small>
    </footer>
  );
}
