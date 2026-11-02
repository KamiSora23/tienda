# Diseño: marcas de productos y filtros de inventario

## Objetivo

Conectar Productos e Inventario con los catálogos administrativos reales de categorías y marcas. Los productos podrán carecer de marca y se mostrarán como `Sin marca / Genérico`, sin crear una marca artificial en la base de datos.

## Alcance

### Productos

- Cargar en paralelo productos, categorías activas y marcas activas.
- Sustituir cualquier entrada libre de marca por un selector.
- La primera opción será `Sin marca / Genérico` y enviará un valor vacío.
- Permitir crear una marca activa desde el formulario de producto mediante un modal `Nueva marca`.
- Recargar el catálogo después de crear la marca y dejarla disponible para selección.
- Mostrar marca en la lista de productos y contemplar categoría y marca en la búsqueda local.
- Al editar, conservar la categoría y marca actuales.

### Inventario

- Cargar productos, categorías activas, marcas activas y almacenes reales.
- Añadir filtros combinables por texto, categoría, marca y almacén.
- `Todas las categorías`, `Todas las marcas` y `Todos los almacenes` representarán ausencia de filtro.
- La opción `Sin marca / Genérico` permitirá filtrar productos cuyo campo de marca esté vacío.
- Mostrar categoría y marca en las vistas que contienen productos: kardex, lotes, reservas y alertas.
- En formularios de lote, ajuste, conteo y transferencia, identificar cada producto como `Nombre · Categoría · Marca`, usando `Genérico` cuando corresponda.
- Los filtros solo cambiarán la presentación; no modificarán existencias ni movimientos.

## Backend y persistencia

- Mantener el esquema actual, donde `products.category` y `products.brand` almacenan nombres. No se agregarán migraciones ni claves foráneas en este cambio.
- `category` seguirá siendo obligatoria y deberá corresponder a una categoría activa de la misma empresa.
- `brand` será opcional. Si llega vacía se normalizará a `null`; si contiene un valor deberá corresponder a una marca activa de la misma empresa.
- La creación administrativa de marcas validará nombre, asignará estado activo por defecto y evitará nombres duplicados dentro de la empresa.
- Las consultas de inventario que devuelven productos incorporarán categoría y marca cuando todavía no estén presentes, respetando el aislamiento por empresa.

## Flujo de datos

1. El frontend solicita los productos y catálogos autorizados con el token del usuario.
2. El usuario selecciona una categoría y opcionalmente una marca existente, o crea una marca.
3. El backend valida ambos nombres dentro de la empresa del usuario antes de guardar.
4. Inventario obtiene categoría y marca desde el producto asociado a cada movimiento.
5. Los filtros se aplican de forma combinada sobre los datos cargados, incluyendo almacén cuando el registro lo contiene.

## Errores y estados vacíos

- Si no existen marcas, el producto se podrá guardar como genérico y seguirá disponible el botón para crear una marca.
- Si una marca fue desactivada entre la carga y el guardado, el backend rechazará el producto con un error de validación comprensible.
- Si falla la carga de alguno de los catálogos, se mostrará la notificación de error existente y no se inventarán valores locales.
- Los registros antiguos con marca vacía se presentarán como `Genérico`.

## Pruebas y aceptación

- Prueba backend: acepta un producto sin marca.
- Prueba backend: acepta una marca activa de la empresa.
- Prueba backend: rechaza una marca inexistente, inactiva o de otra empresa.
- Prueba backend: crear una marca sin estado explícito genera una marca activa y evita duplicados.
- Prueba frontend: Productos consulta marcas, usa un selector y ofrece creación de marca.
- Prueba frontend: Inventario consulta categorías y marcas, expone los cuatro filtros y muestra ambos datos.
- Ejecutar la suite completa de Laravel, las pruebas de contrato frontend y la compilación de producción.

## Fuera de alcance

- Migrar `products` a `category_id` y `brand_id`.
- Edición masiva de marcas.
- Imágenes o logotipos de marca dentro del selector.
- Cambios en precios, stock o reglas de venta.
