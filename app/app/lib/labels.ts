// Textos legibles para los códigos de auditoría que registra la API (audit_events.event).

const EVENT_LABELS: Record<string, string> = {
  "auth.login": "Inicio de sesión",
  "created": "Registro creado",
  "updated": "Registro actualizado",
  "deleted": "Registro eliminado",
  "company.saved": "Empresa actualizada",
  "integration.test": "Prueba de integración",
  "permissions.assigned": "Permisos asignados",
  "product.created": "Producto creado",
  "product.saved": "Producto actualizado",
  "promotion.saved": "Promoción guardada",
  "purchase.confirmed": "Compra confirmada",
  "role.duplicated": "Rol duplicado",
  "sale.cancelled": "Venta anulada",
  "sale.completed": "Venta registrada",
  "sale.debit_note": "Nota de débito emitida",
  "sale.price_overridden": "Precio de venta modificado",
  "sale.returned": "Devolución de venta",
  "user.password_changed": "Contraseña cambiada",
  "user.profile_updated": "Perfil actualizado",
  "user.saved": "Usuario guardado",
  "user.session_revoked": "Sesión cerrada por administrador",
  "purchase.cancelled": "Compra anulada",
  "purchase.returned": "Devolución a proveedor",
  "sale.discount_applied": "Descuento aplicado",
  "unit.status_changed": "Unidad activada o desactivada",
};

const MOVEMENT_LABELS: Record<string, string> = {
  initial_stock: "Stock inicial",
  purchase_in: "Compra",
  purchase_cancel_out: "Anulación de compra",
  purchase_return_out: "Devolución a proveedor",
  sale_out: "Venta",
  web_sale_out: "Venta web",
  sale_return_in: "Devolución de cliente",
  sale_cancel_in: "Anulación de venta",
  adjustment: "Ajuste",
  count_adjustment: "Conteo físico",
  transfer_out: "Transferencia (salida)",
  transfer_in: "Transferencia (entrada)",
};

export const movementLabel = (type?: string) => (type && MOVEMENT_LABELS[type]) || type || "—";

const MODULE_LABELS: Record<string, string> = {
  companies: "Empresas", branches: "Sucursales", users: "Usuarios", roles: "Roles", customers: "Clientes", suppliers: "Proveedores",
  categories: "Categorías", brands: "Marcas", units: "Unidades", products: "Productos", warehouses: "Almacenes", inventory: "Inventario",
  purchases: "Compras", sales: "Ventas", cash: "Caja", promotions: "Promociones", expenses: "Gastos", reports: "Reportes", audit: "Auditoría",
  settings: "Configuración", ai: "IA", portal: "Portal web", orders: "Pedidos web", notifications: "Notificaciones", files: "Archivos",
  backups: "Respaldos", integrations: "Integraciones", maintenance: "Mantenimiento",
};
const ACTION_LABELS: Record<string, string> = { view: "Ver", create: "Crear", update: "Editar", delete: "Eliminar", cancel: "Anular", export: "Exportar", manage: "Administrar", discount: "Aplicar descuentos", override_price: "Cambiar precios" };

export const moduleLabel = (module?: string) => (module && MODULE_LABELS[module]) || module || "—";
export const actionLabel = (action?: string) => (action && ACTION_LABELS[action]) || action || "—";

// Códigos internos que la API devuelve en estados, tipos y roles.
const CODE_LABELS: Record<string, string> = {
  active: "Activo", inactive: "Inactivo", pending: "Pendiente", paid: "Pagada", confirmed: "Confirmada", cancelled: "Anulada",
  completed: "Completada", open: "Abierta", closed: "Cerrada", refunded: "Devuelta", partial_return: "Devolución parcial", draft: "Borrador",
  available: "Disponible", depleted: "Agotado", suspended: "Suspendido", preparing: "En preparación", packed: "Empacado", ready: "Listo",
  delivered: "Entregado", overdue: "Vencido", issued: "Emitida", released: "Liberada", expired: "Vencida", failed: "Fallido", success: "Correcto",
  ticket: "Ticket", receipt: "Boleta", invoice: "Factura", credit_note: "Nota de crédito", debit_note: "Nota de débito", quick: "Compra rápida", guide: "Guía",
  cash: "Efectivo", yape: "Yape", plin: "Plin", card: "Tarjeta", transfer: "Transferencia", credit: "Crédito",
  opening: "Apertura", sale: "Venta", income: "Ingreso", withdrawal: "Retiro", deposit: "Depósito", expense: "Gasto", refund: "Devolución", closing: "Cierre",
  partial: "Arqueo parcial", final: "Arqueo final", percentage: "Porcentaje", fixed: "Monto fijo", buy_x_get_y: "Lleva X paga Y", combo: "Combo",
  admin: "Administrador", manager: "Gerente", cashier: "Cajero", seller: "Vendedor", platform_admin: "Plataforma",
  purchase: "Compra", product: "Producto", products: "Productos", users: "Usuarios", sales: "Ventas", purchases: "Compras", companies: "Empresas",
  branches: "Sucursales", warehouses: "Almacenes", promotion: "Promoción", auth: "Acceso", units: "Unidades", roles: "Roles",
  electricity: "Electricidad", water: "Agua", gas: "Gas", other: "Otro", document: "Documento", image: "Imagen",
};

/** Traduce un código interno ("paid", "ticket"…) o devuelve el valor tal cual. */
export const codeLabel = (value: unknown) => (typeof value === "string" && (CODE_LABELS[value] ?? MOVEMENT_LABELS[value] ?? EVENT_LABELS[value])) || (value as string);

// Encabezados de columnas que llegan con el nombre del campo de la base de datos.
const COLUMN_LABELS: Record<string, string> = {
  id: "N°", name: "Nombre", slug: "Código web", sort_order: "Orden", status: "Estado", parent_name: "Categoría padre",
  created_at: "Fecha", updated_at: "Actualizado", opened_at: "Apertura de caja", starts_at: "Inicio", ends_at: "Fin",
  number: "Número", invoice_number: "Comprobante del proveedor", document_type: "Comprobante", document_number: "Documento",
  customer_name: "Cliente", customer_phone: "Teléfono", supplier_name: "Proveedor", user_name: "Usuario", product_name: "Producto",
  warehouse_name: "Almacén", subtotal: "Subtotal", discount: "Descuento", tax: "IGV", freight: "Flete", total: "Total",
  previous_price: "Precio anterior", new_price: "Precio nuevo", previous_cost: "Costo anterior", new_cost: "Costo nuevo",
  reason: "Motivo", source: "Origen", type: "Tipo", quantity: "Cantidad", previous_stock: "Stock anterior", new_stock: "Stock nuevo",
  amount: "Monto", method: "Método", description: "Descripción", reference: "Referencia", event: "Evento", entity: "Módulo", entity_id: "Registro",
  ip: "IP", value: "Valor", payment_method: "Método de pago", payment_status: "Estado del pago", code: "Código", logo: "Logo",
  business_name: "Razón social", trade_name: "Nombre comercial", ruc: "RUC", email: "Correo", phone: "Teléfono", address: "Dirección",
  currency: "Moneda", timezone: "Zona horaria", contact: "Contacto", role: "Rol", title: "Título", message: "Mensaje", link: "Enlace",
  feature: "Función", model: "Modelo", tokens: "Tokens", cost: "Costo", provider: "Proveedor", operation: "Operación", error: "Error",
  branch_name: "Sucursal", delivery_address: "Dirección de entrega", filename: "Archivo", size: "Tamaño", mime_type: "Tipo de archivo",
};

/** Encabezado en español para un campo; si no está en la lista, lo deja legible ("sale_price" → "Sale price"). */
export const columnLabel = (key: string) => COLUMN_LABELS[key] ?? key.replaceAll("_", " ").replace(/^\w/, (x) => x.toUpperCase());

export const eventLabel = (event?: string) => (event && EVENT_LABELS[event]) || event || "—";
