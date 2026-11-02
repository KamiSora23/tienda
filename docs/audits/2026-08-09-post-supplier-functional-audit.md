# Diagnóstico integral posterior a producto-proveedores — 2026-08-09

## Resumen ejecutivo

El núcleo transaccional compila y las pruebas Laravel pasan. La aplicación registra 202 rutas, 199 bajo `api/v1`, 28 migraciones, 24 controladores y 27 archivos TSX. La suite backend concluyó con **26 pruebas y 156 aserciones aprobadas**. `vinext build` completó sus cinco fases correctamente.

La relación producto-proveedores quedó operativa: admite varios proveedores, principal único, costo/fecha más recientes, configuración manual, sincronización en compra confirmada, ausencia de asociación en compras pendientes y bloqueo entre empresas. Compras prioriza los productos del proveedor sin ocultar el resto.

La aplicación todavía **no debe publicarse en Internet ni operar como facturación electrónica homologada** hasta resolver los hallazgos altos siguientes.

## Implementado y verificado en esta revisión

- Tabla aditiva `product_suppliers` con unicidad por empresa/producto/proveedor.
- Reconstrucción de asociaciones desde compras confirmadas existentes.
- Primer proveedor confirmado como principal; proveedores posteriores no lo sustituyen.
- Compra directa y confirmación de pendiente sincronizan proveedor, último costo y fecha dentro de la transacción.
- Compra pendiente y compra sin proveedor no crean asociaciones.
- API para asociar, editar, activar/desactivar y cambiar proveedor principal.
- Detalle de producto y CRM de proveedor consultan la misma relación.
- Seeder demostrativo con productos distribuidos por más de un proveedor.
- Categoría, marca, almacén e inventario continúan cubiertos por la suite completa.

## Evidencia de ejecución

- `php artisan test --compact`: 26 aprobadas, 156 aserciones, salida 0.
- `node --test tests/product-suppliers-contract.test.mjs`: 2 aprobadas, salida 0.
- `npm run build`: cinco fases completadas, salida 0.
- `node --test tests/*.test.mjs`: 6 aprobadas y 2 fallidas; las dos fallas pertenecen a `rendered-html.test.mjs`, que todavía busca `_sites-preview/SkeletonPreview.tsx` y un metadato `codex-preview` eliminados.
- El escaneo estándar del plugin de seguridad no produjo `scanId` ni contexto después de más de un minuto y la llamada bloqueada fue detenida. Por ello los hallazgos de seguridad siguientes son una revisión estática secuencial, no un escaneo canónico completo del plugin.

## Hallazgos de prioridad alta

### 1. Mutaciones de inventario sin aislamiento de empresa/sucursal

`InventoryController.php:9-11` acepta identificadores de almacén y producto como enteros y consulta/modifica `stocks` únicamente por esos IDs. Un usuario autenticado con permiso de inventario que conozca IDs de otra empresa podría registrar lotes, ajustar existencias o transferir stock ajeno.

**Recomendación:** validar almacenes por `company_id` y sucursal, productos por `company_id`, y agregar `company_id` a cada consulta bloqueada de stock. Añadir pruebas negativas multiempresa para lote, ajuste, conteo y transferencia.

### 2. Descarga de respaldos sin filtro de empresa

`SystemController.php:38` obtiene `backup_records` solo por `id`, mientras listado y restauración sí consideran empresa. Un usuario con acceso a respaldos podría descargar un archivo registrado por otra empresa mediante enumeración de ID.

**Recomendación:** exigir `id + company_id + status=completed`, comprobar permiso administrativo explícito y añadir prueba de IDOR multiempresa.

### 3. Los respaldos por empresa contienen/restauran la base global

`SystemController.php:37-39` ejecuta `mysqldump` y `mysql` sobre toda `DB_DATABASE`, pero etiqueta el archivo con una empresa. En una instalación multiempresa, un administrador de una tienda podría obtener o restaurar información de todas las empresas. Además, la ruta de Laragon/MySQL está codificada y no se pasa la contraseña configurada, por lo que el flujo depende del entorno local.

**Recomendación:** restringir respaldo/restauración global al superadministrador de plataforma o crear exportaciones realmente aisladas por empresa. Ejecutar restauraciones únicamente en un proceso administrativo fuera de la petición web y probarlas en una base separada.

### 4. Credenciales administrativas demostrativas precargadas en producción frontend

`DeurstoreApp.tsx:162` precarga correo y contraseña conocidos (`admin@deurstore.pe` / contraseña demo). También aparecen en seeder y documentación. Si una instancia conserva esas credenciales, cualquier visitante conoce el acceso inicial.

**Recomendación:** no colocar contraseña por defecto en el formulario, exigir cambio en el primer inicio, generar contraseña de instalación aleatoria y bloquear seeders demo en `production`.

### 5. Clave de Gemini expuesta previamente

La clave enviada en la conversación sigue figurando como configurada en `.env`. Debe considerarse comprometida aunque no se imprimió su valor durante el diagnóstico.

**Recomendación:** revocarla en Google AI Studio, crear una nueva, limpiar historiales/archivos donde aparezca y documentar qué información comercial se envía a Gemini.

### 6. Precios de venta confiados al cliente

`ErpController.php:102` acepta `items.*.unit_price` y `line_total` desde el frontend. Un usuario con permiso de venta puede alterar precios sin un permiso específico de descuento/precio ni un límite derivado del catálogo.

**Recomendación:** calcular precio en servidor, permitir modificación solo con permiso explícito, respetar `minimum_price` y auditar usuario, precio anterior, nuevo y motivo.

## Hallazgos de prioridad media

### 1. Prueba de integración webhook permite destinos arbitrarios

`IntegrationController.php:16-18` guarda una URL y el servidor realiza un POST sin bloquear localhost, redes privadas o metadatos de nube. Un administrador con permiso de integraciones podría utilizarlo como SSRF.

**Recomendación:** aceptar solo HTTPS, resolver DNS, bloquear rangos privados/loopback/link-local, controlar redirecciones y aplicar allowlist cuando sea posible.

### 2. Facturación SUNAT no está implementada de extremo a extremo

Existe una prueba de autenticación SUNAT, pero las ventas generan documentos internos. No hay evidencia de firma UBL, envío CPE, CDR, estados, contingencia ni homologación OSE/SUNAT.

**Recomendación:** no anunciar “facturación electrónica” hasta completar y certificar el flujo legal.

### 3. Suite frontend principal contiene pruebas obsoletas

`rendered-html.test.mjs` depende de un preview eliminado y hace fallar `npm test` aunque el build sea correcto.

**Recomendación:** reemplazar esas pruebas por render del login/aplicación actual y agregar los contratos funcionales al script `npm test`/CI.

### 4. Cobertura automática insuficiente respecto al alcance

Hay 202 rutas y 374 líneas no vacías en el catálogo de vistas, pero solo 26 pruebas backend y contratos frontend principalmente estructurales. Faltan recorridos multiempresa, permisos por rol, respaldos, archivos, OCR, integraciones, reportes, cierres de caja y cada vista administrativa.

### 5. Configuración local no es adecuada para producción

El entorno revisado usa `APP_ENV=local` y `APP_DEBUG=true`. Esto es correcto para Laragon local, pero no para exposición pública.

**Recomendación:** usar variables de producción, `APP_DEBUG=false`, HTTPS, cookies seguras, colas/scheduler supervisados y almacenamiento fuera del webroot.

## Prioridad baja / deuda técnica

- `OperationalModules.tsx`, `AdvancedOperationsController.php` y otros controladores contienen muchas responsabilidades en archivos/líneas muy densas, dificultando revisión y mantenimiento.
- La búsqueda global superior continúa sin evidencia de búsqueda unificada completa.
- Varias vistas obligatorias están agrupadas en módulos y modales en vez de rutas/páginas independientes; funcionalmente puede ser suficiente, pero no existe una matriz automatizada vista → ruta → permiso → prueba.
- La configuración SMTP se cambia dinámicamente dentro de la petición; conviene aislarla en un servicio y cola.

## Estado por área

- **Operativo con pruebas:** autenticación básica, productos/categorías/marcas, producto-proveedores, compras confirmadas/pendientes, inventario/kardex básico, POS y ventas ponderadas, gastos recurrentes/variables, portal configurable y build.
- **Operativo pero con cobertura parcial:** roles/permisos, CRM, promociones, caja avanzada, devoluciones/anulaciones, pedidos web, reportes, auditoría, archivos y notificaciones.
- **Dependiente de entorno o externo:** Gemini/OCR, SMTP, WhatsApp, SUNAT, webhooks, respaldos/restauración y procesos programados.
- **No certificado para producción:** facturación electrónica, restauración multiempresa segura y seguridad de red de integraciones.

## Orden recomendado de trabajo

1. Corregir aislamiento multiempresa de inventario y descarga/restauración de respaldos.
2. Rotar Gemini y eliminar credenciales demo del formulario.
3. Endurecer política de precios y webhook SSRF.
4. Reparar `npm test` y crear pruebas multiempresa/roles.
5. Integrar y certificar SUNAT si se emitirán comprobantes electrónicos.
6. Ejecutar piloto local con caja, impresora, balanza y restauración ensayada antes de usar datos reales.
