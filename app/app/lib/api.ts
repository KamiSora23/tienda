// Cliente HTTP único para la API Laravel. Antes cada módulo tenía su propia copia
// con manejo de errores distinto; todos deben importar desde aquí.
import { saveBlob } from "./format";

export const API = process.env.NEXT_PUBLIC_API_URL || "http://127.0.0.1:8001/api/v1";

export class ApiError extends Error {
  constructor(message: string, readonly status: number) {
    super(message);
  }
}

const STATUS_MESSAGES: Record<number, string> = {
  401: "Tu sesión expiró. Vuelve a iniciar sesión.",
  403: "No tienes permiso para realizar esta acción.",
  404: "El recurso solicitado no existe o el servidor de la API no es el correcto.",
  419: "Tu sesión expiró. Vuelve a iniciar sesión.",
  429: "Demasiados intentos. Espera un momento e inténtalo de nuevo.",
};

// Los errores 5xx pueden traer el texto interno de la excepción (SQL, rutas de
// archivos) cuando APP_DEBUG está activo; nunca se muestran al usuario.
export function errorMessage(status: number, body: any): string {
  // 503 lleva mensajes propios del sistema (p. ej. «el sistema está en mantenimiento»); el resto de 5xx no se muestra.
  if (status === 503 && typeof body?.message === "string" && body.message.length < 300) return body.message;
  if (status >= 500) return "El servidor tuvo un problema al procesar la solicitud. Inténtalo de nuevo o contacta al administrador.";
  const validation = Object.values(body?.errors || {}).flat().join(", ");
  return validation || body?.message || STATUS_MESSAGES[status] || "No se pudo completar la operación.";
}

// Descarga autenticada (exportaciones, respaldos, archivos).
export async function downloadFile(path: string, token: string, filename: string, accept = "*/*") {
  let response: Response;
  try {
    response = await fetch(`${API}${path}`, { headers: { Accept: accept, Authorization: `Bearer ${token}` } });
  } catch {
    throw new ApiError("No se pudo conectar con el servidor. Verifica que la API de Deurstore esté iniciada.", 0);
  }
  if (!response.ok) throw new ApiError(errorMessage(response.status, await response.json().catch(() => null)), response.status);
  saveBlob(await response.blob(), filename);
}

export async function api(path: string, token = "", options: RequestInit = {}) {
  const headers: Record<string, string> = {
    Accept: "application/json",
    ...(options.body instanceof FormData ? {} : { "Content-Type": "application/json" }),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...((options.headers as Record<string, string>) || {}),
  };
  let response: Response;
  try {
    response = await fetch(`${API}${path}`, { ...options, headers });
  } catch {
    throw new ApiError("No se pudo conectar con el servidor. Verifica que la API de Deurstore esté iniciada.", 0);
  }
  const body = await response.json().catch(() => null);
  if (!response.ok) throw new ApiError(errorMessage(response.status, body), response.status);
  if (body === null) throw new ApiError("El servidor respondió en un formato inesperado.", response.status);
  return body.data;
}
