# Auditoría funcional integral — Plan de ejecución

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Identificar funciones rotas, simuladas, inconsistencias de datos y riesgos operativos en todos los módulos del ERP.

**Architecture:** La auditoría combinará inspección estática, pruebas API, consultas de consistencia MySQL y pruebas renderizadas. Los hallazgos se guardarán en un único informe priorizado; solo se corregirán de inmediato defectos críticos que bloqueen la auditoría o puedan corromper datos.

**Tech Stack:** Laravel/PHPUnit, MySQL, React/vinext, Browser plugin.

## Global Constraints

- No ejecutar `migrate:fresh` sobre la base real.
- No enviar datos reales a proveedores externos durante las pruebas.
- Preservar cambios existentes y datos del usuario.
- No hay repositorio Git; usar evidencia de comandos y capturas.

---

### Task 1: Inventario estático y pruebas automatizadas

**Files:**
- Create: `docs/audits/2026-08-09-functional-audit.md`

- [ ] Enumerar rutas, controladores, módulos y tablas.
- [ ] Buscar placeholders, botones sin operación, errores ignorados y estados no implementados.
- [ ] Ejecutar `php artisan route:list`, `php artisan test` y `npm run build`.
- [ ] Registrar cada hallazgo con severidad, reproducción, impacto y archivo responsable.

### Task 2: Consistencia de datos y flujos API

- [ ] Comprobar stock contra kardex, ventas anuladas/devueltas y compras confirmadas.
- [ ] Comprobar caja contra pagos y movimientos.
- [ ] Comprobar correlativos, clientes de facturas, promociones y reservas web.
- [ ] Probar autenticación, permisos, CRUD principales y operaciones destructivas con base de pruebas.
- [ ] Añadir hallazgos y evidencia al informe.

### Task 3: Auditoría renderizada

- [ ] Usar Browser plugin para login, sidebar y pantallas principales.
- [ ] Revisar consola, overlays, formularios, modales, scroll y responsive.
- [ ] Ejercitar POS por importe, cliente rápido, compras, caja, configuración y portal.
- [ ] Capturar evidencia de fallos y del flujo POS corregido.
- [ ] Finalizar matriz priorizada: P0 corrupción/seguridad, P1 bloqueo, P2 incompleto, P3 mejora.

### Task 4: Correcciones críticas y revalidación

- [ ] Corregir únicamente P0/P1 que bloqueen el uso o comprometan datos.
- [ ] Añadir prueba de regresión por cada corrección.
- [ ] Repetir suite, build y pruebas renderizadas.
- [ ] Documentar riesgos restantes y orden recomendado de implementación.
