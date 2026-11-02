# Diseño: venta por importe en productos pesables y auditoría funcional

Fecha: 2026-08-09

## Objetivo

Permitir que un cajero venda productos configurados en kilogramos indicando un importe monetario exacto, sin alterar el precio oficial por kilogramo. Además, realizar una auditoría funcional integral del ERP y entregar hallazgos priorizados con evidencia.

## Alcance funcional

La venta por importe estará disponible en:

- Punto de venta principal.
- Formulario de venta manual.
- Portal público únicamente cuando esté habilitado en modo tienda.

Los productos vendidos por unidad seguirán aceptando exclusivamente cantidades enteras. Los productos configurados en kilogramos admitirán dos modos por línea:

- Por peso: el cajero indica kilogramos.
- Por importe: el cajero indica soles.

## Regla de cálculo

Para un producto con precio `P` por kilogramo y un importe solicitado `M`:

1. Se calcula el peso teórico `M / P`.
2. El peso se redondea a tres decimales mediante redondeo comercial.
3. El inventario descuenta el peso redondeado.
4. El total de la línea conserva exactamente el importe solicitado.
5. El precio unitario histórico conserva el precio oficial por kilogramo.

Ejemplo: arroz a S/ 5.20/kg, importe solicitado S/ 2.00. La venta registra `0.385 kg`, precio oficial `S/ 5.20/kg` y total de línea `S/ 2.00`.

El total monetario explícito se almacenará como total de línea. No se modificará el precio unitario para forzar la igualdad matemática después del redondeo.

## Modelo de datos y contrato API

Las solicitudes de venta aceptarán opcionalmente en cada ítem:

- `quantity`: peso o unidades descontadas del inventario.
- `unit_price`: precio oficial aplicado.
- `line_total`: importe monetario exacto solicitado.
- `entry_mode`: `quantity` o `amount`.

El backend será la autoridad final. Para `entry_mode=amount` comprobará que:

- El producto permita cantidades decimales y esté configurado en kilogramos.
- `line_total` sea mayor que cero.
- `quantity` coincida con `round(line_total / unit_price, 3)`.
- La cantidad no supere el stock disponible.
- El precio unitario no sea inferior al mínimo permitido.

Para ventas por cantidad, `line_total` se calculará como hasta ahora. Una migración añadirá `entry_mode` a `sale_details`, con valor predeterminado `quantity`, para conservar la trazabilidad. El campo `total` existente seguirá siendo la fuente contable del importe de la línea; no se añadirá un segundo campo monetario redundante.

## Interfaz del POS

Cada línea pesable mostrará un selector compacto `Peso | Importe`:

- En modo Peso: entrada en kg y vista del subtotal calculado.
- En modo Importe: entrada en soles y vista inmediata del peso resultante.

La línea mostrará simultáneamente precio por kilogramo, peso a descontar y total exacto. Cambiar el precio, producto o modo recalculará los valores derivados. Los productos por unidad no mostrarán el selector.

Los pagos, promociones, descuentos y vuelto utilizarán la suma de los totales exactos de las líneas, no la multiplicación recalculada de cantidad por precio cuando la línea provenga de un importe.

## Comprobantes y reportes

El comprobante mostrará, para líneas por importe:

- Nombre del producto.
- Peso registrado.
- Precio por kilogramo.
- Importe exacto cobrado.

Los reportes de ventas sumarán `sale_details.total`. La utilidad se calculará como `total de línea - cantidad × costo histórico`, preservando el margen real cobrado. Kardex e inventario usarán exclusivamente `quantity`.

## Portal público

En modo tienda, los productos por kilogramo ofrecerán compra por peso o por importe con las mismas reglas. En modo catálogo no habrá controles de compra. Con el portal desactivado, las rutas públicas seguirán bloqueadas.

## Auditoría funcional integral

La revisión cubrirá:

1. Autenticación, sesiones, roles y permisos.
2. Maestros: empresas, sucursales, productos, categorías, marcas, unidades y almacenes.
3. Compras, costos, pagos y devoluciones.
4. POS, ventas manuales, comprobantes, anulaciones y notas.
5. Inventario, kardex y consistencia de stock.
6. Caja, movimientos, apertura y cierre.
7. Clientes, proveedores, gastos, promociones y pedidos web.
8. Portal público y sincronización con la empresa activa.
9. Reportes, exportaciones, auditoría y notificaciones.
10. Configuración, respaldos, archivos, mantenimiento e integraciones.
11. Gemini, OCR y tratamiento de datos enviados a proveedores externos.
12. Diseño responsive, errores de consola y flujos principales renderizados.

Cada hallazgo incluirá severidad, pasos de reproducción, componente afectado, evidencia, impacto y recomendación. La implementación inmediata se limitará al flujo de venta por importe y a defectos críticos que impidan probarlo; el resto se entregará priorizado para evitar cambios no relacionados sin revisión.

## Errores y casos límite

- Precio cero: se bloqueará el modo importe.
- Importe menor al mínimo operativo que redondee a `0.000 kg`: se rechazará.
- Stock insuficiente: se mostrará el máximo disponible y se impedirá cobrar.
- Producto por unidad: no admitirá modo importe.
- Promoción: se aplicará sobre el total exacto elegible.
- Pago combinado: la suma asignada deberá coincidir con el total final con tolerancia de un céntimo.
- Cambio de producto o modo: se limpiarán valores incompatibles para evitar datos obsoletos.

## Estrategia de pruebas

- Pruebas unitarias del cálculo importe-peso y redondeo.
- Pruebas API para venta por importe, stock insuficiente, unidad no permitida y total exacto.
- Prueba de persistencia: cantidad `0.385`, precio `5.20`, total `2.00`.
- Prueba de inventario: descuento exacto de `0.385 kg`.
- Prueba de comprobante y utilidad.
- Prueba renderizada del POS en escritorio y móvil, sin errores de consola.
- Pruebas completas de Laravel y compilación del frontend.

## Criterios de aceptación

- El cajero puede vender S/ 2.00 de un producto a S/ 5.20/kg.
- La interfaz muestra 0.385 kg antes del cobro.
- La base registra 0.385 kg y S/ 2.00.
- El inventario descuenta 0.385 kg.
- El comprobante y los reportes conservan S/ 2.00.
- Productos por unidad no ofrecen este modo.
- No se rompen ventas existentes por cantidad.
- La auditoría entrega un inventario priorizado de fallos y funciones incompletas.
