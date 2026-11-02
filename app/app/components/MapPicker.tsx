"use client";
// Mapa para marcar una ubicación (OpenStreetMap + Leaflet, sin clave de API).
// Leaflet se carga desde el CDN solo cuando se muestra un mapa.
import { useEffect, useRef, useState } from "react";

export type LatLng = { lat: number; lng: number };
// city: ciudad de reparto (centra el mapa si no hay punto y acota la búsqueda). radiusKm: zona de reparto alrededor de center.
type Props = { value: LatLng | null; onChange: (v: LatLng, address?: string) => void; center?: LatLng | null; height?: number; hint?: string; city?: string; radiusKm?: number };

const LEAFLET = "https://unpkg.com/leaflet@1.9.4/dist/";
const DEFAULT_CENTER: LatLng = { lat: -5.1945, lng: -80.6328 }; // Piura

/** Distancia en km entre dos puntos (fórmula del haversine). */
export function distanceKm(a: LatLng, b: LatLng) {
  const r = (d: number) => (d * Math.PI) / 180;
  const h = Math.sin(r(b.lat - a.lat) / 2) ** 2 + Math.cos(r(a.lat)) * Math.cos(r(b.lat)) * Math.sin(r(b.lng - a.lng) / 2) ** 2;
  return 6371 * 2 * Math.asin(Math.min(1, Math.sqrt(h)));
}

// Centro de cada ciudad buscada (una sola consulta por ciudad y página). featureType=city evita
// confundir la ciudad con el departamento del mismo nombre (Piura, Lima, Cusco…).
const cityCenters = new Map<string, Promise<LatLng | null>>();
function cityCenter(city: string): Promise<LatLng | null> {
  const key = city.trim().toLowerCase();
  if (!cityCenters.has(key)) cityCenters.set(key, fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=pe&accept-language=es&featureType=city&q=${encodeURIComponent(city)}`, { headers: { Accept: "application/json" } })
    .then((r) => r.json()).then(([hit]) => (hit ? { lat: +hit.lat, lng: +hit.lon } : null)).catch(() => null));
  return cityCenters.get(key)!;
}

let leafletLoading: Promise<any> | null = null;
function loadLeaflet(): Promise<any> {
  if ((window as any).L) return Promise.resolve((window as any).L);
  leafletLoading ??= new Promise((resolve, reject) => {
    const css = document.createElement("link");
    css.rel = "stylesheet"; css.href = `${LEAFLET}leaflet.css`;
    document.head.appendChild(css);
    const js = document.createElement("script");
    js.src = `${LEAFLET}leaflet.js`; js.async = true;
    js.onload = () => resolve((window as any).L);
    js.onerror = () => { leafletLoading = null; reject(new Error("No se pudo cargar el mapa")); };
    document.head.appendChild(js);
  });
  return leafletLoading;
}

// Dirección aproximada del punto (Nominatim, solo al soltar el marcador o buscar: uso esporádico).
async function reverseGeocode(p: LatLng): Promise<string | undefined> {
  try {
    const r = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&accept-language=es&lat=${p.lat}&lon=${p.lng}`, { headers: { Accept: "application/json" } });
    const b = await r.json();
    const a = b.address ?? {};
    return [[a.road, a.house_number].filter(Boolean).join(" "), a.suburb || a.neighbourhood, a.city || a.town || a.village].filter(Boolean).join(", ") || b.display_name;
  } catch { return undefined; }
}

export function MapPicker({ value, onChange, center, height = 260, hint = "Toca el mapa para marcar la ubicación.", city, radiusKm = 0 }: Props) {
  const box = useRef<HTMLDivElement>(null);
  const map = useRef<any>(null);
  const marker = useRef<any>(null);
  const circle = useRef<any>(null);
  const changeRef = useRef(onChange);
  changeRef.current = onChange;
  const [error, setError] = useState("");
  const [query, setQuery] = useState("");
  const [busy, setBusy] = useState(false);

  const place = async (p: LatLng, lookup = true) => {
    const L = (window as any).L;
    if (!map.current || !L) return;
    if (!marker.current) {
      marker.current = L.marker([p.lat, p.lng], { draggable: true }).addTo(map.current);
      marker.current.on("dragend", () => { const ll = marker.current.getLatLng(); place({ lat: ll.lat, lng: ll.lng }); });
    } else marker.current.setLatLng([p.lat, p.lng]);
    const rounded = { lat: +p.lat.toFixed(7), lng: +p.lng.toFixed(7) };
    changeRef.current(rounded, lookup ? await reverseGeocode(rounded) : undefined);
  };

  useEffect(() => {
    let cancelled = false;
    loadLeaflet().then((L) => {
      if (cancelled || !box.current || map.current) return;
      const start = value ?? center ?? DEFAULT_CENTER;
      map.current = L.map(box.current, { scrollWheelZoom: false }).setView([start.lat, start.lng], value ? 17 : radiusKm > 0 ? Math.max(11, Math.round(14.5 - Math.log2(radiusKm))) : 14);
      if (!value && !center && city) cityCenter(city).then((c) => { if (c && map.current && !marker.current) map.current.setView([c.lat, c.lng], 14); });
      L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19, attribution: "© OpenStreetMap" }).addTo(map.current);
      // Íconos del marcador desde el mismo CDN que la hoja de estilos.
      L.Icon.Default.prototype.options.imagePath = `${LEAFLET}images/`;
      if (value) place(value, false);
      drawZone();
      map.current.on("click", (e: any) => place({ lat: e.latlng.lat, lng: e.latlng.lng }));
      setTimeout(() => map.current?.invalidateSize(), 150);
    }).catch((e) => setError(e.message));
    return () => { cancelled = true; map.current?.remove(); map.current = null; marker.current = null; circle.current = null; };
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  // Zona de reparto: se dibuja al crear el mapa y cada vez que cambian el centro o el radio.
  const zone = useRef({ center, radiusKm });
  zone.current = { center, radiusKm };
  const drawZone = () => {
    const L = (window as any).L, { center: c, radiusKm: km } = zone.current;
    if (!map.current || !L) return;
    circle.current?.remove(); circle.current = null;
    const color = (box.current && (getComputedStyle(box.current).getPropertyValue("--brand") || getComputedStyle(box.current).getPropertyValue("--green")).trim()) || "#168555";
    if (c && km > 0) circle.current = L.circle([c.lat, c.lng], { radius: km * 1000, color, weight: 2, fillOpacity: 0.07, interactive: false }).addTo(map.current);
  };
  useEffect(drawZone, [center?.lat, center?.lng, radiusKm]); // eslint-disable-line react-hooks/exhaustive-deps

  const locate = () => {
    if (!navigator.geolocation) return setError("Tu navegador no permite obtener la ubicación.");
    setBusy(true); setError("");
    navigator.geolocation.getCurrentPosition(
      (pos) => { const p = { lat: pos.coords.latitude, lng: pos.coords.longitude }; map.current?.setView([p.lat, p.lng], 17); place(p).finally(() => setBusy(false)); },
      () => { setBusy(false); setError("No pudimos obtener tu ubicación. Marca el punto tocando el mapa."); },
      { enableHighAccuracy: true, timeout: 10000 },
    );
  };

  const search = async () => {
    if (!query.trim()) return;
    setBusy(true); setError("");
    try {
      const c = center ?? (city ? await cityCenter(city) : null) ?? DEFAULT_CENTER;
      const q = city && !query.toLowerCase().includes(city.toLowerCase()) ? `${query}, ${city}` : query;
      // Varios resultados dentro de la zona y se elige el más cercano: así "Plaza de Armas" no cae en un distrito vecino.
      const d = 0.15;
      const r = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=8&countrycodes=pe&accept-language=es&bounded=1&q=${encodeURIComponent(q)}&viewbox=${c.lng - d},${c.lat + d},${c.lng + d},${c.lat - d}`, { headers: { Accept: "application/json" } });
      const hits: any[] = await r.json();
      const hit = hits.sort((a, b) => distanceKm(c, { lat: +a.lat, lng: +a.lon }) - distanceKm(c, { lat: +b.lat, lng: +b.lon }))[0];
      if (!hit) setError("No encontramos esa dirección. Prueba con calle y número, o marca el punto en el mapa.");
      else { const p = { lat: +hit.lat, lng: +hit.lon }; map.current?.setView([p.lat, p.lng], 17); await place(p, false); changeRef.current({ lat: +p.lat.toFixed(7), lng: +p.lng.toFixed(7) }, hit.display_name?.split(",").slice(0, 3).join(",")); }
    } catch { setError("No se pudo buscar la dirección."); } finally { setBusy(false); }
  };

  return (
    <div className="map-picker">
      <div className="map-tools">
        <input value={query} onChange={(e) => setQuery(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); search(); } }} placeholder={city ? `Buscar calle o lugar en ${city}` : "Buscar dirección en el mapa"} aria-label="Buscar dirección en el mapa" />
        <button type="button" onClick={search} disabled={busy}>Buscar</button>
        <button type="button" onClick={locate} disabled={busy} title="Usar mi ubicación actual">◎ Mi ubicación</button>
      </div>
      <div ref={box} className="map-canvas" style={{ height }} role="application" aria-label="Mapa: toca para marcar la ubicación" />
      {value && center && radiusKm > 0 && distanceKm(center, value) > radiusKm && <small className="map-out">Este punto está a {distanceKm(center, value).toFixed(1)} km de la tienda, fuera de la zona de reparto ({radiusKm} km).</small>}
      <small className="map-hint">{error || (value ? `Ubicación marcada (${value.lat.toFixed(5)}, ${value.lng.toFixed(5)}). Puedes arrastrar el marcador para ajustarla.` : hint)}</small>
    </div>
  );
}

/** Enlace para abrir la ubicación en Google Maps (útil para el repartidor). */
export const mapsLink = (lat?: number | string | null, lng?: number | string | null) => (lat != null && lng != null && lat !== "" ? `https://www.google.com/maps?q=${lat},${lng}` : null);
