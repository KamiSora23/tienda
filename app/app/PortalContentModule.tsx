"use client";
// Portal web: diapositivas del carrusel de portada y productos destacados.
import { askConfirm } from "@/app/components/confirm";
import { Modal } from "@/app/components/ui";
import { api } from "@/app/lib/api";
import { money } from "@/app/lib/format";
import { useEffect, useMemo, useState } from "react";

type Banner = { id: number; title: string; subtitle?: string; button_label?: string; image_url?: string | null; product_id?: number | null; product_name?: string; promotion_id?: number | null; promotion_name?: string; category?: string | null; sort_order: number; active: number | boolean; starts_at?: string | null; ends_at?: string | null };
type Product = { id: number; name: string; category: string; sale_price: string; image?: string | null; is_featured: number | boolean; featured_order: number };

const linkText = (b: Banner) => b.product_name ? `Producto: ${b.product_name}` : b.promotion_name ? `Promoción: ${b.promotion_name}` : b.category ? `Categoría: ${b.category}` : "Sin enlace (lleva al catálogo)";
const toInput = (v?: string | null) => (v ? v.replace(" ", "T").slice(0, 16) : "");

export function PortalContentModule({ token, notify }: { token: string; notify: (m: string) => void }) {
  const [data, setData] = useState<{ banners: Banner[]; products: Product[]; promotions: any[]; categories: string[] } | null>(null);
  const [editing, setEditing] = useState<Partial<Banner> | null>(null);
  const [featured, setFeatured] = useState<number[]>([]);
  const [search, setSearch] = useState("");
  const [savingFeatured, setSavingFeatured] = useState(false);

  const load = async () => {
    try {
      const d = await api("/portal-content", token);
      setData(d);
      setFeatured(d.products.filter((p: Product) => +p.is_featured).sort((a: Product, b: Product) => a.featured_order - b.featured_order).map((p: Product) => p.id));
    } catch (e: any) { notify(e.message); }
  };
  useEffect(() => { load(); }, [token]); // eslint-disable-line react-hooks/exhaustive-deps

  const byId = useMemo(() => new Map((data?.products ?? []).map((p) => [p.id, p])), [data]);
  const savedFeatured = useMemo(() => (data?.products ?? []).filter((p) => +p.is_featured).sort((a, b) => a.featured_order - b.featured_order).map((p) => p.id), [data]);
  const featuredDirty = JSON.stringify(savedFeatured) !== JSON.stringify(featured);
  const available = (data?.products ?? []).filter((p) => !featured.includes(p.id) && p.name.toLowerCase().includes(search.toLowerCase()));

  const move = <T,>(list: T[], i: number, d: number) => { const n = [...list]; const j = i + d; if (j < 0 || j >= n.length) return n; [n[i], n[j]] = [n[j], n[i]]; return n; };

  const reorder = async (i: number, d: number) => {
    const list = move(data!.banners, i, d);
    try {
      // Se guarda el nuevo orden de las dos diapositivas que cambiaron de lugar.
      await Promise.all(list.map((b, k) => (b.sort_order !== k + 1 ? saveBanner({ ...b, sort_order: k + 1 }, true) : null)));
      await load();
    } catch (e: any) { notify(e.message); }
  };

  const saveBanner = async (b: Partial<Banner>, quiet = false, file?: File | null, removeImage = false) => {
    const f = new FormData();
    for (const k of ["title", "subtitle", "button_label", "category", "starts_at", "ends_at"] as const) if (b[k]) f.append(k, String(b[k]));
    if (b.product_id) f.append("product_id", String(b.product_id));
    if (b.promotion_id) f.append("promotion_id", String(b.promotion_id));
    f.append("sort_order", String(b.sort_order ?? 0));
    f.append("active", b.active === false || b.active === 0 ? "0" : "1");
    if (file) f.append("image", file);
    if (removeImage) f.append("remove_image", "1");
    await api(`/portal-content/banners${b.id ? `/${b.id}` : ""}`, token, { method: "POST", body: f });
    if (!quiet) notify("Diapositiva guardada");
  };

  const toggle = async (b: Banner) => { try { await saveBanner({ ...b, active: !+b.active }, true); notify(+b.active ? "Diapositiva ocultada" : "Diapositiva visible"); load(); } catch (e: any) { notify(e.message); } };
  const remove = async (b: Banner) => {
    if (!await askConfirm(`¿Eliminar la diapositiva «${b.title}»?`, { confirmLabel: "Eliminar", danger: true })) return;
    try { await api(`/portal-content/banners/${b.id}`, token, { method: "DELETE" }); notify("Diapositiva eliminada"); load(); } catch (e: any) { notify(e.message); }
  };
  const saveFeatured = async () => {
    setSavingFeatured(true);
    try { await api("/portal-content/featured", token, { method: "PUT", body: JSON.stringify({ product_ids: featured }) }); notify("Destacados guardados"); await load(); } catch (e: any) { notify(e.message); } finally { setSavingFeatured(false); }
  };

  if (!data) return <section className="panel"><p className="empty-table">Cargando…</p></section>;

  return (
    <>
      <div className="page-head">
        <div><p>COMERCIO ELECTRÓNICO</p><h1>Portal web</h1><span>Carrusel de portada y productos destacados de la tienda en línea.</span></div>
        <div className="head-actions"><a className="link-button" href="/portal" target="_blank" rel="noreferrer">Ver portal ↗</a><button className="primary" onClick={() => setEditing({ active: true, sort_order: data.banners.length + 1 })}>＋ Nueva diapositiva</button></div>
      </div>

      <section className="panel portal-admin">
        <header className="inner-head"><div><h2>Carrusel de portada</h2><p>Se muestran en orden y pasan solas cada pocos segundos. {data.banners.length === 0 && "Mientras no crees ninguna, el portal muestra tus promociones activas."}</p></div></header>
        {data.banners.length === 0 ? <div className="empty-state"><b>Aún no hay diapositivas</b><p>Crea una para anunciar una promoción, un producto o una categoría.</p><button className="primary" onClick={() => setEditing({ active: true, sort_order: 1 })}>Crear la primera</button></div>
          : <div className="banner-list">{data.banners.map((b, i) => (
            <article key={b.id} className={+b.active ? "" : "off"}>
              <div className="banner-thumb" style={b.image_url ? { backgroundImage: `url(${b.image_url})` } : undefined}>{!b.image_url && <span>{b.title}</span>}</div>
              <div className="banner-info">
                <strong>{b.title}{!+b.active && <em>Oculta</em>}</strong>
                {b.subtitle && <small>{b.subtitle}</small>}
                <small>{linkText(b)}{b.starts_at || b.ends_at ? ` · ${b.starts_at ? `desde ${new Date(b.starts_at).toLocaleDateString("es-PE")}` : ""} ${b.ends_at ? `hasta ${new Date(b.ends_at).toLocaleDateString("es-PE")}` : ""}` : ""}</small>
              </div>
              <div className="row-actions">
                <button onClick={() => reorder(i, -1)} disabled={i === 0} aria-label="Subir">↑</button>
                <button onClick={() => reorder(i, 1)} disabled={i === data.banners.length - 1} aria-label="Bajar">↓</button>
                <button onClick={() => toggle(b)}>{+b.active ? "Ocultar" : "Mostrar"}</button>
                <button onClick={() => setEditing(b)}>Editar</button>
                <button onClick={() => remove(b)}>Eliminar</button>
              </div>
            </article>
          ))}</div>}
      </section>

      <section className="panel portal-admin">
        <header className="inner-head"><div><h2>Productos destacados</h2><p>Aparecen en el carrusel «Destacados» del portal, en este orden. Solo se muestran los que tienen stock.</p></div>{featuredDirty && <button className="primary" disabled={savingFeatured} onClick={saveFeatured}>{savingFeatured ? "Guardando…" : "Guardar destacados"}</button>}</header>
        <div className="featured-editor">
          <div>
            <h3>Destacados ({featured.length}/24)</h3>
            {featured.length === 0 ? <p className="empty-table">Elige productos de la lista de la derecha.</p> : <ol className="featured-list">{featured.map((id, i) => { const p = byId.get(id); return p && (
              <li key={id}><span className="thumb" style={p.image ? { backgroundImage: `url(${p.image})` } : undefined}>{!p.image && p.name[0]}</span><div><strong>{p.name}</strong><small>{p.category} · {money(+p.sale_price)}</small></div>
                <button onClick={() => setFeatured(move(featured, i, -1))} disabled={i === 0} aria-label="Subir">↑</button><button onClick={() => setFeatured(move(featured, i, 1))} disabled={i === featured.length - 1} aria-label="Bajar">↓</button><button onClick={() => setFeatured(featured.filter((x) => x !== id))} aria-label={`Quitar ${p.name}`}>✕</button></li>); })}</ol>}
          </div>
          <div>
            <h3>Agregar productos</h3>
            <input className="featured-search" placeholder="Buscar producto…" value={search} onChange={(e) => setSearch(e.target.value)} />
            <ul className="featured-list available">{available.slice(0, 40).map((p) => (
              <li key={p.id}><span className="thumb" style={p.image ? { backgroundImage: `url(${p.image})` } : undefined}>{!p.image && p.name[0]}</span><div><strong>{p.name}</strong><small>{p.category} · {money(+p.sale_price)}</small></div><button disabled={featured.length >= 24} onClick={() => setFeatured([...featured, p.id])}>＋ Destacar</button></li>
            ))}{available.length === 0 && <li className="empty-table">Sin resultados.</li>}</ul>
          </div>
        </div>
      </section>

      {editing && <BannerForm banner={editing} data={data} close={() => setEditing(null)} save={async (b, file, removeImage) => { await saveBanner(b, false, file, removeImage); setEditing(null); load(); }} notify={notify} />}
    </>
  );
}

function BannerForm({ banner, data, close, save, notify }: { banner: Partial<Banner>; data: { products: Product[]; promotions: any[]; categories: string[] }; close: () => void; save: (b: Partial<Banner>, file: File | null, removeImage: boolean) => Promise<void>; notify: (m: string) => void }) {
  const [b, setB] = useState<Partial<Banner>>(banner);
  const [link, setLink] = useState(banner.product_id ? "product" : banner.promotion_id ? "promotion" : banner.category ? "category" : "none");
  const [file, setFile] = useState<File | null>(null);
  const [removeImage, setRemoveImage] = useState(false);
  const [saving, setSaving] = useState(false);
  const preview = file ? URL.createObjectURL(file) : removeImage ? null : banner.image_url;
  const set = (patch: Partial<Banner>) => setB((x) => ({ ...x, ...patch }));

  const submit = async (e: React.FormEvent) => {
    e.preventDefault(); setSaving(true);
    const clean = { ...b, product_id: link === "product" ? b.product_id : null, promotion_id: link === "promotion" ? b.promotion_id : null, category: link === "category" ? b.category : null };
    try { await save(clean, file, removeImage); } catch (x: any) { notify(x.message); } finally { setSaving(false); }
  };

  return (
    <Modal title={banner.id ? "Editar diapositiva" : "Nueva diapositiva"} eyebrow="CARRUSEL DEL PORTAL" close={close}>
      <form className="banner-form" onSubmit={submit}>
        <div className="banner-preview" style={preview ? { backgroundImage: `url(${preview})` } : undefined}>
          <div><small>{link === "promotion" ? "PROMOCIÓN" : link === "product" ? "PRODUCTO" : "DESTACADO"}</small><b>{b.title || "Título de la diapositiva"}</b><span>{b.subtitle || "Texto corto que acompaña al título"}</span><i>{b.button_label || "Ver más"}</i></div>
        </div>
        <div className="form-grid">
          <label className="wide">Título<input required maxLength={120} value={b.title ?? ""} onChange={(e) => set({ title: e.target.value })} placeholder="Ej.: ¡Semana del arroz!" /></label>
          <label className="wide">Texto<input maxLength={255} value={b.subtitle ?? ""} onChange={(e) => set({ subtitle: e.target.value })} placeholder="Ej.: 10% de descuento en todos los arroces hasta el domingo" /></label>
          <label>Texto del botón<input maxLength={40} value={b.button_label ?? ""} onChange={(e) => set({ button_label: e.target.value })} placeholder="Ver más" /></label>
          <label>Al tocarla lleva a<select value={link} onChange={(e) => setLink(e.target.value)}><option value="none">El catálogo</option><option value="product">Un producto</option><option value="promotion">Una promoción</option><option value="category">Una categoría</option></select></label>
          {link === "product" && <label className="wide">Producto<select required value={b.product_id ?? ""} onChange={(e) => set({ product_id: +e.target.value })}><option value="">Elige un producto</option>{data.products.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label>}
          {link === "promotion" && <label className="wide">Promoción<select required value={b.promotion_id ?? ""} onChange={(e) => set({ promotion_id: +e.target.value })}><option value="">Elige una promoción</option>{data.promotions.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select>{data.promotions.length === 0 && <small>No hay promociones activas. Créalas en Promociones.</small>}</label>}
          {link === "category" && <label className="wide">Categoría<select required value={b.category ?? ""} onChange={(e) => set({ category: e.target.value })}><option value="">Elige una categoría</option>{data.categories.map((c) => <option key={c}>{c}</option>)}</select></label>}
          <label>Mostrar desde<input type="datetime-local" value={toInput(b.starts_at)} onChange={(e) => set({ starts_at: e.target.value || null })} /></label>
          <label>Mostrar hasta<input type="datetime-local" value={toInput(b.ends_at)} onChange={(e) => set({ ends_at: e.target.value || null })} /></label>
          <label className="wide">Imagen de fondo (opcional)<input type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setRemoveImage(false); }} /><small>Horizontal, por ejemplo 1600 × 600 px, hasta 4 MB. Sin imagen se usa la foto del producto enlazado o los colores de la tienda.</small></label>
          {banner.image_url && !file && <label className="check-inline"><input type="checkbox" checked={removeImage} onChange={(e) => setRemoveImage(e.target.checked)} /> Quitar la imagen actual</label>}
          <label className="check-inline"><input type="checkbox" checked={!(b.active === false || b.active === 0)} onChange={(e) => set({ active: e.target.checked })} /> Visible en el portal</label>
        </div>
        <footer className="form-actions"><button type="button" onClick={close}>Cancelar</button><button className="primary" disabled={saving}>{saving ? "Guardando…" : "Guardar diapositiva"}</button></footer>
      </form>
    </Modal>
  );
}
