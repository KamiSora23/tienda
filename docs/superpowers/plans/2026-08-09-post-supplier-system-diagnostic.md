# Post-Supplier System Diagnostic Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Auditar el sistema completo después de implementar producto-proveedores y entregar hallazgos reproducibles priorizados.

**Architecture:** El diagnóstico es de solo lectura salvo bases efímeras y compilaciones. Combina inventario estático de rutas/esquema/vistas con pruebas automatizadas y recorridos funcionales locales; no corrige hallazgos automáticamente.

**Tech Stack:** Laravel Artisan, PHP tests, Node tests, Vinext build, análisis estático con `rg`, documentación Markdown.

## Global Constraints

- No enviar datos reales a servicios externos.
- No afirmar integración SUNAT, Gemini, respaldo o correo sin evidencia ejecutable.
- Diferenciar defecto, función parcial, deuda técnica y dependencia externa.
- Priorizar como crítico, alto, medio o bajo con evidencia y recomendación.

---

### Task 1: Línea base ejecutable

**Files:**
- Create: `docs/audits/2026-08-09-post-supplier-functional-audit.md`

- [ ] Registrar versiones, configuración no secreta, lista de migraciones y recuento de rutas.
- [ ] Ejecutar suite Laravel, pruebas frontend y build; capturar comandos, salida resumida y fallos exactos.
- [ ] Separar fallos nuevos de pruebas obsoletas como `_sites-preview`.

### Task 2: Integridad y aislamiento

- [ ] Revisar claves foráneas, restricciones únicas, uso de transacciones y consultas sin `company_id`/`branch_id`.
- [ ] Revisar autenticación, sesiones, permisos y rutas públicas.
- [ ] Validar con pruebas locales compras, inventario, ventas, anulaciones, devoluciones, caja y gastos.
- [ ] Documentar cada hallazgo con archivo/línea, impacto y reproducción.

### Task 3: Cobertura funcional y vistas

- [ ] Comparar rutas/controladores/componentes con `VISTAS-REQUERIDAS.txt` y `tienda.docx` ya extraído en el proyecto.
- [ ] Clasificar cada módulo como operativo, parcial, interfaz sin lógica, lógica sin interfaz o ausente.
- [ ] Revisar portal, pedidos, reportes, auditoría, configuración, IA, archivos, respaldos, integraciones y mantenimiento.

### Task 4: Informe final

- [ ] Ordenar hallazgos por prioridad y dependencia.
- [ ] Incluir resumen ejecutivo, evidencia de pruebas, riesgos de producción y hoja de ruta recomendada.
- [ ] Auto-revisar el informe contra comandos ejecutados; eliminar afirmaciones sin evidencia y marcadores incompletos.
