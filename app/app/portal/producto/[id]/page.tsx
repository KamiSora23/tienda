"use client";
import { money } from "@/app/lib/format";
import { API } from "@/app/lib/api";
import { equivalence, initialQty, qtyIncrement, unitSymbol, type Presentation } from "@/app/lib/units";
import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { PortalFooter, PortalHeader, portalStyle, ProductImage, useCart, usePortalHome } from "../../shared";
import "../../portal.css";

export default function ProductDetail() {
  const { id } = useParams<{ id: string }>();
  const home = usePortalHome();
  const [cart, setCart] = useCart();
  const [p, setP] = useState<any>();
  const [error, setError] = useState("");
  const [image, setImage] = useState(0);
  const [choice, setChoice] = useState<number | "base">("base");
  const [qty, setQty] = useState(1);
  const [added, setAdded] = useState("");

  useEffect(() => {
    setP(undefined); setImage(0); setChoice("base");
    fetch(`${API}/portal/products/${id}`, { headers: { Accept: "application/json" } })
      .then(async (r) => { const b = await r.json(); if (!r.ok) throw Error(b.message); setP(b.data); setQty(initialQty(b.data.unit_type)); })
      .catch((e) => setError(e.message));
  }, [id]);

  const shopping = p?.portal?.mode !== "catalog";
  const presentation: Presentation | undefined = choice === "base" ? undefined : p?.presentations?.find((x: Presentation) => x.id === choice);
  const step = presentation ? 1 : qtyIncrement(p?.unit_type ?? "unit");
  const price = +(presentation?.sale_price ?? p?.sale_price ?? 0);
  // Lo que ya está en la bolsa también cuenta contra el stock.
  const inBag = cart.filter((x) => x.id === p?.id).reduce((s, x) => s + x.quantity * x.factor, 0);
  const baseQty = qty * +(presentation?.factor ?? 1);
  const enough = p && inBag + baseQty <= +p.stock;

  const add = () => {
    if (!p || !enough) return;
    const key = `${p.id}:${presentation?.id ?? "base"}`;
    setCart((c) => {
      const existing = c.find((x) => x.key === key);
      if (existing) return c.map((x) => (x.key === key ? { ...x, image: x.image ?? p.images?.[0]?.url ?? null, quantity: +(x.quantity + qty).toFixed(3), entry_mode: "quantity", line_total: +((x.quantity + qty) * price).toFixed(2) } : x));
      const image = p.images?.[0]?.url ?? null;
      return [...c, { id: p.id, name: p.name, category: p.category, brand: p.brand, unit_type: p.unit_type, stock: +p.stock, sku: p.sku, image, key, sale_price: price, factor: +(presentation?.factor ?? 1), presentation_id: presentation?.id, presentation_name: presentation?.name, quantity: qty, entry_mode: "quantity", line_total: +(qty * price).toFixed(2) }];
    });
    setAdded(`${presentation ? `${qty} × ${presentation.name}` : `${qty} ${unitSymbol(p.unit_type)}`} agregado a tu bolsa`);
  };

  return (
    <main className="portal" style={portalStyle(home)}>
      <PortalHeader home={home} cart={cart} />
      {error ? <section className="portal-copy"><h1>Producto no disponible</h1><p>Puede que se haya agotado o ya no esté a la venta.</p><a className="portal-back" href="/portal">← Volver al catálogo</a></section>
        : !p ? <section className="portal-product-detail" aria-busy><div className="detail-gallery skeleton" /><article className="skeleton"><i /><i /><i /></article></section>
        : <>
          <nav className="breadcrumb"><a href="/portal">Catálogo</a> › <span>{p.category}</span> › <b>{p.name}</b></nav>
          <section className="portal-product-detail">
            <div>
              <div className="detail-gallery"><ProductImage src={p.images?.[image]?.url} name={p.name} /></div>
              {p.images?.length > 1 && <div className="detail-thumbs">{p.images.map((x: any, i: number) => <button key={x.id} className={i === image ? "active" : ""} onClick={() => setImage(i)} aria-label={`Ver imagen ${i + 1}`}><img src={x.url} alt="" /></button>)}</div>}
            </div>
            <article>
              <small>{p.category}{p.brand ? ` · ${p.brand}` : ""}</small>
              <h1>{p.name}</h1>
              <p className="detail-sku">Código {p.sku}{p.barcode ? ` · Barras ${p.barcode}` : ""}</p>
              {p.promotions?.map((x: any) => <div className="detail-promo" key={x.id}>% {x.name}{x.type === "percentage" ? ` · ${+x.value}% de descuento` : ""}</div>)}
              <strong>{money(price)} <small>/ {presentation ? presentation.name.toLowerCase() : unitSymbol(p.unit_type)}</small></strong>
              {p.portal?.show_stock !== false && <p className={+p.stock <= 5 ? "stock-low" : "stock-line"}>{+p.stock > 0 ? `${+p.stock} ${unitSymbol(p.unit_type)} disponibles` : "Agotado por ahora"}</p>}

              {p.presentations?.length > 0 && (
                <div className="detail-options" role="radiogroup" aria-label="Presentación">
                  <button role="radio" aria-checked={choice === "base"} className={choice === "base" ? "active" : ""} onClick={() => { setChoice("base"); setQty(initialQty(p.unit_type)); }}><span>Por {unitSymbol(p.unit_type)}</span><b>{money(+p.sale_price)}</b></button>
                  {p.presentations.map((x: Presentation) => <button key={x.id} role="radio" aria-checked={choice === x.id} disabled={+x.factor > +p.stock} className={choice === x.id ? "active" : ""} onClick={() => { setChoice(x.id ?? "base"); setQty(1); }}><span>{x.name}<small>{equivalence(+x.factor, p.unit_type)}</small></span><b>{money(+x.sale_price)}</b></button>)}
                </div>
              )}

              {shopping && +p.stock > 0 && (
                <div className="detail-buy">
                  <div className="qty-stepper large"><button onClick={() => setQty((q) => Math.max(step, +(q - step).toFixed(3)))} aria-label="Menos">−</button><span>{qty} {presentation ? "" : unitSymbol(p.unit_type)}</span><button onClick={() => setQty((q) => +(q + step).toFixed(3))} aria-label="Más">＋</button></div>
                  <button className="detail-add" onClick={add} disabled={!enough}>{enough ? `Agregar · ${money(qty * price)}` : "Sin stock suficiente"}</button>
                </div>
              )}
              {added && <div className="detail-added" role="status">✓ {added} <a href="/portal#bolsa">Ver bolsa</a></div>}
              <a className="portal-back" href="/portal">← Seguir comprando</a>
            </article>
          </section>
          {p.related?.length > 0 && (
            <section className="catalog-section">
              <header><div><small>SUGERENCIAS</small><h2>También puede interesarte</h2></div></header>
              <div className="portal-products">{p.related.map((x: any) => <article key={x.id}><a href={`/portal/producto/${x.id}`} className="product-visual"><ProductImage src={x.image} name={x.name} /></a><small>{x.category}</small><h3><a href={`/portal/producto/${x.id}`}>{x.name}</a></h3><footer><b>{money(+x.sale_price)} <small>/ {unitSymbol(x.unit_type)}</small></b><a className="add-button" href={`/portal/producto/${x.id}`} aria-label={`Ver ${x.name}`}>›</a></footer></article>)}</div>
            </section>
          )}
        </>}
      <PortalFooter home={home} />
    </main>
  );
}
