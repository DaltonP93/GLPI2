# Tests — Plataforma GLPI Modular

Estrategia de pruebas del proyecto. En Fase 0 se dejan los **arneses** y la
estructura; las pruebas de cada módulo se agregan al desarrollarlo.

| Carpeta | Propósito |
|---------|-----------|
| `smoke/` | Verificaciones rápidas post-instalación/actualización (`run-smoke.sh`). |
| `upgrade/` | Suite de compatibilidad ante actualización de GLPI (`verify-core-untouched.sh`). |
| `e2e/` | Pruebas end-to-end de flujos completos (a definir por módulo). |

## Pirámide de pruebas
1. **Unitarias** — dentro de cada `plugins/*/tests` y `services/*/tests`.
2. **Integración** — APIs, workflows, webhooks.
3. **E2E** — flujos de usuario (portal, compras, etc.).
4. **Upgrade/regresión** — que los módulos sobrevivan a nuevas versiones de GLPI
   **sin modificar el core** (ver `docs/operations/glpi-upgrade-test.md`).

## Reglas
- Toda funcionalidad nueva incluye pruebas (ver *Definition of Done* en `CLAUDE.md`).
- La prueba de actualización es **obligatoria** antes de aceptar una nueva
  versión de GLPI en staging/producción.
