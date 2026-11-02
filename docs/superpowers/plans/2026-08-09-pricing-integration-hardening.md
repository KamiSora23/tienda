# Pricing and Integration Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Recalcular ventas en servidor, controlar excepciones de precio y bloquear SSRF en webhooks.

**Architecture:** Un resolutor de precios aplica catálogo o una excepción autorizada y auditable. Un validador de destinos se ejecuta al guardar y antes de enviar webhooks.

**Tech Stack:** Laravel HTTP client, PHP DNS/IP validation, PHPUnit, React.

## Global Constraints

- SUNAT no se modifica.
- Un importe por peso conserva el total monetario exacto.

---

### Task 1: Política de precios

**Files:** Create `backend/app/Services/SalePriceService.php`; Modify `backend/app/Http/Controllers/ErpController.php`; Test `backend/tests/Feature/SalePriceAuthorizationTest.php`.

- [ ] Probar que un precio enviado se ignora sin permiso.
- [ ] Probar que override requiere permiso, motivo y precio mínimo.
- [ ] Resolver precio de catálogo y registrar override en auditoría.
- [ ] Recalcular línea, subtotal, pagos y peso desde valores autorizados.
- [ ] Ejecutar pruebas de venta normal y por importe.

### Task 2: Interfaz de excepción de precio

**Files:** Modify `app/app/SalesModule.tsx`, `app/app/OperationalModules.tsx`, `app/app/DeurstoreApp.tsx`; Test `app/tests/security-ui.test.mjs`.

- [ ] Vaciar email y contraseña del login.
- [ ] Enviar override y motivo solo cuando el usuario cambie el precio.
- [ ] Mantener cálculo visual como estimación y mostrar errores del servidor.
- [ ] Ejecutar pruebas frontend.

### Task 3: Protección SSRF

**Files:** Create `backend/app/Services/PublicWebhookUrl.php`; Modify `backend/app/Http/Controllers/IntegrationController.php`; Test `backend/tests/Feature/WebhookSecurityTest.php`.

- [ ] Probar rechazo de HTTP, localhost, loopback, privadas, link-local, credenciales y DNS privado.
- [ ] Validar URL al configurar y nuevamente antes de enviar.
- [ ] Desactivar redirecciones y limitar timeout/respuesta.
- [ ] Probar un destino HTTPS público con `Http::fake()`.

### Task 4: Higiene de Gemini

**Files:** Modify `backend/.env`, `backend/.env.example`, documentación que contenga la clave.

- [ ] Buscar el prefijo de clave expuesto sin imprimir secretos adicionales.
- [ ] Eliminar el valor comprometido de archivos locales y mantener variable vacía.
- [ ] Documentar revocación externa obligatoria y configuración segura de una clave nueva.
