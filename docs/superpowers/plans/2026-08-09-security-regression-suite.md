# Security Regression Suite Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convertir los límites de seguridad corregidos en regresiones automatizadas.

**Architecture:** Factories auxiliares crean dos empresas y usuarios con roles distintos; pruebas HTTP validan respuesta y ausencia de mutaciones.

**Tech Stack:** PHPUnit, Laravel RefreshDatabase, Node test runner, TypeScript build.

## Global Constraints

- No ejecutar llamadas reales a webhooks, IA ni restauración.

---

### Task 1: Matriz de permisos y recursos

**Files:** Test `backend/tests/Feature/SecurityRegressionTest.php`.

- [ ] Cubrir roles, respaldos, archivos, integraciones, contexto e inventario.
- [ ] Afirmar códigos y que los registros de la otra empresa no cambian.
- [ ] Ejecutar el archivo de pruebas aislado.

### Task 2: Recorrido completo

**Files:** Modify relevant tests under `backend/tests/Feature`; Test `app/tests/security-ui.test.mjs`.

- [ ] Ejecutar autenticación, compra confirmada, inventario y venta con precio del servidor.
- [ ] Ejecutar toda la suite Laravel.
- [ ] Ejecutar pruebas frontend y compilación de producción.
- [ ] Registrar cualquier limitación real restante en `docs/audits/`.
