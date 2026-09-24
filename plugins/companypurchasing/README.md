# Company Purchasing (`companypurchasing`)

Plugin propio de la **Plataforma GLPI Modular**.

- **Estrategia (matriz):** Build (apoyado en Forms/Assets nativos)
- **Propósito:** Solicitudes de compra, cotizaciones versionadas, aprobaciones, recepcion y alta/vinculo de activos GLPI.
- **GLPI soportado:** `>=11.0` y `<12.0` (el `max=12.0` es límite superior **excluyente**; probado en 11.0.8; GLPI 12 no soportado hasta suite de regresión — ver `../../docs/architecture/glpi-version-compatibility.md`)
- **Estado:** Fase 2D — **P2D-1 (núcleo)** + **P2D-2 (circuito de aprobación)** implementados:
  solicitud → jefe de área (`REQUEST_SCOPE`) → Compras/cotización → Gerencia financiera
  (`COMMERCIAL_FINANCIAL_SCOPE`) → `APPROVED`/`REJECTED`/`RETURNED`, sobre `companyworkflow` (único motor) y
  `companysignature` (evidencia/PDF). Sin recepción/outbox/Snipe-IT todavía (P2D-3…P2D-4).
- **Decisión P2D-2:** `../../docs/adr/ADR-0018-companypurchasing-approvals.md`.
- **Gate native-first (contrato de v1):**
  `../../docs/architecture/companypurchasing-native-first-gate.md` — reconciliado con el código real
  ya mergeado (`companyworkflow`, `companysignature`, `companyintegrations` SI-1).

## Qué hace P2D-1 (núcleo)
| Pieza | Rol |
|------|-----|
| `Model/Request` + `RequestItem` | Solicitud (borrador) y líneas. Identidad de línea = `id`; `line_no` sólo orden. Define el derecho `plugin_companypurchasing` y sus bits (ACL por acción). |
| `Model/NumberSequence` + `Service/NumberingService` | Numeración `UNIQUE(entities_id, scope, year)`, **transaccional/concurrency-safe** (probada con procesos paralelos reales); el número se asigna al **abandonar DRAFT** (no se reutiliza; se aceptan huecos). El texto visible `REQUEST-<año>-<seq>` es **por entidad** (`requests` tiene `UNIQUE(entities_id, number)` + `UNIQUE(entities_id, number_scope, number_year, number_seq)`), así que A y B pueden compartir número. |
| `Service/Decimal` + `Money` + `CurrencyPolicy` | Dinero **EXACTO** sin `float` (aritmética de strings). PYG escala 0; importes como string exacto; no redondea (fail-closed). |
| `Model/ScopeDef` + `Service/ScopeCatalog` + `ScopeSnapshotBuilder` | **Approval scopes** versionados/configurables (`REQUEST_SCOPE`, `COMMERCIAL_FINANCIAL_SCOPE`) con **validación semántica** (vocabulario cerrado `ALLOWED_KEYS`: rechaza typo/desconocida, duplicada, comercial en `REQUEST_SCOPE`, baseline y vacío); el builder arma el payload semántico determinista para Firma (P2D-2) y es **fail-closed** (`selectFields` lanza ante una clave protegida no producible). `REQUEST_SCOPE` **no** incluye proveedor/cotización/precio final. |
| `Service/RequestManager` + `Service/AdvisoryLock` | CRUD controlado de borrador (crear/editar/líneas/submit), ACL y multi-entidad **fail-closed**, dinero exacto, auditoría. **Atomicidad:** cada mutación (`createDraft`/`updateDraft`/`addLine`/`updateLine`/`removeLine`/`submitDraft`) confirma su cambio de datos **y** su evento de auditoría JUNTOS en una transacción local; ante excepción → `ROLLBACK` (sin dato/evento parcial ni solicitud/línea huérfana). Ningún write fallido se vuelve éxito en silencio (se comprueban `update()`/`delete()`). Las mutaciones sobre una solicitud existente se serializan además bajo un **lock común** `request_<id>` (`GET_LOCK`): recargar fresco → ACL/entidad/DRAFT → `BEGIN {mutar → recomputar total → auditar} COMMIT` → liberar (impide editar un DRAFT ya enviado y los totales stale). El **solicitante es siempre el usuario autenticado** (intento de otro → rechazo, en create y update); `is_recursive` baseline 0. Referencias `Group`/`Supplier`/`Budget` validadas **PARA la entidad de la solicitud** (misma entidad o ancestro recursivo, recorriendo la cadena `entities_id` con el modelo `Entity`; no basta con que la sesión vea ambas ramas). `submitDraft()` es **idempotente** y valida scopes **fail-closed** antes de reservar número. **No** integra `companyworkflow` (el `domain_state` es snapshot/cache). |
| `Model/PurchasingEvent` + `Service/Audit` | Auditoría de negocio **append-only** (sin secretos, con `correlation_id`). `Audit::record()` es **fail-closed** (lanza si el evento no persiste); `idempotency_key` **UNIQUE** hace idempotente durable el `REQUEST_SUBMITTED`. |
| `Command/SelftestCommand` | `plugins:companypurchasing:selftest` (integración + E2E; obligatorio en CI). Incluye los escenarios P2D-2 (trait `ApprovalSelftestScenarios`, mismo comando). |

**Estado de dominio:** `DRAFT` (editable) → `submit` asigna número + pinnea `scopes_version` (P2D-1) y
luego inicia/avanza la instancia de `companyworkflow` (P2D-2). Desde ese momento la **autoridad** es el
motor y `domain_state` es sólo su **proyección** (el motor gana; `reconcile` converge).

## Qué agrega P2D-2 (circuito de aprobación)
| Pieza | Rol |
|------|-----|
| `Service/PurchasingWorkflow` | Describe el proceso y las reglas PURAS de scopes: spec de la definición (grupos/quórum/SLA desde **configuración**, nunca hardcodeados), orden de etapas, reinicio por checkpoint y **aprobaciones vivas derivadas del ledger del motor**. |
| `Service/ApprovalOrchestrator` | Saga idempotente: `publishDefinition`, `submit`, `decide(approve/reject/return)`, fachada comercial, `enforceIntegrity`, PDF, `reconcile`. **Nunca** llama a workflow/firma dentro de una transacción local. |
| `Service/DocumentVersionAllocator` + `Model/DocVersion`/`DocSequence` | `document_version` de dominio: monotónica por solicitud, concurrency-safe, no reutilizable; reutiliza la última del scope sólo si el contenido no cambió. |
| `Service/QuoteManager` + `QuoteMath` + `Model/Quote`/`QuoteItem` | Cotizaciones (Supplier nativo; N adjuntos `Document_Item` nativos), precio final por línea (FK `items.id`), totales derivados exactos; selección con `requests.quotes_id_selected` como única fuente de verdad (lock + `lock_version`). |
| `Service/WorkflowGateway` / `SignatureGateway` | Únicas puertas a `companyworkflow` / `companysignature` (APIs oficiales; lecturas por modelo). |
| `Service/StateProjection` + `Command/ReconcileCommand` | Proyección de `domain_state`; listener best-effort + `plugins:companypurchasing:reconcile`. |
| `Service/ReferenceValidator` | Validación AUTORITATIVA de maestros nativos PARA la entidad (extraída de P2D-1, misma semántica). |

**Configuración (`plugin:companypurchasing`)**: `workflow_code`; `approver_group_{area_head,purchasing,finance}`
(obligatorios para publicar); `quorum_*`; `sla_hours_*`; `stage_scopes` (etapa → scope);
`scope_checkpoints` (scope → estado que reabre); `pdf_stages`; `quote_states`; `amend_states`;
`sync_on_workflow_events`. Publicar: `ApprovalOrchestrator::publishDefinition()` (derecho `MANAGE_CONFIG`).

**Perfiles (mínimo privilegio; la autorización de cada decisión la da el motor: grupo + quórum):**
solicitante `plugin_companypurchasing` (crear/ver propias/editar borrador) + `plugin_companyworkflow:READ`;
aprobadores `plugin_companyworkflow:RIGHT_ACT` + `plugin_companysignature:RIGHT_RECORD`; Compras además
`plugin_companypurchasing:MANAGE_PURCHASING`.

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
