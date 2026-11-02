"use client";

import { money } from "@/app/lib/format";
import { API } from "@/app/lib/api";
import { allowsDecimals, equivalence, FAMILIES, initialQty, qtyIncrement, qtyStep, UNITS, unitSymbol, type Presentation, type UnitType } from "@/app/lib/units";
import { useEffect, useMemo, useRef, useState } from "react";
import { OrderTimeline, PortalFooter, PortalHeader, portalStyle, ProductImage, statusLabel, useCart, usePortalHome, type CartItem, type PortalProduct } from "./shared";
import { distanceKm, MapPicker, mapsLink, type LatLng } from "@/app/components/MapPicker";
import { HeroCarousel, ProductRail, type Slide } from "./carousel";
import "./portal.css";

const SORTS: [string, string][] = [["name", "Nombre (A-Z)"], ["price_asc", "Precio: menor a mayor"], ["price_desc", "Precio: mayor a menor"], ["newest", "Novedades"]];
const customerToken = () => sessionStorage.getItem("deurstore_customer_token") || "";

export default function Portal() {
  const home = usePortalHome();
  const [cart, setCart] = useCart();
  const [products, setProducts] = useState<PortalProduct[]>([]);
  const [page, setPage] = useState({ current: 1, last: 1, total: 0 });
  const [loading, setLoading] = useState(true);
  const [filters, setFilters] = useState({ search: "", category: "", brand: "", sort: "name" });
  const [account, setAccount] = useState<any>(null);
  const [favorites, setFavorites] = useState<number[]>([]);
  const [featured, setFeatured] = useState<PortalProduct[]>([]);
  const [picking, setPicking] = useState<PortalProduct | null>(null);
  const [drawer, setDrawer] = useState(false);
  const [trackOpen, setTrackOpen] = useState(false);
  const [toast, setToast] = useState("");
  const toastTimer = useRef<ReturnType<typeof setTimeout>>(undefined);

  const shopping = home?.portal?.mode !== "catalog";
  const showStock = home?.portal?.show_stock !== false;

  const notify = (text: string) => { setToast(text); clearTimeout(toastTimer.current); toastTimer.current = setTimeout(() => setToast(""), 2600); };

  // Catálogo: búsqueda con espera de 350 ms para no consultar en cada tecla.
  const load = async (pageNumber = 1, f = filters) => {
    setLoading(true);
    const qs = new URLSearchParams({ page: String(pageNumber), per_page: "24", search: f.search, category: f.category, brand: f.brand, sort: f.sort });
    try {
      const body = await fetch(`${API}/portal/catalog?${qs}`, { headers: { Accept: "application/json" } }).then((r) => r.json());
      const data = body.data ?? {};
      setProducts((prev) => (pageNumber === 1 ? data.data ?? [] : [...prev, ...(data.data ?? [])]));
      setPage({ current: data.current_page ?? 1, last: data.last_page ?? 1, total: data.total ?? 0 });
    } finally { setLoading(false); }
  };
  useEffect(() => { const t = setTimeout(() => load(1, filters), filters.search ? 350 : 0); return () => clearTimeout(t); }, [filters]); // eslint-disable-line react-hooks/exhaustive-deps
  const setFilter = (patch: Partial<typeof filters>) => setFilters((f) => ({ ...f, ...patch }));

  useEffect(() => {
    fetch(`${API}/portal/catalog?featured=1&per_page=12`, { headers: { Accept: "application/json" } }).then((r) => r.json()).then((b) => setFeatured(b.data?.data ?? [])).catch(() => {});
    const t = customerToken();
    if (t) fetch(`${API}/portal/account`, { headers: { Authorization: `Bearer ${t}`, Accept: "application/json" } }).then((r) => (r.ok ? r.json() : null)).then((b) => { if (b?.data) { setAccount(b.data); setFavorites(b.data.favorite_ids || []); } });
    // Enlaces desde otras páginas del portal: /portal#bolsa y /portal#seguimiento.
    const openFromHash = () => { if (location.hash === "#bolsa") setDrawer(true); if (location.hash === "#seguimiento") setTrackOpen(true); };
    openFromHash(); window.addEventListener("hashchange", openFromHash);
    return () => window.removeEventListener("hashchange", openFromHash);
  }, []);

  const total = useMemo(() => cart.reduce((s, x) => s + x.line_total, 0), [cart]);

  const favorite = async (id: number) => {
    const t = customerToken();
    if (!t) return notify("Inicia sesión en Mi cuenta para guardar favoritos.");
    const r = await fetch(`${API}/portal/account/favorites/${id}`, { method: "POST", headers: { Authorization: `Bearer ${t}`, Accept: "application/json" } });
    const b = await r.json();
    if (r.ok) { setFavorites((f) => (b.data.active ? [...f, id] : f.filter((x) => x !== id))); notify(b.data.active ? "Guardado en favoritos" : "Quitado de favoritos"); }
  };

  const add = (p: PortalProduct, pr?: Presentation) => {
    setPicking(null);
    const key = `${p.id}:${pr?.id ?? "base"}`, price = +(pr?.sale_price ?? p.sale_price), first = pr ? 1 : initialQty(p.unit_type);
    const existing = cart.find((x) => x.key === key);
    if (existing) return changeQty(existing, +(existing.quantity + (pr ? 1 : qtyIncrement(p.unit_type))).toFixed(3));
    if ((pr ? +pr.factor : first) > +p.stock) return notify(`No hay stock suficiente de ${p.name}.`);
    setCart((c) => [...c, { ...p, key, sale_price: price, factor: +(pr?.factor ?? 1), presentation_id: pr?.id, presentation_name: pr?.name, quantity: first, entry_mode: "quantity", line_total: +(price * first).toFixed(2) }]);
    notify(`${pr ? `${pr.name} de ` : ""}${p.name} agregado a tu bolsa`);
  };
  const choose = (p: PortalProduct) => (p.presentations?.length ? setPicking(p) : add(p));

  // Cantidad dentro del stock: en presentaciones se compara en unidades base (docena = 12).
  const changeQty = (item: CartItem, quantity: number) => {
    const min = item.presentation_id ? 1 : qtyStep(item.unit_type);
    if (quantity < min) return setCart((c) => c.filter((x) => x.key !== item.key));
    if (quantity * item.factor > +item.stock) return notify(`Solo quedan ${+item.stock} ${unitSymbol(item.unit_type)} de ${item.name}.`);
    setCart((c) => c.map((x) => (x.key === item.key ? { ...x, entry_mode: "quantity", quantity, line_total: +(quantity * +x.sale_price).toFixed(2) } : x)));
  };
  const inCart = (p: PortalProduct) => cart.find((x) => x.key === `${p.id}:base`);
  const card = (p: PortalProduct) => {
    const item = inCart(p), low = showStock && p.unit_type === "unit" && +p.stock <= 5;
    return (
      <article key={p.id}>
        <button className={`favorite-button ${favorites.includes(p.id) ? "active" : ""}`} onClick={() => favorite(p.id)} aria-label={favorites.includes(p.id) ? "Quitar de favoritos" : "Guardar en favoritos"} aria-pressed={favorites.includes(p.id)}>♥</button>
        <a href={`/portal/producto/${p.id}`} className="product-visual"><ProductImage src={p.image} name={p.name} />{low && <em className="stock-badge">¡Últimas {+p.stock}!</em>}</a>
        <small>{p.category}{p.brand ? ` · ${p.brand}` : ""}</small>
        <h3><a href={`/portal/producto/${p.id}`}>{p.name}</a></h3>
        {showStock && !low && <p className="stock-line">{+p.stock} {unitSymbol(p.unit_type)} disponibles</p>}
        {p.presentations?.length ? <p className="portal-presentations">También por {p.presentations.map((x) => x.name.toLowerCase()).join(", ")}</p> : null}
        <footer>
          <b>{money(+p.sale_price)} <small>/ {unitSymbol(p.unit_type)}</small></b>
          {shopping && (item && !p.presentations?.length
            ? <div className="qty-stepper"><button onClick={() => changeQty(item, +(item.quantity - qtyIncrement(p.unit_type)).toFixed(3))} aria-label="Quitar uno">−</button><span>{+item.quantity}</span><button onClick={() => changeQty(item, +(item.quantity + qtyIncrement(p.unit_type)).toFixed(3))} aria-label="Agregar uno">＋</button></div>
            : <button className="add-button" aria-label={`Agregar ${p.name}`} onClick={() => choose(p)}>＋</button>)}
        </footer>
      </article>
    );
  };
  // Al tocar una diapositiva: su producto, su categoría o el catálogo.
  const openSlide = (sl: Slide) => {
    if (sl.product) { location.href = `/portal/producto/${sl.product.id}`; return; }
    if (sl.category) setFilter({ category: sl.category });
    document.getElementById("catalogo")?.scrollIntoView({ behavior: "smooth" });
  };

  if (!home) return <main className="portal"><section className="portal-disabled"><p>Cargando tienda…</p></section></main>;

  return (
    <main className="portal" style={portalStyle(home)}>
      <PortalHeader home={home} cart={cart} onCart={() => setDrawer(true)}>
        <label className="portal-search">⌕<input value={filters.search} onChange={(e) => setFilter({ search: e.target.value })} placeholder="Busca por nombre, marca o código" aria-label="Buscar productos" />{filters.search && <button onClick={() => setFilter({ search: "" })} aria-label="Limpiar búsqueda">×</button>}</label>
      </PortalHeader>

      {home.banners?.length > 0 ? <HeroCarousel slides={home.banners} onOpen={openSlide} /> : <section className="portal-hero">
        <div>
          <small>COMPRA LOCAL, COMPRA FÁCIL</small>
          <h1>Todo para tu día,<br />más cerca de ti.</h1>
          <p>Productos esenciales con stock actualizado desde {home.company?.trade_name || "nuestra tienda"}{home.portal?.delivery_zone ? ` en ${home.portal.delivery_zone}` : ""}.</p>
          <div className="hero-actions">
            <button onClick={() => document.getElementById("catalogo")?.scrollIntoView({ behavior: "smooth" })}>Ver catálogo</button>
            <button className="ghost" onClick={() => setTrackOpen(true)}>Seguir mi pedido</button>
          </div>
        </div>
        <div className="hero-card">
          <span>{shopping ? "Entrega local" : "Consulta de stock"}</span>
          <b>{home.portal?.delivery_zone || home.company?.address || "Atención local"}</b>
          <small>{shopping ? `Reservamos tus productos por ${home.portal?.order_expiration_hours ?? 24} h mientras preparamos tu pedido.` : "Revisa la disponibilidad antes de visitarnos."}</small>
          {home.portal?.contact_phone && <a href={`tel:${home.portal.contact_phone}`}>☏ {home.portal.contact_phone}</a>}
        </div>
      </section>}

      <section className="benefits">
        <div><b>✓ Stock real</b><span>Disponibilidad al momento</span></div>
        <div><b>{shopping ? "▣ Pedido reservado" : "⌕ Consulta rápida"}</b><span>{shopping ? "Separamos tus productos" : "Sin compras en línea"}</span></div>
        <div><b>⌂ Atención local</b><span>{home.portal?.delivery_zone || "Cerca de ti"}</span></div>
      </section>

      {featured.length > 0 && <ProductRail eyebrow="SELECCIÓN DE LA TIENDA" title="Destacados">{featured.map((p) => card(p))}</ProductRail>}

      {home.promotions?.length > 0 && (
        <section className="portal-promotions">
          <small>PROMOCIONES VIGENTES</small>
          {home.promotions.map((x: any) => <article key={x.id}><strong>{x.name}</strong><span>{x.type === "percentage" ? `${+x.value}% de descuento` : x.type === "fixed" ? `S/ ${(+x.value).toFixed(2)} de descuento` : "Oferta especial"} · hasta el {new Date(x.ends_at).toLocaleDateString("es-PE")}</span></article>)}
        </section>
      )}

      <section id="catalogo" className="catalog-section">
        <header>
          <div><small>CATÁLOGO</small><h2>Productos disponibles</h2><p className="catalog-count">{loading && page.current === 1 ? "Buscando…" : `${page.total} producto${page.total === 1 ? "" : "s"}${filters.search ? ` para “${filters.search}”` : ""}`}</p></div>
          <select className="portal-sort" value={filters.sort} onChange={(e) => setFilter({ sort: e.target.value })} aria-label="Ordenar">{SORTS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>
        </header>
        <div className="portal-filters">
          <button className={!filters.category ? "active" : ""} onClick={() => setFilter({ category: "" })}>Todas</button>
          {home.categories?.map((x: any) => <button className={filters.category === x.name ? "active" : ""} key={x.id} onClick={() => setFilter({ category: x.name })}>{x.name}</button>)}
          {home.brands?.length > 0 && <select value={filters.brand} onChange={(e) => setFilter({ brand: e.target.value })} aria-label="Marca"><option value="">Todas las marcas</option>{home.brands.map((x: string) => <option key={x}>{x}</option>)}</select>}
        </div>

        <div className="portal-products">
          {products.map((p) => card(p))}
          {loading && Array.from({ length: page.current === 1 ? 8 : 4 }, (_, i) => <article key={`s${i}`} className="skeleton" aria-hidden><span /><i /><i /><i /></article>)}
        </div>
        {!loading && products.length === 0 && <div className="portal-empty"><b>No encontramos productos</b><p>Prueba con otra palabra o quita los filtros.</p><button onClick={() => setFilters({ search: "", category: "", brand: "", sort: filters.sort })}>Quitar filtros</button></div>}
        {!loading && page.current < page.last && <button className="load-more" onClick={() => load(page.current + 1)}>Ver más productos</button>}
      </section>

      <PortalFooter home={home} />

      {picking && (
        <div className="portal-overlay center" onMouseDown={() => setPicking(null)}>
          <section className="portal-picker" role="dialog" aria-label={`¿Cómo quieres ${picking.name}?`} onMouseDown={(e) => e.stopPropagation()}>
            <header><div><small>{picking.name}</small><h3>¿Cómo lo quieres?</h3></div><button onClick={() => setPicking(null)} aria-label="Cerrar">×</button></header>
            <button onClick={() => add(picking)}><b>Por {unitSymbol(picking.unit_type)}</b><small>Eliges la cantidad en tu bolsa</small><strong>{money(+picking.sale_price)} / {unitSymbol(picking.unit_type)}</strong></button>
            {picking.presentations!.map((x) => <button key={x.id} onClick={() => add(picking, x)} disabled={x.factor > +picking.stock}><b>{x.name}</b><small>{x.factor > +picking.stock ? "Sin stock suficiente" : equivalence(x.factor, picking.unit_type)}</small><strong>{money(+x.sale_price)}</strong></button>)}
          </section>
        </div>
      )}

      {shopping && drawer && <CartDrawer home={home} cart={cart} setCart={setCart} total={total} account={account} changeQty={changeQty} close={() => { setDrawer(false); if (location.hash) history.replaceState(null, "", "/portal"); }} trackOrder={() => { setDrawer(false); setTrackOpen(true); }} />}
      {trackOpen && <TrackingDialog close={() => { setTrackOpen(false); if (location.hash) history.replaceState(null, "", "/portal"); }} />}
      {toast && <div className="portal-toast" role="status">{toast}{shopping && cart.length > 0 && !drawer && <button onClick={() => setDrawer(true)}>Ver bolsa</button>}</div>}
    </main>
  );
}

function CartDrawer({ home, cart, setCart, total, account, changeQty, close, trackOrder }: { home: any; cart: CartItem[]; setCart: (f: (c: CartItem[]) => CartItem[]) => void; total: number; account: any; changeQty: (i: CartItem, q: number) => void; close: () => void; trackOrder: () => void }) {
  const portal = home?.portal ?? {};
  // Métodos de entrega que la tienda activó en Configuración.
  const methods = ([["delivery", portal.delivery_enabled !== false], ["pickup", portal.pickup_enabled !== false]] as const).filter(([, on]) => on).map(([m]) => m);
  const [method, setMethod] = useState<"delivery" | "pickup">(methods[0] ?? "delivery");
  const [error, setError] = useState("");
  const [sending, setSending] = useState(false);
  const [placed, setPlaced] = useState<{ number: string; total: number; phone: string; method: string } | null>(null);
  const addresses: any[] = account?.addresses ?? [];
  const defaultAddress = addresses.find((x) => x.is_default) ?? addresses[0];
  const fromSaved = (a: any) => ({ address: a ? [a.address, a.city].filter(Boolean).join(", ") : "", district: a?.district ?? "", reference: a?.reference ?? "", point: a?.lat != null ? { lat: +a.lat, lng: +a.lng } : null as LatLng | null });
  const [dest, setDest] = useState(() => fromSaved(defaultAddress));
  const [mapKey, setMapKey] = useState(0); // al elegir otra dirección guardada, el mapa se recentra
  const update = (key: string, patch: Partial<CartItem>) => setCart((c) => c.map((i) => (i.key === key ? { ...i, ...patch } : i)));
  const storePoint: LatLng | null = portal.store_lat != null ? { lat: +portal.store_lat, lng: +portal.store_lng } : null;
  const free = +portal.free_delivery_from > 0 && total >= +portal.free_delivery_from;
  const fee = method === "delivery" && !free ? +(portal.delivery_fee ?? 0) : 0;
  const missingForFree = +portal.free_delivery_from > 0 && !free ? +portal.free_delivery_from - total : 0;

  const order = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault(); setError("");
    if (method === "delivery" && !dest.address.trim()) return setError("Escribe la dirección de entrega o márcala en el mapa.");
    // Con zona de reparto, el punto en el mapa es obligatorio y debe quedar dentro (el servidor lo vuelve a comprobar).
    if (method === "delivery" && storePoint && +portal.delivery_radius_km > 0) {
      if (!dest.point) return setError("Marca en el mapa el punto de entrega.");
      if (distanceKm(storePoint, dest.point) > +portal.delivery_radius_km) return setError(`Esa dirección está fuera de nuestra zona de reparto (${+portal.delivery_radius_km} km). Puedes elegir recojo en tienda.`);
    }
    setSending(true);
    const f = new FormData(e.currentTarget), token = customerToken();
    try {
      const r = await fetch(`${API}/portal/orders`, {
        method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}) },
        body: JSON.stringify({
          fulfillment_type: method, customer_name: f.get("name"), customer_phone: f.get("phone"), customer_notes: f.get("notes") || null,
          ...(method === "delivery" ? { delivery_address: dest.address, delivery_district: dest.district || null, delivery_reference: dest.reference || null, delivery_lat: dest.point?.lat ?? null, delivery_lng: dest.point?.lng ?? null } : {}),
          items: cart.map((x) => ({ product_id: x.id, presentation_id: x.presentation_id || null, quantity: x.quantity, entry_mode: x.entry_mode, line_total: x.line_total })),
        }),
      });
      const b = await r.json();
      if (!r.ok) return setError(b.errors ? Object.values(b.errors).flat().join(" ") : b.message || "No se pudo registrar el pedido.");
      setPlaced({ number: b.data.number, total: +b.data.total, phone: String(f.get("phone")), method });
      try { sessionStorage.setItem("deurstore_last_order", JSON.stringify({ number: b.data.number, phone: f.get("phone") })); } catch { /* sin almacenamiento */ }
      setCart(() => []);
    } catch { setError("No se pudo conectar con la tienda. Intenta nuevamente."); } finally { setSending(false); }
  };

  return (
    <div className="portal-overlay" onMouseDown={close}>
      <aside className="cart-drawer" onMouseDown={(e) => e.stopPropagation()} aria-label="Bolsa de compra">
        <header><div><small>TU PEDIDO</small><h2>{placed ? "¡Pedido recibido!" : "Bolsa de compra"}</h2></div><button onClick={close} aria-label="Cerrar">×</button></header>
        {placed ? (
          <div className="order-success">
            <b>✓</b>
            <p>Tu pedido <strong>{placed.number}</strong> por <strong>{money(placed.total)}</strong> fue registrado. {placed.method === "pickup" ? <>Te avisaremos cuando esté listo para recoger{portal.pickup_address ? <> en <strong>{portal.pickup_address}</strong></> : null}.</> : "Lo llevaremos a la dirección indicada."} Guarda el código: lo necesitarás junto con tu teléfono para seguirlo.</p>
            <button onClick={() => navigator.clipboard?.writeText(placed.number)}>Copiar código</button>
            <button className="ghost" onClick={trackOrder}>Seguir mi pedido</button>
          </div>
        ) : cart.length === 0 ? (
          <div className="cart-empty"><b>Tu bolsa está vacía</b><p>Agrega productos desde el catálogo.</p><button onClick={close}>Ver productos</button></div>
        ) : (
          <>
            <div className="portal-cart">
              {cart.map((x) => {
                const sym = unitSymbol(x.unit_type), weighable = allowsDecimals(x.unit_type) && !x.presentation_id, step = x.presentation_id ? 1 : qtyIncrement(x.unit_type);
                return (
                  <div key={x.key} className="portal-cart-line">
                    <ProductImage src={x.image} name={x.name} className="thumb" />
                    <section>
                      <b>{x.name}</b>
                      <small>{x.presentation_id ? `${x.presentation_name} · ${equivalence(x.factor, x.unit_type)} · ${money(+x.sale_price)}` : `${money(+x.sale_price)} / ${sym}`}</small>
                      <div className="cart-line-controls">
                        {weighable && <select value={x.entry_mode} onChange={(e) => update(x.key, { entry_mode: e.target.value as "quantity" | "amount" })} aria-label="Pedir por"><option value="quantity">{FAMILIES[UNITS[x.unit_type as UnitType]?.family] || "Cantidad"}</option><option value="amount">Importe (S/)</option></select>}
                        {x.entry_mode === "amount"
                          ? <label className="amount-input">S/ <input aria-label="Importe solicitado" type="number" step=".1" min=".1" value={x.line_total} onChange={(e) => { const v = Math.max(0.1, +e.target.value); const q = +(v / +x.sale_price).toFixed(3); if (q > +x.stock) return; update(x.key, { line_total: v, quantity: q }); }} /><small>≈ {x.quantity.toFixed(3)} {sym}</small></label>
                          : <div className="qty-stepper"><button onClick={() => changeQty(x, +(x.quantity - step).toFixed(3))} aria-label="Quitar">−</button><input aria-label="Cantidad" type="number" step={x.presentation_id ? 1 : qtyStep(x.unit_type)} min={x.presentation_id ? 1 : qtyStep(x.unit_type)} value={x.quantity} onChange={(e) => +e.target.value > 0 && changeQty(x, +e.target.value)} /><button onClick={() => changeQty(x, +(x.quantity + step).toFixed(3))} aria-label="Agregar">＋</button></div>}
                      </div>
                    </section>
                    <b className="line-total">{money(x.line_total)}</b>
                    <button className="remove" onClick={() => setCart((c) => c.filter((i) => i.key !== x.key))} aria-label={`Quitar ${x.name}`}>×</button>
                  </div>
                );
              })}
            </div>
            <form onSubmit={order}>
              {methods.length === 0 ? <div className="checkout-error">La tienda no está recibiendo pedidos en este momento.</div> : <>
              <h3>¿Cómo quieres recibirlo?</h3>
              <div className="fulfillment-options" role="radiogroup" aria-label="Tipo de entrega">
                {methods.includes("delivery") && <button type="button" role="radio" aria-checked={method === "delivery"} className={method === "delivery" ? "active" : ""} onClick={() => setMethod("delivery")}><b>🛵 Delivery</b><small>{free ? "¡Envío gratis!" : +portal.delivery_fee > 0 ? `Envío ${money(+portal.delivery_fee)}` : "Sin costo de envío"}</small></button>}
                {methods.includes("pickup") && <button type="button" role="radio" aria-checked={method === "pickup"} className={method === "pickup" ? "active" : ""} onClick={() => setMethod("pickup")}><b>🏪 Recojo en tienda</b><small>Gratis{portal.pickup_hours ? ` · ${portal.pickup_hours}` : ""}</small></button>}
              </div>
              {method === "delivery" && missingForFree > 0 && <p className="free-hint">Agrega {money(missingForFree)} más y el envío es gratis.</p>}

              <h3>Tus datos</h3>
              {account && <p className="checkout-hint">Usamos los datos de tu cuenta. Puedes cambiarlos para este pedido.</p>}
              <input name="name" placeholder="Nombre de quien recibe" defaultValue={account?.user?.name ?? ""} required maxLength={120} autoComplete="name" />
              <input name="phone" type="tel" placeholder="Teléfono (para coordinar)" defaultValue={account?.user?.phone ?? ""} required pattern="[0-9+ ()\-]{6,20}" title="Solo números, entre 6 y 20 dígitos" autoComplete="tel" />

              {method === "delivery" ? <>
                <h3>Dirección de entrega</h3>
                {addresses.length > 0 && <select onChange={(e) => { const a = addresses.find((x) => String(x.id) === e.target.value); setDest(fromSaved(a)); setMapKey((k) => k + 1); }} defaultValue={defaultAddress?.id} aria-label="Dirección guardada">{addresses.map((a) => <option key={a.id} value={a.id}>{a.label} — {a.address}</option>)}<option value="">Otra dirección</option></select>}
                <p className="checkout-hint">Repartimos en {portal.delivery_city || "la ciudad"}{+portal.delivery_radius_km > 0 ? `, hasta ${+portal.delivery_radius_km} km de la tienda` : ""}. Busca tu calle o toca el mapa.</p>
                <MapPicker key={mapKey} hint="Toca el mapa para marcar el punto exacto de entrega." city={portal.delivery_city} radiusKm={+portal.delivery_radius_km || 0} value={dest.point} center={storePoint} onChange={(point, found) => setDest((d) => ({ ...d, point, address: found && !d.address.trim() ? found : d.address }))} />
                <input placeholder="Dirección (calle, número, dpto.)" value={dest.address} onChange={(e) => setDest((d) => ({ ...d, address: e.target.value }))} required maxLength={500} autoComplete="street-address" aria-label="Dirección" />
                <div className="checkout-row">
                  <input placeholder="Distrito / urbanización" value={dest.district} onChange={(e) => setDest((d) => ({ ...d, district: e.target.value }))} maxLength={120} aria-label="Distrito" />
                  <input placeholder="Referencia (ej.: frente al parque)" value={dest.reference} onChange={(e) => setDest((d) => ({ ...d, reference: e.target.value }))} maxLength={255} aria-label="Referencia" />
                </div>
              </> : (
                <div className="pickup-card">
                  <b>Recoges en</b>
                  <p>{portal.pickup_address || home?.company?.address || "Nuestra tienda"}</p>
                  {portal.pickup_hours && <small>Horario: {portal.pickup_hours}</small>}
                  {mapsLink(portal.store_lat, portal.store_lng) && <a href={mapsLink(portal.store_lat, portal.store_lng)!} target="_blank" rel="noreferrer">Ver en el mapa ↗</a>}
                  <small>Te avisaremos cuando tu pedido esté listo. Lo separamos por {portal.order_expiration_hours ?? 24} h.</small>
                </div>
              )}
              <textarea name="notes" placeholder={method === "delivery" ? "Indicaciones para el repartidor (opcional)" : "Comentarios para la tienda (opcional)"} maxLength={500} />

              <div className="checkout-summary">
                <div><span>Productos</span><b>{money(total)}</b></div>
                {method === "delivery" && <div><span>Envío</span><b>{fee > 0 ? money(fee) : "Gratis"}</b></div>}
                <div className="grand"><span>Total</span><b>{money(total + fee)}</b></div>
              </div>
              {error && <div className="checkout-error" role="alert">{error}</div>}
              <button disabled={sending}>{sending ? "Enviando…" : `Confirmar pedido · ${money(total + fee)}`}</button>
              </>}
            </form>
          </>
        )}
      </aside>
    </div>
  );
}

function TrackingDialog({ close }: { close: () => void }) {
  const last = (() => { try { return JSON.parse(sessionStorage.getItem("deurstore_last_order") || "null"); } catch { return null; } })();
  const [number, setNumber] = useState(last?.number ?? "");
  const [phone, setPhone] = useState(last?.phone ?? "");
  const [order, setOrder] = useState<any>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const search = async (e?: React.FormEvent) => {
    e?.preventDefault(); setError("");
    if (!number || !phone) return setError("Ingresa el código y el teléfono del pedido.");
    setLoading(true);
    try {
      const r = await fetch(`${API}/portal/orders/${encodeURIComponent(number.trim())}?phone=${encodeURIComponent(phone.trim())}`, { headers: { Accept: "application/json" } });
      const b = await r.json();
      if (r.ok) setOrder(b.data); else setError("No encontramos un pedido con ese código y teléfono.");
    } finally { setLoading(false); }
  };

  return (
    <div className="portal-overlay center" onMouseDown={close}>
      <section className="tracking-card" onMouseDown={(e) => e.stopPropagation()} role="dialog" aria-label="Seguimiento de pedido">
        <small>SEGUIMIENTO</small>
        {order ? (
          <>
            <h2>{order.number}</h2>
            <OrderTimeline status={order.status} />
            <div className="tracking-state"><b>{statusLabel(order.status)}</b><span>Actualizado {new Date(order.updated_at).toLocaleString("es-PE")}</span></div>
            <ul className="tracking-items">{order.items?.map((x: any, i: number) => <li key={i}><span>{x.presentation ? `${+x.presentation.quantity} × ${x.presentation.name} de ${x.name}` : `${+x.quantity} × ${x.name}`}</span><b>{money(+x.total)}</b></li>)}</ul>
            <p className="tracking-delivery">{order.fulfillment_type === "pickup" ? <>🏪 <b>Recojo en tienda</b> · {order.delivery_address.replace(/^Recojo en tienda:?\s*/, "") || "en la tienda"}</> : <>🛵 <b>Delivery</b> a {order.delivery_address}{order.delivery_district ? `, ${order.delivery_district}` : ""}{order.delivery_reference ? ` (Ref.: ${order.delivery_reference})` : ""}{mapsLink(order.delivery_lat, order.delivery_lng) && <> · <a href={mapsLink(order.delivery_lat, order.delivery_lng)!} target="_blank" rel="noreferrer">ver mapa</a></>}</>}</p>{+order.delivery_fee > 0 && <p className="tracking-delivery">Envío: {money(+order.delivery_fee)}</p>}
            <strong>Total {money(+order.total)}</strong>
            {order.history?.length > 1 && <details><summary>Historial</summary><ul className="tracking-history">{order.history.map((h: any, i: number) => <li key={i}><b>{statusLabel(h.status)}</b> · {new Date(h.created_at).toLocaleString("es-PE")}{h.notes && <small>{h.notes}</small>}</li>)}</ul></details>}
            <div className="tracking-actions"><button className="ghost" onClick={() => setOrder(null)}>Buscar otro</button><button onClick={close}>Cerrar</button></div>
          </>
        ) : (
          <form onSubmit={search}>
            <h2>Consulta tu pedido</h2>
            <p>Ingresa el código que recibiste al confirmar la compra y el teléfono que usaste.</p>
            <input className="tracking-input" value={number} onChange={(e) => setNumber(e.target.value.toUpperCase())} placeholder="WEB-000001" aria-label="Código del pedido" />
            <input className="tracking-input" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="Teléfono usado en el pedido" aria-label="Teléfono" />
            {error && <div className="checkout-error" role="alert">{error}</div>}
            <button disabled={loading}>{loading ? "Buscando…" : "Buscar pedido"}</button>
          </form>
        )}
      </section>
    </div>
  );
}
