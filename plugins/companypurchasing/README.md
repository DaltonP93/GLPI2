# Company Purchasing (`companypurchasing`)

Plugin propio de la **Plataforma GLPI Modular**.

- **Estrategia (matriz):** Build (apoyado en Forms/Assets nativos)
- **Propósito:** Solicitudes de compra, cotizaciones versionadas, aprobaciones, recepcion y alta/vinculo de activos GLPI.
- **GLPI soportado:** `>=11.0` y `<12.0` (el `max=12.0` es límite superior **excluyente**; probado en 11.0.8; GLPI 12 no soportado hasta suite de regresión — ver `../../docs/architecture/glpi-version-compatibility.md`)
- **Estado:** Fase 2D — **P2D-1 (núcleo)** implementado: dominio + esquema + ACL + numeración +
  solicitudes/líneas + dinero exacto + approval scopes/snapshot builder. Sin
  workflow/firma/recepción/outbox todavía (P2D-2…P2D-4).
- **Gate native-first (contrato de v1):**
  `../../docs/architecture/companypurchasing-native-first-gate.md` — reconciliado con el código real
  ya mergeado (`companyworkflow`, `companysignature`, `companyintegrations` SI-1).

## Qué hace P2D-1 (núcleo)
| Pieza | Rol |
|------|-----|
| `Model/Request` + `RequestItem` | Solicitud (borrador) y líneas. Identidad de línea = `id`; `line_no` sólo orden. Define el derecho `plugin_companypurchasing` y sus bits (ACL por acción). |
| `Model/NumberSequence` + `Service/NumberingService` | Numeración `UNIQUE(entities_id, scope, year)`, **transaccional/concurrency-safe**; el número se asigna al **abandonar DRAFT** (no se reutiliza; se aceptan huecos). |
| `Service/Decimal` + `Money` + `CurrencyPolicy` | Dinero **EXACTO** sin `float` (aritmética de strings). PYG escala 0; importes como string exacto; no redondea (fail-closed). |
| `Model/ScopeDef` + `Service/ScopeCatalog` + `ScopeSnapshotBuilder` | **Approval scopes** versionados/configurables (`REQUEST_SCOPE`, `COMMERCIAL_FINANCIAL_SCOPE`); el builder arma el payload semántico determinista para Firma (P2D-2). `REQUEST_SCOPE` **no** incluye proveedor/cotización/precio final. |
| `Service/RequestManager` | CRUD controlado de borrador (crear/editar/líneas/submit), ACL y multi-entidad **fail-closed**, dinero exacto, auditoría. **No** integra `companyworkflow` (el `domain_state` es snapshot/cache). |
| `Model/PurchasingEvent` + `Service/Audit` | Auditoría de negocio **append-only** (sin secretos, con `correlation_id`). |
| `Command/SelftestCommand` | `plugins:companypurchasing:selftest` (integración + E2E; obligatorio en CI). |

**Estado de dominio:** `DRAFT` (editable) → `submit` asigna número + pinnea `scopes_version` → `PENDING`
(snapshot). La **autoridad** de estados será `companyworkflow` en **P2D-2**.

## Regla 0
Este plugin **no modifica el core de GLPI**. Solo usa hooks/API oficiales.
Ver `../../CLAUDE.md` y `../../docs/adr/ADR-0002-glpi-core-immutable.md`.

## Definition of Done (por módulo)
código · migración reversible · ACL · i18n ES/EN · auditoría · métricas/logs ·
tests · documentación · changelog · verificación de core intacto.

## Estructura
| Ruta | Rol |
|------|-----|
| `setup.php` | Metadatos, requisitos e `init` (registro de hooks) |
| `hook.php` | `install()` / `uninstall()` con migraciones reversibles |
| `src/` | Clases del plugin (PSR-4: `GlpiPlugin\...`) |
| `locales/` | Traducciones ES/EN (i18n) |
| `templates/` | Vistas Twig |
| `tests/` | Pruebas del plugin |

## Antes de desarrollar
Ejecutar el **análisis nativo GLPI 11**
(`../../docs/architecture/native-first-process.md`) y registrar la decisión
en un ADR bajo `../../docs/adr/`.
