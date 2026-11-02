"use client";

import { portalStyle, usePortalHome } from "./shared";
import "./portal.css";

// Si la tienda desactivó el portal, ninguna página del portal se muestra.
export default function PortalLayout({ children }: { children: React.ReactNode }) {
  const home = usePortalHome();

  if (!home) return <main className="portal"><section className="portal-disabled"><p>Cargando portal…</p></section></main>;
  if (!home.portal) return <main className="portal"><section className="portal-disabled"><h1>Portal no disponible</h1><p>No fue posible conectar con la tienda. Intenta nuevamente en unos momentos.</p></section></main>;
  if (home.portal.enabled === false) {
    const name = home.company?.trade_name || "Tienda";
    return <main className="portal" style={portalStyle(home)}><header className="portal-header"><a className="portal-brand" href="/portal"><b>{name[0]}</b><span>{name}<small>PORTAL PÚBLICO</small></span></a></header><section className="portal-disabled"><small>PORTAL TEMPORALMENTE CERRADO</small><h1>Volveremos pronto</h1><p>El portal público se encuentra desactivado. Comunícate directamente con la tienda para recibir atención.</p>{home.portal.contact_phone && <a href={`tel:${home.portal.contact_phone}`}>Llamar al {home.portal.contact_phone}</a>}</section></main>;
  }
  return children;
}
