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
                 localization · email-dev · deployment · backup-restore ·
                 glpi-upgrade-test · docker-image-pinning
  architecture/  (+ glpi-version-compatibility)
plugins/         companyportal · companypurchasing · companyworkflow · companyqr ·
                 companydashboard · companysignature · companyintegrations
                 (cada uno: setup.php · hook.php · README · CHANGELOG ·
                  src/ · locales/ · templates/ · tests/)
services/        ai-assistant · whatsapp-adapter · integration-hub
                 (cada uno: README · .env.example · Dockerfile · openapi/ · src/ · tests/)
infra/           docker/ (Dockerfile.glpi · docker-compose.yml · apache · php ·
                 entrypoint · glpi-config/install-and-localize.sh) · nginx/ ·
                 backup/ (backup.sh · restore.sh) · monitoring/ · scripts/
tests/           README · smoke/ (run-smoke.sh) · upgrade/ (verify-core-untouched.sh) ·
                 localization/ (verify-localization.sh) · mail/ (verify-mail.sh) ·
                 security/ (secret-scan.sh) · e2e/
.github/         workflows/ci.yml (CI de PRs; sin deploy)
glpi/            .gitkeep (punto de montaje upstream; el core NO se versiona)
```

## Correcciones post-revisión (gaps cerrados antes del merge)
| Gap | Qué se hizo | Archivos |
|-----|-------------|----------|
| 1. Localización real | Install reproducible (idioma es_ES, tablas tz, `database:enable_timezones`) + doc global/por-usuario + prueba fail-closed. PYG aclarado (GLPI core no tiene "moneda"; lo aplican los plugins). | `infra/docker/glpi-config/install-and-localize.sh`, `docs/operations/localization.md`, `tests/localization/verify-localization.sh` |
| 2. Correo DEV | Transporte reproducible `msmtp → MailHog` (imagen + `sendmail_path` + `entrypoint`), procedimiento admin documentado y prueba fail-closed vía API de MailHog. | `Dockerfile.glpi`, `php/glpi.ini`, `entrypoint.sh`, `docs/operations/email-dev.md`, `tests/mail/verify-mail.sh` |
| 3. Upgrade fail-closed | Eliminado `|| true`; fallo de install/activate de plugin requerido ⇒ exit ≠ 0; OK sólo si todo verde. | `infra/scripts/glpi-upgrade-test.sh` |
| 4. CI de PRs | Workflow: PHP lint, `bash -n`, YAML, `docker compose config`, verify-core, secret-scan (job estático) + stack up con smoke/localización/correo (job integración). Sin deploy. | `.github/workflows/ci.yml`, `tests/security/secret-scan.sh` |
| Doc `max=12.0` | Semántica explícita `>=11.0` y `<12.0`; sin soporte de major futura sin regresión. | `docs/architecture/glpi-version-compatibility.md`, 7 `plugins/*/setup.php` y `README.md` |
| Hardening | Pinning de imágenes (MailHog fijado; evitar `:latest`) + checksum opcional del tarball GLPI (`GLPI_SHA256`). | `docs/operations/docker-image-pinning.md`, `Dockerfile.glpi`, `docker-compose.yml` |

**Corrección técnica importante:** los nombres de comando de consola en GLPI 11
**no** llevan el prefijo `glpi:` (a diferencia de GLPI 10). Verificado contra el
código de 11.0.8: `database:install`, `database:enable_timezones`, `database:update`,
`plugin:install`, `plugin:activate`, `plugin:list`. Se corrigieron todos los scripts
y docs que usaban `glpi:plugin:*`.

## Verificaciones realizadas (en esta sesión, estáticas)
- `tests/upgrade/verify-core-untouched.sh` → **OK** (el core no está versionado).
- `php -l` en los 14 PHP de plugins → **sintaxis OK**.
- `bash -n` en todos los scripts → **OK**.
- `tests/security/secret-scan.sh` → **OK**.
- `docker compose config` → **OK**.
- Validación YAML de compose + workflow + OpenAPI → **OK**.

## Limitaciones conocidas
- **Sin daemon Docker en el entorno de esta sesión:** no se pudo *ejecutar* el stack
  aquí (build/up de GLPI, install, smoke, correo). Esa verificación **funcional** la
  realiza **CI** (job `integration`) y el entorno destino. En esta sesión sólo se
  validó sintaxis/estructura.
- **GLPI 11 no expone comando de consola para config arbitraria** ni bootstrap de
  scripts sueltos (`inc/includes.php` es sólo avisos de deprecación). Por eso la zona
  horaria de instancia, el formato numérico y la habilitación de correo son **pasos
  administrativos** documentados + verificados por prueba (según lo autorizado).

## Cómo continuar (siguiente paso, con tu aprobación)
Primer plugin funcional recomendado: **`companyqr`** (pequeño; valida instalación,
permisos, hooks, i18n, auditoría, migraciones y compatibilidad de upgrade). Antes de
programarlo se ejecuta el análisis native-first y se registra el ADR del módulo.
