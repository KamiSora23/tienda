CREATE TABLE `auditorias` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`uuid` text NOT NULL,
	`empresa_id` integer NOT NULL,
	`usuario` text NOT NULL,
	`evento` text NOT NULL,
	`entidad` text NOT NULL,
	`entidad_id` integer,
	`antes` text,
	`despues` text,
	`fecha_creacion` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE TABLE `sucursales` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`empresa_id` integer NOT NULL,
	`codigo` text NOT NULL,
	`nombre` text NOT NULL,
	`direccion` text,
	`estado` text DEFAULT 'activo' NOT NULL,
	FOREIGN KEY (`empresa_id`) REFERENCES `empresas`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `sucursales_empresa_codigo_idx` ON `sucursales` (`empresa_id`,`codigo`);--> statement-breakpoint
CREATE TABLE `turnos_caja` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`empresa_id` integer NOT NULL,
	`sucursal_id` integer NOT NULL,
	`usuario` text NOT NULL,
	`monto_inicial` real NOT NULL,
	`monto_esperado` real,
	`monto_contado` real,
	`estado` text DEFAULT 'abierto' NOT NULL,
	`fecha_apertura` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	`fecha_cierre` text
);
--> statement-breakpoint
CREATE TABLE `empresas` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`uuid` text NOT NULL,
	`nombre` text NOT NULL,
	`ruc` text NOT NULL,
	`estado` text DEFAULT 'activo' NOT NULL,
	`fecha_creacion` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `empresas_uuid_idx` ON `empresas` (`uuid`);--> statement-breakpoint
CREATE UNIQUE INDEX `empresas_ruc_idx` ON `empresas` (`ruc`);--> statement-breakpoint
CREATE TABLE `movimientos_inventario` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`uuid` text NOT NULL,
	`empresa_id` integer NOT NULL,
	`sucursal_id` integer NOT NULL,
	`producto_id` integer NOT NULL,
	`tipo` text NOT NULL,
	`cantidad` real NOT NULL,
	`costo_unitario_historico` real NOT NULL,
	`stock_anterior` real NOT NULL,
	`stock_posterior` real NOT NULL,
	`documento` text,
	`fecha_creacion` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE TABLE `productos` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`empresa_id` integer NOT NULL,
	`sku` text NOT NULL,
	`codigo_barras` text,
	`nombre` text NOT NULL,
	`categoria` text NOT NULL,
	`unidad` text DEFAULT 'un' NOT NULL,
	`permite_decimales` integer DEFAULT false NOT NULL,
	`costo_promedio` real DEFAULT 0 NOT NULL,
	`precio_venta` real DEFAULT 0 NOT NULL,
	`precio_minimo` real DEFAULT 0 NOT NULL,
	`stock_minimo` real DEFAULT 0 NOT NULL,
	`estado` text DEFAULT 'activo' NOT NULL,
	FOREIGN KEY (`empresa_id`) REFERENCES `empresas`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `productos_empresa_sku_idx` ON `productos` (`empresa_id`,`sku`);--> statement-breakpoint
CREATE TABLE `compras` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`empresa_id` integer NOT NULL,
	`sucursal_id` integer NOT NULL,
	`numero` text NOT NULL,
	`proveedor` text,
	`subtotal` real NOT NULL,
	`igv` real DEFAULT 0 NOT NULL,
	`flete` real DEFAULT 0 NOT NULL,
	`percepcion` real DEFAULT 0 NOT NULL,
	`total` real NOT NULL,
	`estado` text DEFAULT 'confirmada' NOT NULL,
	`fecha_creacion` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE TABLE `detalle_ventas` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`venta_id` integer NOT NULL,
	`producto_id` integer NOT NULL,
	`cantidad` real NOT NULL,
	`precio_unitario` real NOT NULL,
	`costo_unitario_historico` real NOT NULL,
	`total` real NOT NULL,
	FOREIGN KEY (`venta_id`) REFERENCES `ventas`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE TABLE `pagos_venta` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`venta_id` integer NOT NULL,
	`metodo` text NOT NULL,
	`monto` real NOT NULL,
	FOREIGN KEY (`venta_id`) REFERENCES `ventas`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE TABLE `ventas` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`uuid` text NOT NULL,
	`empresa_id` integer NOT NULL,
	`sucursal_id` integer NOT NULL,
	`numero` text NOT NULL,
	`cliente` text,
	`subtotal` real NOT NULL,
	`igv` real NOT NULL,
	`total` real NOT NULL,
	`estado` text DEFAULT 'pagada' NOT NULL,
	`fecha_creacion` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE TABLE `existencias` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`empresa_id` integer NOT NULL,
	`sucursal_id` integer NOT NULL,
	`producto_id` integer NOT NULL,
	`cantidad` real DEFAULT 0 NOT NULL,
	FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`producto_id`) REFERENCES `productos`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `existencias_sucursal_producto_idx` ON `existencias` (`sucursal_id`,`producto_id`);