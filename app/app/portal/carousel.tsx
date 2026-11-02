"use client";
// Carruseles del portal: portada con diapositivas y fila deslizable de productos.
import { money } from "@/app/lib/format";
import { useEffect, useRef, useState, type ReactNode } from "react";

export type Slide = { id: number | string; title: string; subtitle?: string | null; button_label?: string | null; category?: string | null; image?: string | null; own_image?: boolean; product?: { id: number; name: string; sale_price: number } | null; promotion?: { id: number; name: string; type: string; value: number } | null };

const promoLabel = (p: NonNullable<Slide["promotion"]>) => (p.type === "percentage" ? `-${+p.value}%` : p.type === "fixed" ? `-S/ ${(+p.value).toFixed(2)}` : "Oferta");
const reducedMotion = () => typeof window !== "undefined" && window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;

/** Portada: pasa sola cada 6 s, se pausa al pasar el mouse o tocarla, y se desliza con el dedo o las flechas. */
export function HeroCarousel({ slides, onOpen }: { slides: Slide[]; onOpen: (s: Slide) => void }) {
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  const touch = useRef<number | null>(null);
  const count = slides.length;
  const go = (i: number) => setIndex(((i % count) + count) % count);

  useEffect(() => {
    if (count < 2 || paused || reducedMotion()) return;
    const t = setTimeout(() => go(index + 1), 6000);
    return () => clearTimeout(t);
  }, [index, paused, count]); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <section className="hero-carousel" aria-roledescription="carrusel" aria-label="Novedades y promociones"
      onMouseEnter={() => setPaused(true)} onMouseLeave={() => setPaused(false)} onFocus={() => setPaused(true)} onBlur={() => setPaused(false)}
      onKeyDown={(e) => { if (e.key === "ArrowRight") go(index + 1); if (e.key === "ArrowLeft") go(index - 1); }}
      onTouchStart={(e) => { touch.current = e.touches[0].clientX; setPaused(true); }}
      onTouchEnd={(e) => { const start = touch.current; touch.current = null; setPaused(false); if (start === null) return; const dx = e.changedTouches[0].clientX - start; if (Math.abs(dx) > 40) go(index + (dx < 0 ? 1 : -1)); }}>
      <div className="hero-track" style={{ transform: `translateX(-${index * 100}%)` }}>
        {slides.map((s, i) => (
          <article key={s.id} className={`hero-slide ${s.image ? (s.own_image ? "with-image" : "with-product") : "plain"}`} aria-roledescription="diapositiva" aria-label={`${i + 1} de ${count}: ${s.title}`} aria-hidden={i !== index}
            style={s.image && s.own_image ? { backgroundImage: `url(${s.image})` } : undefined}>
            <div className="hero-copy">
              <small>{s.promotion ? "PROMOCIÓN" : s.product ? "DESTACADO" : s.category ? s.category.toUpperCase() : "NOVEDAD"}</small>
              <h2>{s.title}</h2>
              {s.subtitle && <p>{s.subtitle}</p>}
              {s.product && <b className="hero-price">{money(s.product.sale_price)}</b>}
              <button tabIndex={i === index ? 0 : -1} onClick={() => onOpen(s)}>{s.button_label || (s.product ? "Ver producto" : s.category ? `Ver ${s.category.toLowerCase()}` : "Ver productos")} →</button>
            </div>
            {s.image && !s.own_image && <img className="hero-product" src={s.image} alt="" loading={i === 0 ? "eager" : "lazy"} />}
            {s.promotion && <span className="hero-badge">{promoLabel(s.promotion)}</span>}
          </article>
        ))}
      </div>
      {count > 1 && <>
        <button className="hero-arrow prev" onClick={() => go(index - 1)} aria-label="Anterior">‹</button>
        <button className="hero-arrow next" onClick={() => go(index + 1)} aria-label="Siguiente">›</button>
        <div className="hero-dots" role="tablist">{slides.map((s, i) => <button key={s.id} role="tab" aria-selected={i === index} aria-label={`Ir a ${s.title}`} className={i === index ? "active" : ""} onClick={() => go(i)} />)}</div>
      </>}
    </section>
  );
}

/** Fila horizontal con flechas; en el celular se desliza con el dedo. */
export function ProductRail({ title, eyebrow, children }: { title: string; eyebrow: string; children: ReactNode }) {
  const track = useRef<HTMLDivElement>(null);
  const [edges, setEdges] = useState({ start: true, end: false });
  const update = () => { const t = track.current; if (t) setEdges({ start: t.scrollLeft <= 4, end: t.scrollLeft + t.clientWidth >= t.scrollWidth - 4 }); };
  useEffect(() => { update(); window.addEventListener("resize", update); return () => window.removeEventListener("resize", update); }, [children]);
  const scroll = (dir: number) => track.current?.scrollBy({ left: dir * track.current.clientWidth * 0.85, behavior: reducedMotion() ? "auto" : "smooth" });
  return (
    <section className="product-rail">
      <header><div><small>{eyebrow}</small><h2>{title}</h2></div>
        <div className="rail-arrows"><button onClick={() => scroll(-1)} disabled={edges.start} aria-label="Ver anteriores">‹</button><button onClick={() => scroll(1)} disabled={edges.end} aria-label="Ver siguientes">›</button></div>
      </header>
      <div className="rail-track portal-products" ref={track} onScroll={update}>{children}</div>
    </section>
  );
}
