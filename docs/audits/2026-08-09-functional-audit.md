# Auditoría funcional del sistema — 2026-08-09

## Resultado ejecutivo

El núcleo transaccional está operativo sobre Laravel + MySQL: autenticación, catálogo, inventario/kardex, compras, POS, ventas, caja, clientes/proveedores, gastos, promociones, pedidos web, reportes, configuración, auditoría, archivos, copias de seguridad, integraciones y administración cuentan con rutas y pantallas conectadas. Laravel registra 191 rutas, 161 protegidas por autenticación/permisos. La suite automatizada pasa con 8 pruebas y 53 aserciones.

En esta revisión se completó la venta por importe para productos pesables en POS, venta manual y portal. Por ejemplo, S/ 2.00 a S/ 5.20/kg persiste 0.385 kg y conserva S/ 2.00 como total exacto. También se protegió el seguimiento público de pedidos: ahora requiere el número y el teléfono usado al comprar.

## Verificado y operativo

- Migraciones de MySQL aplicadas, incluida la trazabilidad `entry_mode` de cada detalle de venta.
- Venta por cantidad y venta por importe con validación del tipo de unidad, precio, peso calculado, stock, pagos y total.
- Persistencia exacta del total de línea en comprobantes, detalle de venta, pedidos web y conversión del pedido web a venta.
- Portal respetando estado activo/inactivo y modo tienda/catálogo desde configuración.
- Datos de demostración y flujos comerciales principales cubiertos por pruebas.
- Sintaxis TSX validada en POS, ventas manuales y portal.

## Pendientes relevantes antes de producción real

### Alta prioridad

1. **Comprobantes electrónicos SUNAT.** El sistema genera tickets/boletas/facturas internas, pero no se verificó envío real a SUNAT, firma digital, CDR, contingencia, notas electrónicas ni consulta de estado. No debe anunciarse como facturación electrónica homologada hasta integrar y certificar un proveedor/OSE.
2. **Precio confiado al cliente interno.** El endpoint de ventas acepta `unit_price` enviado por la interfaz. Esto permite precios manuales —útil para ciertos negocios—, pero requiere un permiso explícito y auditoría de cambio de precio; de lo contrario debe usar el precio del producto en servidor.
3. **Prueba de restauración de respaldos.** Existen pantallas/endpoints, pero una tienda real debe probar periódicamente una restauración completa en una base separada y registrar el resultado.
4. **Clave de Gemini expuesta en la conversación.** Aunque está en `.env`, debe revocarse y reemplazarse antes de producción. También debe informarse al usuario qué datos comerciales se envían al proveedor de IA.

### Prioridad media

1. La búsqueda global superior es actualmente decorativa: no ejecuta búsqueda ni navegación.
2. Hay componentes heredados/demostrativos dentro de `DeurstoreApp.tsx`; conviene eliminarlos para reducir mantenimiento y evitar que vuelvan a mostrarse accidentalmente.
3. La comprobación TypeScript global reporta errores previos en `CashManagementModule.tsx`, `CrmModule.tsx` y tipos del worker Cloudflare. No afectan las pruebas Laravel ni la sintaxis de las pantallas modificadas, pero deben corregirse para mantener CI estricto.
4. La compilación completa de Vite no pudo repetirse durante esta auditoría porque el proceso de desarrollo mantiene bloqueada `node_modules/.vite-temp` en Windows. Debe ejecutarse con el servidor frontend detenido.
5. Falta ampliar la cobertura automática: permisos por rol, anulaciones/devoluciones, cierres de caja, restauración, cargas de archivos, modos del portal y recorridos completos de cada pantalla.

## Recomendación de salida a producción

Antes de atender ventas reales: rotar Gemini, definir la política de modificación de precios, integrar/certificar SUNAT si se emitirán comprobantes electrónicos, probar restauración, ejecutar `npm run build` con el servidor detenido y realizar una jornada piloto con caja/impresora/balanza reales.
