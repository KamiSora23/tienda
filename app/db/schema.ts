import { sql } from "drizzle-orm";
import { integer, real, sqliteTable, text, uniqueIndex } from "drizzle-orm/sqlite-core";

export const companies = sqliteTable("empresas", {
  id: integer("id").primaryKey({ autoIncrement: true }),
  uuid: text("uuid").notNull(),
  nombre: text("nombre").notNull(),
  ruc: text("ruc").notNull(),
  estado: text("estado").notNull().default("activo"),
  fechaCreacion: text("fecha_creacion").notNull().default(sql`CURRENT_TIMESTAMP`),
}, (t) => [uniqueIndex("empresas_uuid_idx").on(t.uuid), uniqueIndex("empresas_ruc_idx").on(t.ruc)]);

export const branches = sqliteTable("sucursales", {
  id: integer("id").primaryKey({ autoIncrement: true }),
  empresaId: integer("empresa_id").notNull().references(() => companies.id),
  codigo: text("codigo").notNull(),
  nombre: text("nombre").notNull(),
  direccion: text("direccion"),
  estado: text("estado").notNull().default("activo"),
}, (t) => [uniqueIndex("sucursales_empresa_codigo_idx").on(t.empresaId, t.codigo)]);

export const products = sqliteTable("productos", {
  id: integer("id").primaryKey({ autoIncrement: true }),
  empresaId: integer("empresa_id").notNull().references(() => companies.id),
  sku: text("sku").notNull(),
  codigoBarras: text("codigo_barras"),
  nombre: text("nombre").notNull(),
  categoria: text("categoria").notNull(),
  unidad: text("unidad").notNull().default("un"),
  permiteDecimales: integer("permite_decimales", { mode: "boolean" }).notNull().default(false),
  costoPromedio: real("costo_promedio").notNull().default(0),
  precioVenta: real("precio_venta").notNull().default(0),
  precioMinimo: real("precio_minimo").notNull().default(0),
  stockMinimo: real("stock_minimo").notNull().default(0),
  estado: text("estado").notNull().default("activo"),
}, (t) => [uniqueIndex("productos_empresa_sku_idx").on(t.empresaId, t.sku)]);

export const stocks = sqliteTable("existencias", {
  id: integer("id").primaryKey({ autoIncrement: true }),
  empresaId: integer("empresa_id").notNull(),
  sucursalId: integer("sucursal_id").notNull().references(() => branches.id),
  productoId: integer("producto_id").notNull().references(() => products.id),
  cantidad: real("cantidad").notNull().default(0),
}, (t) => [uniqueIndex("existencias_sucursal_producto_idx").on(t.sucursalId, t.productoId)]);

export const inventoryMovements = sqliteTable("movimientos_inventario", {
  id: integer("id").primaryKey({ autoIncrement: true }),
  uuid: text("uuid").notNull(),
  empresaId: integer("empresa_id").notNull(),
  sucursalId: integer("sucursal_id").notNull(),
  productoId: integer("producto_id").notNull(),
  tipo: text("tipo").notNull(),
  cantidad: real("cantidad").notNull(),
  costoUnitarioHistorico: real("costo_unitario_historico").notNull(),
  stockAnterior: real("stock_anterior").notNull(),
  stockPosterior: real("stock_posterior").notNull(),
  documento: text("documento"),
  fechaCreacion: text("fecha_creacion").notNull().default(sql`CURRENT_TIMESTAMP`),
});

export const sales = sqliteTable("ventas", {
  id: integer("id").primaryKey({ autoIncrement: true }),
  uuid: text("uuid").notNull(),
  empresaId: integer("empresa_id").notNull(),
  sucursalId: integer("sucursal_id").notNull(),
  numero: text("numero").notNull(),
  cliente: text("cliente"),
  subtotal: real("subtotal").notNull(),
  igv: real("igv").notNull(),
  total: real("total").notNull(),
  estado: text("estado").notNull().default("pagada"),
  fechaCreacion: text("fecha_creacion").notNull().default(sql`CURRENT_TIMESTAMP`),
});

export const saleDetails = sqliteTable("detalle_ventas", {
  id: integer("id").primaryKey({ autoIncrement: true }),
  ventaId: integer("venta_id").notNull().references(() => sales.id),
  productoId: integer("producto_id").notNull(),
  cantidad: real("cantidad").notNull(),
  precioUnitario: real("precio_unitario").notNull(),
  costoUnitarioHistorico: real("costo_unitario_historico").notNull(),
  total: real("total").notNull(),
});

export const salePayments = sqliteTable("pagos_venta", {
  id: integer("id").primaryKey({ autoIncrement: true }),
  ventaId: integer("venta_id").notNull().references(() => sales.id),
  metodo: text("metodo").notNull(),
  monto: real("monto").notNull(),
});

export const cashSessions = sqliteTable("turnos_caja", {
  id: integer("id").primaryKey({ autoIncrement: true }),
  empresaId: integer("empresa_id").notNull(),
  sucursalId: integer("sucursal_id").notNull(),
  usuario: text("usuario").notNull(),
  montoInicial: real("monto_inicial").notNull(),
  montoEsperado: real("monto_esperado"),
  montoContado: real("monto_contado"),
  estado: text("estado").notNull().default("abierto"),
  fechaApertura: text("fecha_apertura").notNull().default(sql`CURRENT_TIMESTAMP`),
  fechaCierre: text("fecha_cierre"),
});

export const purchases = sqliteTable("compras", {
  id: integer("id").primaryKey({ autoIncrement: true }),
  empresaId: integer("empresa_id").notNull(),
  sucursalId: integer("sucursal_id").notNull(),
  numero: text("numero").notNull(),
  proveedor: text("proveedor"),
  subtotal: real("subtotal").notNull(),
  igv: real("igv").notNull().default(0),
  flete: real("flete").notNull().default(0),
  percepcion: real("percepcion").notNull().default(0),
  total: real("total").notNull(),
  estado: text("estado").notNull().default("confirmada"),
  fechaCreacion: text("fecha_creacion").notNull().default(sql`CURRENT_TIMESTAMP`),
});

export const auditEvents = sqliteTable("auditorias", {
  id: integer("id").primaryKey({ autoIncrement: true }),
  uuid: text("uuid").notNull(),
  empresaId: integer("empresa_id").notNull(),
  usuario: text("usuario").notNull(),
  evento: text("evento").notNull(),
  entidad: text("entidad").notNull(),
  entidadId: integer("entidad_id"),
  antes: text("antes"),
  despues: text("despues"),
  fechaCreacion: text("fecha_creacion").notNull().default(sql`CURRENT_TIMESTAMP`),
});
