# Recurring and Variable Expenses Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Añadir obligaciones mensuales pendientes, gastos variables vinculados a lecturas y confirmación transaccional de pagos con caja.

**Architecture:** Laravel conservará `expenses` como libro de obligaciones y añadirá `recurring_expenses` como plantilla. `ExpenseController` concentrará generación idempotente, lectura variable y transición de pago; React consumirá un listado filtrable con resúmenes y acciones profesionales.

**Tech Stack:** PHP 8.3, Laravel, MySQL, React/TypeScript, vinext, pruebas Feature de Laravel.

## Global Constraints

- Los registros históricos permanecen disponibles y se migran como variables manuales pagados.
- Solo `paid` afecta la caja; `pending`, `overdue` y `cancelled` no la afectan.
- Una plantilla genera como máximo un gasto por periodo `AAAA-MM`.
- Una lectura genera como máximo un gasto.
- El día mensual de vencimiento está entre 1 y 28.
- No se eliminan físicamente registros financieros.

---

### Task 1: Esquema compatible y contrato de dominio

**Files:**
- Create: `backend/database/migrations/2026_08_09_000200_add_recurring_expense_management.php`
- Create: `backend/tests/Feature/ExpenseRecurringTest.php`

**Interfaces:**
- Produces: tabla `recurring_expenses`; columnas `expense_type`, `financial_status`, `period`, `due_date`, `paid_at`, `payment_method`, `payment_reference`, `recurring_expense_id`, `meter_reading_id` en `expenses`.

- [ ] **Step 1: Escribir pruebas que inspeccionen el esquema y la migración histórica**

```php
$this->assertTrue(Schema::hasColumn('expenses', 'financial_status'));
$this->assertDatabaseHas('expenses', ['id' => $legacyId, 'expense_type' => 'variable_manual', 'financial_status' => 'paid']);
```

- [ ] **Step 2: Ejecutar la prueba y comprobar que falla por columnas inexistentes**

Run: `php artisan test --filter=ExpenseRecurringTest`

- [ ] **Step 3: Crear migración con claves foráneas, índices únicos y backfill histórico**

```php
$table->unique(['recurring_expense_id', 'period']);
$table->unique('meter_reading_id');
DB::table('expenses')->update(['expense_type'=>'variable_manual','financial_status'=>'paid']);
```

- [ ] **Step 4: Ejecutar pruebas y revisar `artisan migrate:status`**

Run: `php artisan test --filter=ExpenseRecurringTest`

### Task 2: Generación mensual idempotente

**Files:**
- Modify: `backend/app/Http/Controllers/ExpenseController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/ExpenseRecurringTest.php`

**Interfaces:**
- Produces: `recurring(Request $r)`, `saveRecurring(Request $r, ?int $id=null)`, `generateRecurring(Request $r)`.
- Endpoint: `POST /api/v1/expenses/recurring/generate` con `{period:"2026-08"}`.

- [ ] **Step 1: Probar creación, actualización y generación doble de una plantilla**

```php
$this->postJson('/api/v1/expenses/recurring/generate', ['period'=>'2026-08'], $headers)->assertOk();
$this->postJson('/api/v1/expenses/recurring/generate', ['period'=>'2026-08'], $headers)->assertOk();
$this->assertDatabaseCount('expenses', 1);
```

- [ ] **Step 2: Ejecutar en rojo**

Run: `php artisan test --filter=ExpenseRecurringTest`

- [ ] **Step 3: Implementar validaciones, fecha de vencimiento e inserción `insertOrIgnore` transaccional**

```php
$dueDate = Carbon::createFromFormat('Y-m-d', $period.'-'.str_pad($template->due_day, 2, '0', STR_PAD_LEFT));
```

- [ ] **Step 4: Ejecutar en verde y verificar que plantillas inactivas o fuera de vigencia no generen gastos**

Run: `php artisan test --filter=ExpenseRecurringTest`

### Task 3: Lecturas variables y confirmación de pagos

**Files:**
- Modify: `backend/app/Http/Controllers/ExpenseController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/ExpenseRecurringTest.php`

**Interfaces:**
- Produces: `confirmPayment(Request $r, int $id)` y `cancel(Request $r, int $id)`.
- Extiende: `saveReading()` para crear obligación pendiente cuando `amount > 0`.

- [ ] **Step 1: Probar lectura con importe, lectura sin importe, lectura regresiva y duplicados**

```php
$this->postJson("/api/v1/meters/$meter/readings", ['reading_date'=>'2026-08-09','value'=>120,'amount'=>87.40], $headers)->assertCreated();
$this->assertDatabaseHas('expenses', ['meter_reading_id'=>$readingId,'financial_status'=>'pending','amount'=>87.40]);
```

- [ ] **Step 2: Probar pago único y movimiento exacto de caja**

```php
$this->postJson("/api/v1/expenses/$expense/confirm-payment", ['paid_at'=>'2026-08-09','amount'=>87.40,'payment_method'=>'cash'], $headers)->assertOk();
$this->assertDatabaseHas('cash_movements', ['type'=>'expense','amount'=>-87.40]);
```

- [ ] **Step 3: Ejecutar en rojo**

Run: `php artisan test --filter=ExpenseRecurringTest`

- [ ] **Step 4: Implementar ambas operaciones dentro de `DB::transaction`, bloqueando gasto y caja con `lockForUpdate()`**

```php
abort_unless(in_array($expense->financial_status, ['pending','overdue']), 422, 'El gasto no está pendiente');
```

- [ ] **Step 5: Ejecutar en verde**

Run: `php artisan test --filter=ExpenseRecurringTest`

### Task 4: Consultas, filtros y resúmenes mensuales

**Files:**
- Modify: `backend/app/Http/Controllers/ErpController.php`
- Modify: `backend/app/Http/Controllers/ExpenseController.php`
- Test: `backend/tests/Feature/ExpenseRecurringTest.php`

**Interfaces:**
- `GET /api/v1/expenses?period=2026-08&type=fixed&financial_status=pending`
- `GET /api/v1/expenses/summary?period=2026-08`

- [ ] **Step 1: Probar filtros por empresa, sucursal, periodo, tipo y estado**
- [ ] **Step 2: Probar resumen `pending`, `paid`, `overdue`, `fixed`, `variable` con totales exactos**
- [ ] **Step 3: Ejecutar en rojo**
- [ ] **Step 4: Implementar consultas agregadas y estado vencido derivado por `due_date < today()`**
- [ ] **Step 5: Ejecutar en verde**

Run: `php artisan test --filter=ExpenseRecurringTest`

### Task 5: Interfaz completa de Gestión de gastos

**Files:**
- Modify: `app/app/ExpenseManagementModule.tsx`
- Modify: `app/app/globals.css`

**Interfaces:**
- Consumes: endpoints de Tasks 2–4.
- Produces: pestañas Gastos, Recurrentes, Categorías, Servicios y lecturas.

- [ ] **Step 1: Añadir selector mensual y tarjetas de resumen**
- [ ] **Step 2: Añadir columnas Tipo, Periodo, Vencimiento y Estado financiero al listado**
- [ ] **Step 3: Construir formulario de plantilla fija con monto habitual, día 1–28 y vigencia**
- [ ] **Step 4: Añadir modal Confirmar pago con monto final, fecha, método, referencia y comprobante**
- [ ] **Step 5: Mostrar el vínculo desde cada lectura al gasto pendiente generado**
- [ ] **Step 6: Validar TSX con esbuild**

Run: `node --input-type=module -e "import fs from 'node:fs';import{transform}from'esbuild';await transform(fs.readFileSync('app/ExpenseManagementModule.tsx','utf8'),{loader:'tsx',jsx:'automatic'})"`

### Task 6: Seeder, migración real y regresión

**Files:**
- Modify: `backend/database/seeders/DemoDataSeeder.php`
- Test: `backend/tests/Feature/ExpenseRecurringTest.php`

**Interfaces:**
- Produces: plantillas de Internet y alquiler; medidores de luz y agua; gastos pendientes/pagados visibles en el mes actual.

- [ ] **Step 1: Añadir datos demostrativos idempotentes**
- [ ] **Step 2: Ejecutar `php artisan migrate --force` sin borrar datos existentes**
- [ ] **Step 3: Ejecutar suite Laravel completa**

Run: `php artisan test`

- [ ] **Step 4: Ejecutar validación sintáctica TSX y, con el servidor detenido, `npm run build`**
- [ ] **Step 5: Comprobar manualmente generación mensual, lectura variable y confirmación de pago contra MySQL**

## Self-review

- Cobertura: esquema, histórico, plantillas, generación, lecturas, pago/caja, filtros, resumen, UI, seeder y regresión están asignados a tareas.
- Consistencia: `expense_type` usa `fixed`, `variable_metered`, `variable_manual`; `financial_status` usa `pending`, `paid`, `overdue`, `cancelled`.
- Alcance: solo periodicidad mensual; no se incluyen automatizaciones bancarias, OCR ni proveedores externos.
