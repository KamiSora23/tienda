# Tenant and Backup Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Impedir accesos entre empresas y reservar respaldos globales al superadministrador de plataforma.

**Architecture:** Un servicio central identifica `platform_admin`; controladores validan pertenencia antes de operar. Los respaldos completos son recursos globales privados.

**Tech Stack:** Laravel 12, PHP 8.3, MySQL, PHPUnit.

## Global Constraints

- SUNAT queda fuera del alcance.
- Todo cambio se prueba primero con casos negativos.

---

### Task 1: Rol de plataforma y contexto

**Files:** Create `backend/app/Support/PlatformAccess.php`; Modify `backend/app/Http/Controllers/AuthController.php`, `CompanyController.php`, `database/seeders/DatabaseSeeder.php`; Test `backend/tests/Feature/PlatformAccessTest.php`.

- [ ] Escribir pruebas donde admin no cambia a otra empresa y platform_admin sí.
- [ ] Ejecutar la prueba y confirmar fallo.
- [ ] Implementar `PlatformAccess::allows(User $user): bool` usando `role === 'platform_admin'`.
- [ ] Sembrar un superadministrador sin mostrar su contraseña en el login.
- [ ] Ejecutar la prueba y confirmar éxito.

### Task 2: Aislamiento de inventario y documentos

**Files:** Modify `backend/app/Http/Controllers/InventoryController.php`, `SystemController.php`; Test `backend/tests/Feature/TenantIsolationTest.php`.

- [ ] Probar lotes, ajustes, transferencias y documentos con IDs de otra empresa.
- [ ] Confirmar que actualmente fallan las expectativas 404/403.
- [ ] Añadir consultas `company_id` para almacenes, productos, stocks y entidades de archivos.
- [ ] Ejecutar las pruebas y confirmar que ninguna escritura cruzada ocurre.

### Task 3: Respaldos globales seguros

**Files:** Create `backend/app/Services/DatabaseBackupService.php`; Modify `backend/app/Http/Controllers/SystemController.php`; Test `backend/tests/Feature/BackupAuthorizationTest.php`.

- [ ] Probar que admin recibe 403 y platform_admin puede listar metadatos.
- [ ] Centralizar autorización y ejecución, usar nombre aleatorio, disco local privado y argumentos escapados.
- [ ] Exigir contraseña y confirmación en restauración y auditar operaciones.
- [ ] Ejecutar pruebas sin invocar binarios reales mediante servicio simulado.
