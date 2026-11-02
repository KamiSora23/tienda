# Product Brands and Inventory Filters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Conectar Productos e Inventario con categorías y marcas reales, permitiendo productos genéricos y filtros combinables.

**Architecture:** Se conserva el esquema basado en nombres para evitar una migración incompatible. Laravel valida los nombres contra catálogos activos de la empresa y normaliza la ausencia de marca a `null`; React consume esos catálogos y filtra los registros de inventario sin alterar movimientos.

**Tech Stack:** Laravel 11, MySQL/SQLite de pruebas, React/TypeScript, Node test runner, Vinext/Vite.

## Global Constraints

- `Sin marca / Genérico` debe ser válido sin crear un registro artificial en `brands`.
- Categorías y marcas elegidas deben pertenecer a la empresa y estar activas.
- No agregar migraciones ni cambiar el esquema de `products`.
- Los filtros de inventario son solo de presentación y no modifican existencias.
- El directorio raíz no contiene `.git`; omitir pasos de commit hasta inicializar o recuperar el repositorio.

---

### Task 1: Validación y persistencia de marcas

**Files:**
- Modify: `backend/tests/Feature/ProductConfigurationTest.php`
- Modify: `backend/app/Http/Controllers/ErpController.php:50-58`
- Modify: `backend/app/Http/Controllers/AdminController.php:28`

**Interfaces:**
- Consumes: `POST /api/v1/products`, `POST /api/v1/admin/brands`.
- Produces: `products.brand: string|null` validado y marcas nuevas activas sin duplicados por empresa.

- [ ] **Step 1: Escribir pruebas backend que fallen**

Agregar casos que envíen un producto sin `brand`, otro con `brand => Costeño`, otro con marca inventada, y una creación administrativa sin `status`. Comprobar respectivamente `200`, persistencia de `Costeño`, error `422` en `brand`, y marca creada con estado `active`. Agregar además una segunda creación con el mismo nombre y esperar `422`.

- [ ] **Step 2: Ejecutar las pruebas y confirmar el fallo correcto**

Run: `C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe artisan test --filter=ProductConfigurationTest`

Expected: los casos de marca fallan porque `saveProduct` descarta `brand` y `AdminController` no asigna estado ni evita duplicados.

- [ ] **Step 3: Implementar la validación mínima**

En `saveProduct`, añadir una regla nullable basada en `Rule::exists('brands', 'name')` restringida por `company_id` y `status=active`. Antes de construir el payload, convertir `''` en `null`. En `AdminController::store`, para `brands`, validar `name` con una regla única por empresa, aplicar `status=active` cuando falte y normalizar espacios.

- [ ] **Step 4: Ejecutar nuevamente la prueba focalizada**

Run: `C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe artisan test --filter=ProductConfigurationTest`

Expected: PASS.

---

### Task 2: Contrato frontend de marcas y filtros

**Files:**
- Modify: `app/tests/theme-product-contract.test.mjs`
- Modify: `app/app/OperationalModules.tsx:15-24`
- Modify: `app/app/OperationalModules.tsx:53-59`
- Modify: `app/app/globals.css`

**Interfaces:**
- Consumes: `GET /api/v1/admin/categories`, `GET /api/v1/admin/brands`, `GET /api/v1/products`.
- Produces: selector `name="brand"`, modal de marca y controles `inventory-search`, `inventory-category`, `inventory-brand`, `inventory-warehouse`.

- [ ] **Step 1: Escribir contratos frontend que fallen**

Agregar pruebas del contenido real de `OperationalModules.tsx` que exijan: solicitud a `/admin/brands`; selector `name="brand"`; texto `Sin marca / Genérico`; POST a `/admin/brands`; y los cuatro nombres/identificadores de filtros de inventario junto con carga de categorías y marcas.

- [ ] **Step 2: Ejecutar contratos y confirmar el fallo correcto**

Run: `node --test tests/theme-product-contract.test.mjs` desde `app`.

Expected: FAIL porque todavía no hay selector de marca ni filtros de inventario.

- [ ] **Step 3: Implementar marcas en ProductModule**

Extender el estado con `brands`, `brandOpen` y `brandName`. Cargar productos, categorías y marcas con `Promise.all`; conservar solo catálogos activos. Incluir `brand` en búsqueda y columna de tabla. Añadir un selector opcional cuya opción vacía sea `Sin marca / Genérico`, acompañado por `＋ Nueva`. El modal hará `POST /admin/brands` con `{name: brandName, status:'active'}`, cerrará, recargará y notificará.

- [ ] **Step 4: Implementar filtros en InventoryModule**

Agregar estados `inventorySearch`, `categoryFilter`, `brandFilter`, `warehouseFilter`, además de catálogos. Crear predicados locales que obtengan categoría/marca desde el producto asociado y almacén desde `warehouse_id` o `warehouse_name`. Aplicarlos por pestaña a kardex, lotes, reservas y alertas. Renderizar una barra con búsqueda y tres selectores. Añadir columnas Categoría y Marca, usando `Genérico` si la marca es nula. Etiquetar las opciones de producto como `nombre · categoría · marca`.

- [ ] **Step 5: Añadir estilos mínimos**

Reutilizar la apariencia de `.table-tools` y `.category-picker`; añadir una cuadrícula responsiva `.inventory-filters` para que los cuatro controles no desborden en pantallas pequeñas.

- [ ] **Step 6: Ejecutar contratos frontend**

Run: `node --test tests/theme-product-contract.test.mjs` desde `app`.

Expected: PASS.

---

### Task 3: Datos de inventario enriquecidos

**Files:**
- Create: `backend/tests/Feature/InventoryCatalogTest.php`
- Modify: `backend/app/Http/Controllers/ErpController.php:121`
- Modify: `backend/app/Http/Controllers/InventoryController.php:8-12`

**Interfaces:**
- Consumes: relaciones existentes `inventory_movements.product_id`, `lots.product_id`, `stocks.product_id`.
- Produces: campos `category`, `brand`, `warehouse_id` y `warehouse_name` donde correspondan.

- [ ] **Step 1: Escribir pruebas API que fallen**

Autenticar al administrador sembrado y comprobar que `/inventory/kardex`, `/inventory/lots` y `/inventory/alerts` incluyan `category` y `brand`; comprobar además almacén en kardex/alertas cuando sea aplicable.

- [ ] **Step 2: Ejecutar la prueba focalizada**

Run: `C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe artisan test --filter=InventoryCatalogTest`

Expected: FAIL porque las consultas actuales solo seleccionan nombre/SKU y omiten catálogo.

- [ ] **Step 3: Enriquecer las consultas**

En kardex seleccionar `p.category`, `p.brand` y unir `warehouses` por `m.warehouse_id`. En lotes seleccionar `p.category` y `p.brand`. En alertas de stock unir almacenes y seleccionar IDs/nombres de almacén junto a categoría/marca; en vencimientos seleccionar también los campos del producto y almacén. Mantener todos los filtros existentes por empresa y sucursal.

- [ ] **Step 4: Ejecutar la prueba focalizada**

Run: `C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe artisan test --filter=InventoryCatalogTest`

Expected: PASS.

---

### Task 4: Verificación integral

**Files:**
- Verify: `backend/tests/Feature/*.php`
- Verify: `app/tests/*.test.mjs`
- Verify: aplicación frontend completa.

**Interfaces:**
- Consumes: entregables de Tasks 1-3.
- Produces: evidencia de regresión y compilación lista para Laragon.

- [ ] **Step 1: Ejecutar toda la suite Laravel**

Run: `C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe artisan test` desde `backend`.

Expected: todas las pruebas PASS.

- [ ] **Step 2: Ejecutar todas las pruebas frontend**

Run: `node --test tests/*.test.mjs` desde `app`.

Expected: todas las pruebas PASS.

- [ ] **Step 3: Compilar producción**

Run: `C:\Program Files\nodejs\npm.cmd run build` desde `app`.

Expected: Vinext/Vite completa todas las etapas sin errores TypeScript ni de bundling.

- [ ] **Step 4: Revisión manual de aceptación**

Crear un producto genérico y otro con marca; verificar búsqueda por marca en Productos. En Inventario combinar categoría, marca y almacén, cambiar de pestaña y confirmar que tablas y formularios muestran los mismos datos sin modificar stock.
