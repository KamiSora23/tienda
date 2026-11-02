import type { Metadata } from "next";
import { headers } from "next/headers";
import "./globals.css";

export async function generateMetadata(): Promise<Metadata> {
  const requestHeaders = await headers();
  const host = requestHeaders.get("x-forwarded-host") ?? requestHeaders.get("host");
  const protocol = requestHeaders.get("x-forwarded-proto") ?? "https";
  const image = host ? `${protocol}://${host}/og.png` : undefined;
  return {
    title: "Deurstore ERP",
    description: "ERP comercial, inventario, POS y caja para negocios peruanos.",
    openGraph: image ? { title: "Deurstore ERP", description: "ERP comercial · POS · Inventario", images: [image] } : undefined,
    twitter: image ? { card: "summary_large_image", title: "Deurstore ERP", description: "ERP comercial · POS · Inventario", images: [image] } : undefined,
  };
}

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="es">
      <body>{children}</body>
    </html>
  );
}
