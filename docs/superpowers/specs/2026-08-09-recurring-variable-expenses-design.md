# Diseño: gastos recurrentes, variables y confirmación de pagos

Fecha: 2026-08-09

## Objetivo

Ampliar Gestión de gastos para separar gastos fijos recurrentes, gastos variables asociados a servicios medidos y gastos manuales. Ningún gasto recurrente o generado desde una lectura se considerará pagado hasta que un usuario confirme el importe y el pago.

## Clasificación

- **Fijo recurrente:** Internet, alquiler, software, vigilancia u otros conceptos cuyo importe normalmente se mantiene. Se define mediante una plantilla mensual.
- **Variable medido:** electricidad, agua, gas u otro servicio controlado mediante un medidor. Registrar una lectura con importe genera un gasto pendiente vinculado a esa lectura.
- **Variable manual:** mantenimiento, telefonía u otros importes que cambian pero no tienen medidor. Se registran directamente como pendientes.

Los gastos existentes se conservarán y se clasificarán inicialmente como variables manuales pagados, evitando alterar los totales históricos.

## Estados y efecto financiero

- `pending`: obligación registrada, todavía no afecta la caja.
- `paid`: pago confirmado; registra fecha, importe final, método, referencia, usuario y movimiento de caja cuando exista una sesión abierta.
- `overdue`: pendiente cuya fecha de vencimiento ya pasó. Se calculará al consultar o mediante una actualización segura, sin duplicar registros.
- `cancelled`: obligación anulada; no afecta la caja.

Un gasto solo podrá confirmarse una vez. La confirmación será transaccional: actualizar gasto y crear movimiento de caja ocurren juntos o no ocurre ninguno. Si el método implica caja y no hay sesión abierta, se informará al usuario antes de modificar el gasto.

## Gastos fijos recurrentes

Se añadirá una entidad de plantillas recurrentes con:

- empresa y sucursal;
- nombre, categoría y descripción;
- importe habitual;
- frecuencia mensual;
- día de vencimiento del 1 al 28;
- fecha de inicio y fecha final opcional;
- método de pago habitual opcional;
- estado activo/inactivo.

Al abrir el módulo o solicitar la generación mensual, el servidor creará las obligaciones faltantes hasta el mes actual. Una restricción única por plantilla y periodo `AAAA-MM` impedirá duplicados. Cada obligación comienza como pendiente y su importe puede editarse antes de confirmar el pago.

## Servicios variables y medidores

Al guardar una lectura con un importe mayor que cero:

1. se calcula el consumo respecto de la lectura cronológicamente anterior;
2. se crea un gasto pendiente del tipo variable medido;
3. se vincula de forma única con la lectura;
4. se usa el nombre del medidor, el periodo de la lectura y una categoría de servicio apropiada;
5. si la operación falla, ni la lectura ni el gasto quedan parcialmente registrados.

Una lectura sin importe se guarda normalmente y no genera gasto. Si posteriormente se añade el importe, se generará entonces la obligación. No se permitirá un segundo gasto para la misma lectura.

## Confirmación y edición

Antes del pago se podrá modificar:

- importe definitivo;
- fecha de vencimiento;
- categoría y descripción;
- comprobante adjunto.

Al confirmar se solicitará:

- fecha de pago;
- método: efectivo, Yape, tarjeta o transferencia;
- referencia opcional;
- importe definitivo;
- archivo de comprobante opcional.

Los gastos pagados no podrán cambiar directamente su importe ni volver a pagarse. Una corrección deberá anular el registro mediante una acción explícita y auditable.

## Interfaz

La pantalla tendrá cuatro pestañas:

1. **Gastos:** listado mensual con filtros por periodo, tipo, categoría y estado; acciones Editar, Confirmar pago y Anular.
2. **Recurrentes:** alta y edición de plantillas fijas; acción para generar pendientes del mes.
3. **Categorías:** administración existente.
4. **Servicios y lecturas:** medidores, historial de consumos e importes, y acceso al gasto generado.

El encabezado mostrará totales del periodo:

- pendiente;
- pagado;
- vencido;
- fijo;
- variable.

Los formularios usarán páginas o modales profesionales existentes, sin cuadros temporales del navegador.

## API y datos

Se extenderá `expenses` con tipo, estado financiero, periodo, vencimiento, fecha y método de pago, referencia, plantilla recurrente y lectura de medidor. Se añadirá una tabla de plantillas recurrentes.

Endpoints previstos:

- listado y guardado de plantillas;
- generación idempotente de periodos pendientes;
- listado de gastos con filtros y resumen;
- confirmación y anulación transaccional;
- guardado de lecturas con creación automática de gasto pendiente.

Todas las consultas estarán limitadas por empresa y, cuando corresponda, sucursal. Las acciones sensibles generarán eventos de auditoría.

## Validaciones y casos límite

- No duplicar plantilla/periodo ni lectura/gasto.
- Día de vencimiento limitado al 1–28 para funcionar en todos los meses.
- Importes positivos con dos decimales.
- Consumo no negativo; una lectura inferior a la anterior se rechazará para evitar resultados engañosos.
- No confirmar gastos anulados o ya pagados.
- No eliminar físicamente registros financieros.
- Las plantillas inactivas no generan periodos nuevos.
- Generar meses atrasados solo desde la fecha de inicio y nunca después de la fecha final.

## Pruebas

- Una plantilla mensual genera una sola obligación por periodo.
- Repetir la generación no crea duplicados.
- El gasto fijo queda pendiente y puede cambiar de importe antes del pago.
- Confirmar el pago registra exactamente un movimiento de caja.
- Confirmar nuevamente es rechazado.
- Una lectura con importe genera un gasto variable pendiente y vinculado.
- Una lectura sin importe no genera gasto.
- Una lectura duplicada o regresiva es rechazada.
- Los gastos históricos permanecen disponibles después de migrar.
- Los filtros y resúmenes mensuales separan fijo, variable, pendiente, pagado y vencido.

## Fuera de alcance

- Débitos bancarios automáticos.
- Lectura automática de recibos mediante OCR.
- Integración directa con empresas de electricidad o agua.
- Frecuencias semanales o anuales; podrán añadirse posteriormente sin cambiar el modelo mensual inicial.
