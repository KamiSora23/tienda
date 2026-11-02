# Product Suppliers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir varios proveedores por producto y sincronizar la relación únicamente cuando una compra entra al inventario.

**Architecture:** Una tabla pivote `product_suppliers` conserva atributos comerciales por combinación. Un servicio `ProductSupplierService` centraliza validación, proveedor principal y sincronización desde compras para que compra directa y confirmación pendiente compartan la misma regla.

**Tech Stack:** Laravel 11, Query Builder, MySQL/SQLite de pruebas, React 19/TypeScript, Vinext.

## Global Constraints

- La asociación automática ocurre solo al confirmar entrada de inventario.
- El primer proveedor confirmado es principal y los siguientes no lo reemplazan.
- Compras sin proveedor no crean asociaciones.
- Anulaciones y devoluciones no borran la relación histórica.
- Toda lectura y escritura se limita por empresa.
- No hay repositorio Git en la raíz; se omiten commits.

---

### Task 1: Esquema, backfill y datos demostrativos

**Files:**
- Create: `backend/database/migrations/2026_08_09_000300_create_product_suppliers.php`
- Modify: `backend/database/seeders/DemoDataSeeder.php`
- Create: `backend/tests/Feature/ProductSupplierTest.php`

**Interfaces:**
- Produces: tabla `product_suppliers` con clave única `(company_id, product_id, supplier_id)`.
- Consumes: `companies`, `products`, `suppliers`, `purchases`, `purchase_details`.

- [ ] Escribir una prueba que ejecute migraciones/seed y compruebe dos proveedores para al menos un producto y exactamente un principal.
- [ ] Ejecutar `php artisan test --filter=ProductSupplierTest` y confirmar fallo por tabla inexistente.
- [ ] Crear columnas, claves foráneas, índices y restricción única. En `up()`, reconstruir relaciones desde compras confirmadas agrupadas por empresa/producto/proveedor; usar última compra/costo y marcar como principal el proveedor confirmado más antiguo del producto.
- [ ] Poblar DemoDataSeeder con relaciones idempotentes para productos y proveedores existentes.
- [ ] Ejecutar la prueba focalizada y confirmar PASS.

### Task 2: Servicio transaccional y API

**Files:**
- Create: `backend/app/Services/ProductSupplierService.php`
- Create: `backend/app/Http/Controllers/ProductSupplierController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Feature/ProductSupplierTest.php`

**Interfaces:**
- Produces: `syncConfirmedPurchase(int $companyId, object $purchase, iterable $details): void`.
- Produces endpoints: `GET/POST /products/{product}/suppliers`, `PUT /products/{product}/suppliers/{supplier}`, `POST /products/{product}/suppliers/{supplier}/primary`.

- [ ] Añadir pruebas para asociación manual, duplicado idempotente, rechazo entre empresas, edición, desactivación/reactivación y cambio de principal único.
- [ ] Ejecutar la prueba y confirmar fallos de rutas/servicio ausentes.
- [ ] Implementar el servicio con consultas restringidas por `company_id`; `syncConfirmedPurchase` debe actualizar `last_cost`, `last_purchase_at`, `status=active` y asignar principal solo si ninguno existe.
- [ ] Implementar controlador y rutas protegidas por permisos de productos/proveedores.
- [ ] Ejecutar prueba focalizada y confirmar PASS.

### Task 3: Integración con compras

**Files:**
- Modify: `backend/app/Http/Controllers/ErpController.php`
- Modify: `backend/app/Http/Controllers/AdvancedOperationsController.php`
- Modify: `backend/tests/Feature/ProductSupplierTest.php`

**Interfaces:**
- Consumes: `ProductSupplierService::syncConfirmedPurchase`.
- Produces: compras con `supplier_name`; relaciones sincronizadas solo después del ingreso real.

- [ ] Añadir pruebas: pendiente no asocia; confirmar pendiente asocia y suma stock; directa confirmada asocia; sin proveedor no asocia; proveedor de otra empresa devuelve 422; segunda relación no reemplaza principal.
- [ ] Ejecutar pruebas y confirmar fallos por comportamiento faltante.
- [ ] Validar `supplier_id` mediante existencia activa en la empresa en ambos controladores.
- [ ] Invocar el servicio después de procesar correctamente todos los detalles de compra confirmada, dentro de la misma transacción.
- [ ] Unir proveedor en lista/detalle de compras.
- [ ] Ejecutar pruebas focalizadas y confirmar PASS.

### Task 4: Producto y proveedor enriquecidos

**Files:**
- Modify: `backend/app/Http/Controllers/AdvancedOperationsController.php`
- Modify: `backend/app/Http/Controllers/CrmController.php`
- Modify: `backend/tests/Feature/ProductSupplierTest.php`

**Interfaces:**
- Produces: `productDetail.suppliers[]` y `supplierDetail.products[]` con los mismos atributos comerciales.

- [ ] Añadir pruebas de contrato JSON desde ambos detalles, incluyendo cantidad histórica y estado.
- [ ] Ejecutar prueba y confirmar ausencia de los campos.
- [ ] Enriquecer detalle de producto con `product_suppliers + suppliers`; enriquecer CRM del proveedor desde pivote y agregar cantidad histórica mediante subconsulta agregada.
- [ ] Ejecutar prueba focalizada y confirmar PASS.

### Task 5: Interfaz de compras y gestión desde producto

**Files:**
- Modify: `app/app/OperationalModules.tsx`
- Modify: `app/app/globals.css`
- Create: `app/tests/product-suppliers-contract.test.mjs`

**Interfaces:**
- Consumes: productos, proveedores, `productDetail.suppliers`, endpoints de asociación.
- Produces: productos ordenados por proveedor elegido y panel administrativo en detalle de producto.

- [ ] Crear contratos frontend que exijan selector controlado de proveedor, orden asociado-primero, etiqueta de último costo/principal y acciones de asociar/editar/principal/desactivar.
- [ ] Ejecutar `node --test tests/product-suppliers-contract.test.mjs` y confirmar FAIL.
- [ ] Convertir proveedor de compra en estado controlado. Ordenar productos sin ocultar no asociados y sugerir `last_cost` solo al elegir una línea.
- [ ] Añadir tabla y formulario de proveedores al detalle de producto, reutilizando modal, Field, Table y notificaciones.
- [ ] Mostrar proveedor en lista y detalle de compras.
- [ ] Añadir estilos responsivos mínimos y ejecutar el contrato hasta PASS.

### Task 6: Verificación funcional

**Files:**
- Verify: `backend/tests/Feature/*.php`
- Verify: `app/tests/product-suppliers-contract.test.mjs`
- Verify: `app` build.

**Interfaces:**
- Produces: evidencia lista para el diagnóstico integral.

- [ ] Ejecutar `php artisan migrate:fresh --seed` sobre entorno de prueba/local autorizado.
- [ ] Ejecutar `php artisan test` y registrar total de pruebas/aserciones.
- [ ] Ejecutar contratos frontend relevantes.
- [ ] Ejecutar `npm run build` y confirmar salida 0.
- [ ] Probar manualmente compra pendiente, confirmación, proveedor principal y segundo proveedor.
