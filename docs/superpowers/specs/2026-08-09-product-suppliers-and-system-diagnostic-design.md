# Diseño: producto-proveedores y diagnóstico integral

## Objetivo

Implementar una relación comercial muchos-a-muchos entre productos y proveedores, conectarla con compras confirmadas e inventario, y ejecutar después un diagnóstico integral independiente del sistema.

## Modelo de datos

Se creará `product_suppliers` con:

- `company_id`, `product_id` y `supplier_id`.
- `supplier_sku`, código opcional usado por el proveedor.
- `last_cost`, costo de la compra confirmada más reciente.
- `last_purchase_at`, fecha de dicha compra.
- `minimum_order_quantity`, cantidad mínima opcional.
- `lead_time_days`, plazo de entrega opcional en días.
- `is_primary`, indicador de proveedor principal.
- `status`, `active` o `inactive`.
- Marcas de tiempo.

La combinación empresa-producto-proveedor será única. Solo podrá existir un proveedor principal activo por producto dentro de la empresa.

## Reglas comerciales

- Un producto puede estar asociado con uno o varios proveedores.
- El primer proveedor asociado mediante una compra confirmada se convertirá automáticamente en principal.
- Las asociaciones posteriores no reemplazarán al principal existente.
- El proveedor principal podrá cambiarse manualmente; el cambio desmarcará al principal anterior dentro de una transacción.
- Una compra pendiente no creará ni actualizará relaciones.
- Al confirmar una compra pendiente, o registrar una compra directamente como confirmada, se creará o actualizará la relación de cada producto con el proveedor seleccionado.
- La actualización automática guardará `last_cost` y `last_purchase_at`, reactivará una relación inactiva y conservará los campos configurados manualmente.
- Una compra sin proveedor actualizará inventario normalmente, pero no creará asociaciones.
- Anular o devolver una compra revertirá inventario según las reglas actuales, sin borrar la relación histórica.
- Producto y proveedor deben pertenecer a la misma empresa del usuario.

## Compras

Al seleccionar un proveedor, el selector de productos mostrará primero sus productos activos asociados y luego el resto del catálogo. Todos permanecerán disponibles. Cada opción indicará si el producto ya es suministrado por el proveedor, su último costo y su condición de principal cuando corresponda.

Seleccionar un producto no creará la relación. Esta aparecerá solamente al confirmar el ingreso al inventario. Para productos asociados, el formulario podrá sugerir el último costo del proveedor; el usuario podrá modificarlo para la compra actual.

Las listas y detalles de compra mostrarán el nombre del proveedor. El backend validará el proveedor seleccionado, en vez de aceptar cualquier identificador entero.

## Productos

El detalle del producto tendrá una sección `Proveedores` con:

- Nombre y documento del proveedor.
- Código del proveedor.
- Último costo y fecha de última compra.
- Cantidad mínima y plazo de entrega.
- Indicador principal y estado.
- Acciones para asociar, editar, establecer como principal y desactivar/reactivar.

No se permitirá asociar dos veces la misma combinación. La administración manual podrá crear una relación antes de la primera compra, pero no inventará un último costo ni una fecha de compra.

## Proveedores

El detalle CRM del proveedor utilizará `product_suppliers` como catálogo configurado y combinará la información con su historial real de compras. Mostrará productos activos e inactivos, condición principal, último costo, última compra, cantidad acumulada histórica y campos comerciales.

## API y componentes

- Endpoints de consulta y mantenimiento de proveedores por producto.
- Endpoint transaccional para cambiar proveedor principal.
- Respuesta de productos enriquecida con asociaciones cuando el flujo de compras la solicite.
- Servicio backend reutilizable para sincronizar relaciones desde compras confirmadas, evitando duplicar lógica entre compra directa y confirmación de pendientes.
- Componentes de interfaz en el detalle de producto y el formulario de compra, siguiendo los modales y tablas existentes.

## Errores y seguridad funcional

- Todas las consultas se limitarán por `company_id`.
- Los identificadores de producto y proveedor se validarán contra la empresa autenticada.
- Una relación de otra empresa se comportará como inexistente.
- Operaciones concurrentes conservarán un único proveedor principal mediante transacciones y restricciones de unicidad aplicables.
- Los errores de validación se mostrarán mediante el sistema de notificaciones existente.

## Migración y datos actuales

La migración será aditiva y no alterará compras ni productos existentes. Un proceso de backfill insertará relaciones a partir de compras confirmadas históricas con proveedor, usando el costo y fecha de la compra más reciente; el proveedor confirmado más antiguo quedará como principal inicial cuando no exista otro. El backfill será idempotente.

Los seeders incluirán varios productos con dos o más proveedores para que la interfaz poblada muestre comparaciones reales.

## Pruebas de aceptación

- Compra pendiente no crea relación.
- Confirmar compra pendiente crea relación y aumenta stock.
- Compra confirmada directa crea o actualiza relación.
- Compra sin proveedor no crea relación.
- Primera relación queda como principal; compras posteriores no la reemplazan.
- Cambio manual conserva un solo principal.
- Último costo y fecha se actualizan con la compra confirmada más reciente.
- Proveedor ajeno a la empresa es rechazado.
- Productos asociados aparecen primero en Compras y los demás siguen disponibles.
- Producto y proveedor muestran la misma asociación desde ambos detalles.
- Suite Laravel, contratos frontend y compilación pasan.

## Diagnóstico integral posterior

El diagnóstico se ejecutará después de verificar la relación producto-proveedores. Será una revisión separada y de solo lectura salvo pruebas locales reversibles. Cubrirá:

1. Estado de pruebas, compilación y configuración local.
2. Esquema, migraciones, seeders e integridad transaccional.
3. Aislamiento por empresa, sucursal y almacén.
4. Autenticación, sesiones, roles y permisos.
5. Productos, proveedores, compras, inventario y kardex.
6. Ventas, POS, clientes, comprobantes, caja y devoluciones.
7. Gastos, reportes, auditoría y configuración.
8. Portal público, pedidos, IA, archivos, respaldos e integraciones.
9. Correspondencia entre rutas, lógica y catálogo obligatorio de vistas.
10. Riesgos de seguridad funcional y preparación para producción.

El resultado actualizará `docs/audits` con evidencia, impacto, prioridad (`crítico`, `alto`, `medio`, `bajo`), archivos afectados y recomendación. El diagnóstico no afirmará que integraciones externas como SUNAT, Gemini o respaldos están operativas sin evidencia ejecutable.

## Fuera de alcance

- Órdenes de compra automáticas basadas en stock mínimo.
- Cotizaciones enviadas directamente a proveedores.
- Sincronización de catálogos mediante APIs externas de proveedores.
- Cuentas por pagar avanzadas o conciliación bancaria.
- Corregir automáticamente todos los hallazgos del diagnóstico posterior.
