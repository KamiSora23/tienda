# Venta por importe para productos pesables — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Registrar ventas de productos por kilogramo desde un importe exacto, conservando el total monetario solicitado y descontando del stock el peso redondeado a tres decimales.

**Architecture:** El backend será la autoridad de cálculo y validación. `sale_details.total` conservará el importe exacto y una nueva columna `entry_mode` registrará si la línea nació de cantidad o importe. Las tres interfaces de venta usarán el mismo contrato `quantity`, `unit_price`, `line_total`, `entry_mode`.

**Tech Stack:** Laravel 11, MySQL/SQLite de pruebas, React/TypeScript, vinext/Vite.

## Global Constraints

- Peso redondeado comercialmente a tres decimales.
- Total exacto en moneda con dos decimales.
- El precio oficial por kilogramo no se modifica.
- Productos por unidad no admiten `entry_mode=amount`.
- No hay repositorio Git; cada tarea termina con una verificación reproducible en lugar de commit.

---

### Task 1: Contrato y persistencia backend

**Files:**
- Create: `backend/database/migrations/2026_08_09_000100_add_entry_mode_to_sale_details.php`
- Modify: `backend/app/Http/Controllers/ErpController.php`
- Test: `backend/tests/Feature/WeightedAmountSaleTest.php`

**Interfaces:**
- Consumes item `{product_id, quantity, unit_price, line_total?, entry_mode?}`.
- Produces `sale_details.entry_mode` y `sale_details.total` exacto.

- [ ] Escribir una prueba que venda S/ 2.00 de arroz a S/ 5.20/kg y espere `quantity=0.385`, `unit_price=5.20`, `total=2.00`.
- [ ] Añadir pruebas negativas para producto por unidad, discrepancia de cantidad y stock insuficiente.
- [ ] Ejecutar `php artisan test --filter=WeightedAmountSaleTest` y confirmar que falla antes del cambio.
- [ ] Crear migración con `string('entry_mode', 20)->default('quantity')`.
- [ ] Validar `items.*.entry_mode` en `quantity,amount` y `items.*.line_total` como decimal positivo opcional.
- [ ] Para modo importe, recalcular `round(line_total / unit_price, 3)`, exigir coincidencia con `quantity` y persistir `line_total` redondeado a dos decimales.
- [ ] Para modo cantidad, persistir `round(quantity * unit_price, 2)`.
- [ ] Usar los totales de línea para subtotal, promociones, descuento y total de venta.
- [ ] Ejecutar la prueba específica y luego toda la suite Laravel.

### Task 2: Punto de venta

**Files:**
- Modify: `app/app/OperationalModules.tsx`
- Modify: `app/app/globals.css`

**Interfaces:**
- Cada línea del carrito incluye `entry_mode: 'quantity'|'amount'` y `line_total: number`.
- La API recibe el contrato definido en Task 1.

- [ ] Inicializar productos por kg en modo cantidad y calcular `line_total`.
- [ ] Mostrar selector `Peso | Importe` únicamente para `unit_type==='kg'`.
- [ ] En modo importe, convertir `amount / sale_price` a tres decimales y conservar el importe a dos decimales.
- [ ] En modo peso, recalcular el importe desde la cantidad.
- [ ] Sumar el carrito usando `line_total` y enviar `entry_mode` y `line_total` al backend.
- [ ] Mostrar precio/kg, peso descontado y total exacto en cada línea.
- [ ] Validar importe positivo, peso no nulo y stock suficiente antes de cobrar.
- [ ] Compilar frontend y probar el flujo renderizado en navegador.

### Task 3: Venta manual administrativa

**Files:**
- Modify: `app/app/SalesModule.tsx`
- Modify: `app/app/globals.css`

- [ ] Ampliar las líneas manuales con `entry_mode` y `line_total`.
- [ ] Añadir selector y cálculo por importe para productos por kg.
- [ ] Mantener cantidades enteras para productos por unidad.
- [ ] Calcular pago y total visual desde `line_total`.
- [ ] Enviar el contrato completo a `/sales`.
- [ ] Compilar y verificar creación de venta manual.

### Task 4: Portal en modo tienda

**Files:**
- Modify: `app/app/portal/page.tsx`
- Modify: `app/app/portal/portal.css`
- Modify: `backend/app/Http/Controllers/PortalController.php`
- Test: `backend/tests/Feature/PortalWeightedAmountTest.php`

- [ ] Añadir el mismo contrato de línea al carrito público.
- [ ] Permitir peso o importe solo cuando `portal.mode==='shop'`.
- [ ] Validar y recalcular en Laravel; no confiar en el cálculo del navegador.
- [ ] Guardar `quantity`, `unit_price`, `total` y `entry_mode` en el JSON del pedido.
- [ ] Verificar que modo catálogo siga sin controles de compra.
- [ ] Ejecutar pruebas y compilación.

### Task 5: Comprobantes, reportes y regresión

**Files:**
- Modify: `backend/app/Http/Controllers/ErpController.php`
- Modify: `backend/app/Http/Controllers/SystemController.php` si algún reporte recalcula cantidad por precio.
- Modify: `app/app/OperationalModules.tsx`
- Modify: `app/app/SalesModule.tsx`

- [ ] Confirmar que tickets muestran peso, precio por kg y total persistido.
- [ ] Confirmar que utilidad usa `sale_details.total - quantity * historical_unit_cost`.
- [ ] Buscar y reemplazar cálculos contables que ignoren `sale_details.total`.
- [ ] Ejecutar migraciones, pruebas Laravel y build.
- [ ] Ejecutar smoke test POS: arroz S/ 2.00 → 0.385 kg → total S/ 2.00.

