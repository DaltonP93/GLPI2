# Operaciones

| Documento | Contenido |
|-----------|-----------|
| [environments.md](environments.md) | Estrategia DEV → STAGING → PRODUCCIÓN |
| [local-dev-setup.md](local-dev-setup.md) | Levantar el entorno local (Docker) |
| [installation.md](installation.md) | Instalación de GLPI + activación de plugins |
| [localization.md](localization.md) | Localización Paraguay (es / America\_Asuncion / PYG) + verificación |
| [email-dev.md](email-dev.md) | Correo DEV hacia MailHog + verificación |
| [deployment.md](deployment.md) | Flujo de despliegue y rollback |
| [backup-restore.md](backup-restore.md) | Copia de seguridad y restauración |
| [glpi-upgrade-test.md](glpi-upgrade-test.md) | Prueba de actualización de GLPI (plugins sobreviven) |
| [docker-image-pinning.md](docker-image-pinning.md) | Pinning de imágenes e integridad del artefacto (hardening) |
| [phase2-clean-install.md](phase2-clean-install.md) | Baseline de Fase 2: instalación limpia reproducible (orden de plugins, cron, derechos, configuración mínima) |
| [phase2-upgrade.md](phase2-upgrade.md) | Baseline de Fase 2: upgrade fail-closed de una instalación existente, con rollback |
| [si4-readiness.md](si4-readiness.md) | SI-4: requisitos para habilitarlo (sin activarlo), observabilidad y cómo deshabilitarlo |

Baseline y pruebas de aceptación: `../releases/phase2-baseline.md`, `../testing/phase2-uat.md`.
Infra asociada: `../../infra/` (Docker, nginx, backup, monitoreo, scripts).
CI de PRs: `../../.github/workflows/ci.yml` (checks estáticos + integración; sin deploy).
