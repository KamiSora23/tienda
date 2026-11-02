# Diseño de endurecimiento de seguridad

## Alcance aprobado

La aplicación conservará múltiples empresas y añadirá el rol técnico `platform_admin`. Solo este rol podrá operar respaldos completos y cambiar de empresa sin membresía. Los administradores ordinarios permanecerán dentro de su empresa. SUNAT queda fuera de este trabajo.

El inventario validará que almacenes, sucursales, productos, lotes y existencias pertenezcan a la empresa activa antes de escribir. Las transferencias exigirán dos almacenes de la misma empresa y cada consulta de stock incluirá empresa.

Los respaldos serán globales, almacenados en disco privado con nombre aleatorio y metadatos sin empresa. Listar, crear, descargar, programar o restaurar exigirá `platform_admin`. La restauración requerirá contraseña, la frase `RESTAURAR` y un archivo completado existente.

En ventas, el servidor utilizará `products.sale_price`. Un precio diferente requerirá el permiso `sales.override_price`, un motivo obligatorio y nunca podrá ser inferior a `minimum_price`; el precio original y el aplicado se guardarán en auditoría. En modo importe, el servidor conservará el importe exacto solicitado y calculará el peso con el precio autorizado.

Los webhooks solo admitirán HTTPS público. Se bloquearán credenciales en URL, localhost, direcciones privadas/reservadas, destinos que resuelvan a IP no pública y redirecciones. Se aplicarán límites de tiempo y tamaño de respuesta. SUNAT no se modificará.

El login no precargará credenciales. La clave Gemini expuesta se eliminará de archivos locales configurables; el operador deberá revocarla en Google y colocar una nueva solamente en `.env` o mediante la configuración cifrada de la empresa.

## Verificación

Se añadirán pruebas negativas entre dos empresas para inventario, contexto, documentos y respaldos; pruebas de rol de plataforma; pruebas de precio normal y sobrescrito; pruebas SSRF de webhook y pruebas de interfaz para el login. La suite existente y la compilación frontend deberán finalizar correctamente.
