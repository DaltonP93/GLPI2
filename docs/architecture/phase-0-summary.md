# Fase 0 — Resumen de entregables

Estado: **completada** (fundaciones). Sin lógica de negocio. **Core de GLPI intacto**
(no versionado; verificado por `tests/upgrade/verify-core-untouched.sh`).

## Checklist (según lo solicitado)
| # | Entregable | Estado | Dónde |
|---|------------|--------|-------|
| 1 | Arquitectura persistida + `CLAUDE.md` en raíz | ✅ | `docs/`, `CLAUDE.md` |
| 2 | ADRs iniciales (0001–0010) con Contexto/Decisión/Alternativas/Consecuencias/Estado | ✅ | `docs/adr/` |
| 3 | Esqueletos de 7 plugins + 3 servicios | ✅ | `plugins/`, `services/` |
| 4 | GLPI como dependencia upstream (no fork) | ✅ | `infra/docker/Dockerfile.glpi`, `glpi/.gitkeep` |
| 5 | Infra reproducible DEV/STAGING (Docker/Compose) | ✅ | `infra/docker/` |
| 6 | Estrategia DEV→STAGING→PROD + prueba de upgrade | ✅ | `docs/operations/`, `infra/scripts/` |
| 7 | Proceso native-first antes de cada módulo | ✅ | `docs/architecture/native-first-process.md` |
| 8 | Hallazgos GLPI 11 incorporados | ✅ | `docs/architecture/glpi11-findings.md`, `master-document.md` §19 |
| 9 | Matriz de capacidades GLPI 11 | ✅ | `docs/architecture/glpi11-capability-matrix.md` |
| 10 | Riesgos + entrega para revisión (sin avanzar a Compras) | ✅ | `risks.md` + este documento |

## Árbol del repositorio (resumen)
```
CLAUDE.md  README.md  .env.example  .gitignore  .editorconfig
docs/
  architecture/  overview · master-document · glpi11-findings ·
                 glpi11-capability-matrix · native-first-process ·
                 module-map · risks · phase-0-summary
  adr/           README · adr-template · ADR-0001 … ADR-0010
  functional/    README · purchasing · service-portal · signature · metrics
  api/           README · api-standards · glpi-rest-api-v2
  workflows/     README · workflow-engine · purchasing-workflow
  security/      README · security-baseline · secrets-management · audit-logging
  operations/    README · environments · local-dev-setup · installation ·
                 deployment · backup-restore · glpi-upgrade-test
plugins/         companyportal · companypurchasing · companyworkflow · companyqr ·
                 companydashboard · companysignature · companyintegrations
                 (cada uno: setup.php · hook.php · README · CHANGELOG ·
                  src/ · locales/ · templates/ · tests/)
services/        ai-assistant · whatsapp-adapter · integration-hub
                 (cada uno: README · .env.example · Dockerfile · openapi/ · src/ · tests/)
infra/           docker/ (Dockerfile.glpi · docker-compose.yml · apache · php · entrypoint) ·
                 nginx/ · backup/ (backup.sh · restore.sh) · monitoring/ · scripts/
tests/           README · smoke/ (run-smoke.sh) · upgrade/ (verify-core-untouched.sh) · e2e/
glpi/            .gitkeep (punto de montaje upstream; el core NO se versiona)
```

## Verificaciones realizadas
- `tests/upgrade/verify-core-untouched.sh` → **OK** (el core no está versionado).
- `php -l` en los 7 `setup.php`/`hook.php` → **sintaxis OK**.
- `bash -n` en todos los scripts → **OK**.
- `docker compose config` → **OK**.

## Cómo continuar (siguiente paso, con tu aprobación)
Primer plugin funcional recomendado: **`companyqr`** (pequeño; valida instalación,
permisos, hooks, i18n, auditoría, migraciones y compatibilidad de upgrade). Antes de
programarlo se ejecuta el análisis native-first y se registra el ADR del módulo.
