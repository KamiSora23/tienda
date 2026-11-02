"use client";
// Configuración › General › Entregas del portal: recojo en tienda, delivery y ubicación de la tienda.
// Los campos viven dentro del formulario general; la ubicación viaja en campos ocultos.
import { MapPicker, type LatLng } from "@/app/components/MapPicker";
import { useState } from "react";

export function DeliverySettings({ val }: { val: (group: string, key: string, fallback: string) => string }) {
  const [pickup, setPickup] = useState(val("portal", "pickup_enabled", "1") === "1");
  const [delivery, setDelivery] = useState(val("portal", "delivery_enabled", "1") === "1");
  const lat = parseFloat(val("portal", "store_lat", "")), lng = parseFloat(val("portal", "store_lng", ""));
  const [store, setStore] = useState<LatLng | null>(Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null);
  const [city, setCity] = useState(val("portal", "delivery_city", "Piura"));
  const [radius, setRadius] = useState(+val("portal", "delivery_radius_km", "0") || 0);
  const [mapKey, setMapKey] = useState(0); // al cambiar la ciudad sin tienda marcada, el mapa se mueve a la nueva ciudad

  return (
    <>
      <h2>Entregas del portal</h2>
      <p className="settings-help">Elige cómo pueden recibir sus pedidos los clientes. Si activas ambas, el cliente elige al confirmar la compra.</p>
      <div className="delivery-options">
        <label className={pickup ? "active" : ""}><input type="checkbox" name="pickup_enabled" checked={pickup} onChange={(e) => setPickup(e.target.checked)} /><span><b>🏪 Recojo en tienda</b><small>El cliente pasa a recoger su pedido. Sin costo de envío.</small></span></label>
        <label className={delivery ? "active" : ""}><input type="checkbox" name="delivery_enabled" checked={delivery} onChange={(e) => setDelivery(e.target.checked)} /><span><b>🛵 Delivery</b><small>Llevamos el pedido a la dirección que el cliente marque en el mapa.</small></span></label>
      </div>
      {!pickup && !delivery && <div className="settings-warning">Sin ningún método de entrega activo, el portal no podrá recibir pedidos.</div>}
      <div className="form-grid">
        <label hidden={!pickup}>Dirección para recoger<input name="pickup_address" defaultValue={val("portal", "pickup_address", "")} placeholder="Por defecto, la dirección de la empresa" maxLength={255} /></label>
          <label hidden={!pickup}>Horario de recojo<input name="pickup_hours" defaultValue={val("portal", "pickup_hours", "")} placeholder="Ej.: Lun a Sáb, 8:00 a. m. – 8:00 p. m." maxLength={120} /></label>
          <label hidden={!delivery}>Costo de delivery (S/)<input name="delivery_fee" type="number" step="0.10" min="0" defaultValue={val("portal", "delivery_fee", "0")} /></label>
          <label hidden={!delivery}>Ciudad de reparto<input name="delivery_city" value={city} onChange={(e) => setCity(e.target.value)} onBlur={() => !store && setMapKey((k) => k + 1)} placeholder="Ej.: Piura" maxLength={80} /><small className="field-help">El mapa del cliente abre aquí y busca solo en esta ciudad.</small></label>
          <label hidden={!delivery}>Radio de reparto (km)<input name="delivery_radius_km" type="number" step="0.5" min="0" max="200" value={radius} onChange={(e) => setRadius(Math.max(0, +e.target.value))} /><small className="field-help">{radius > 0 ? (store ? `Solo se aceptan entregas hasta ${radius} km de la tienda.` : "Marca la ubicación de la tienda para aplicar el radio.") : "0 = sin límite de distancia"}</small></label>
          <label hidden={!delivery}>Delivery gratis desde (S/)<input name="free_delivery_from" type="number" step="1" min="0" defaultValue={val("portal", "free_delivery_from", "0")} /><small className="field-help">0 = nunca es gratis</small></label>
      </div>
      <div className="wide store-location"><b>Ubicación de la tienda</b><small className="field-help">Punto de partida del delivery: centra el mapa del cliente, marca la zona de reparto y se muestra en el recojo.</small>
        <MapPicker key={mapKey} value={store} center={store} city={city} radiusKm={delivery ? radius : 0} onChange={(p) => setStore(p)} height={260} hint="Busca tu calle o toca el mapa para marcar la tienda." />
      </div>
      <input type="hidden" name="store_lat" value={store?.lat ?? ""} />
      <input type="hidden" name="store_lng" value={store?.lng ?? ""} />
    </>
  );
}
