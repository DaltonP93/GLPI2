# Company Purchasing (`companypurchasing`)

Plugin propio de la **Plataforma GLPI Modular**.

- **Estrategia (matriz):** Build (apoyado en Forms/Assets nativos)
- **Propósito:** Solicitudes de compra, cotizaciones versionadas, aprobaciones, recepcion y alta/vinculo de activos GLPI.
- **GLPI soportado:** `>=11.0` y `<12.0` (el `max=12.0` es límite superior **excluyente**; probado en 11.0.8; GLPI 12 no soportado hasta suite de regresión — ver `../../docs/architecture/glpi-version-compatibility.md`)
- **Estado:** Fase 2D — **P2D-1 (núcleo)** + **P2D-2 (circuito de aprobación)** + **P2D-3 (recepción)** +
  **P2D-4 (entrega, cierre, UI, notificaciones, métricas)** implementados: solicitud → jefe de área
  (`REQUEST_SCOPE`) → Compras/cotización → Gerencia financiera (`COMMERCIAL_FINANCIAL_SCOPE`) → `APPROVED` →
  `IN_PURCHASE` → `PARTIALLY_RECEIVED` → `RECEIVED` → `DELIVERED` → `CLOSED`, sobre `companyworkflow` (único
  motor) y `companysignature` (evidencia/PDF), con recepción y entrega física por unidad y handoff (outbox) para
  SI-4. El alta de activos GLPI / Snipe-IT / companyqr la hace SI-4 (`companyintegrations`), **no** Compras.
- **Versión:** `0.5.0` (P2D-4; esquema nuevo con upgrade idempotente desde 0.4.0). Requiere `companyworkflow`
  `>= 0.6.0` para bandejas/notificaciones (API de lectura); con una versión anterior esas lecturas fallan
  cerradas.
- **Decisiones:** P2D-2 `../../docs/adr/ADR-0018-companypurchasing-approvals.md` · P2D-3
  `../../docs/adr/ADR-0019-companypurchasing-receiving.md` · P2D-4
  `../../docs/adr/ADR-0023-companypurchasing-delivery-ui.md`.
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
| `Service/StateProjection` + `Command/ReconcileCommand` + `Model/ProjectionTask` | Proyección de `domain_state`; listener best-effort + **Acción automática nativa** `reconcileprojection` y comando `plugins:companypurchasing:reconcile` (lotes con cursor + wrap-around; reportan enviadas-sin-instancia e integridad pendiente). |
| `Service/ApprovalPolicy` + `PolicyStore` + `Model/PolicyVersion` | Política de aprobación **pinneada por solicitud** (JSON canónico + hash, inmutable): etapa→scope, checkpoints, PDF, estados comerciales. Un cambio de configuración sólo afecta a solicitudes nuevas. |
| `Service/IntegrityLedger` + `ReopenCapability` + `Model/IntegrityMark` | Marca **durable** de integridad escrita en la misma transacción que la mutación sustantiva; sólo se resuelve tras invalidación confirmada o verificación contra el ledger del motor. Las mutaciones que pueden exigir reabrir requieren `RIGHT_ACT` (+ `RIGHT_RECORD`). |
| `Service/ReferenceValidator` | Validación AUTORITATIVA de maestros nativos PARA la entidad (extraída de P2D-1, misma semántica). |

**Configuración (`plugin:companypurchasing`)**: `workflow_code`; `approver_group_{area_head,purchasing,finance}`
(obligatorios para publicar); `quorum_*`; `sla_hours_*`; `stage_scopes` (etapa → scope);
`scope_checkpoints` (scope → estado que reabre); `pdf_stages`; `quote_states`; `amend_states`;
`sync_on_workflow_events` (operacional); `reconcile_cursor` (estado de la reconciliación). Publicar:
`ApprovalOrchestrator::publishDefinition()` (derecho `MANAGE_CONFIG`). Las reglas de política se **pinnean**
por solicitud al enviarla.

**Contrato de decisión:** `decide(requestId, action, expectedState, comment)` — `expectedState` es
obligatoria; si la etapa ya cambió ⇒ `stage_changed` (sin nueva decisión). `integrityStatus()` /
`isFullyApproved()` exigen APPROVED **y** ausencia de marcas/deriva (`startPurchase()` lo exige).

**Perfiles (mínimo privilegio; la autorización de cada decisión la da el motor: grupo + quórum):**
solicitante `plugin_companypurchasing` (crear/ver propias/editar borrador) + `plugin_companyworkflow:READ`;
aprobadores `plugin_companyworkflow:RIGHT_ACT` + `plugin_companysignature:RIGHT_RECORD`; Compras además
`plugin_companypurchasing:MANAGE_PURCHASING` (y `RIGHT_ACT` + `RIGHT_RECORD` para aceptar cambios que exijan
reabrir una aprobación).

## Qué agrega P2D-3 (recepción física + handoff a SI-4)
| Pieza | Rol |
|------|-----|
| `Service/PurchasingWorkflow` (extendido) | Nueva **versión** de la misma definición: `start_purchase`, `receive_partial`, `receive_complete` con condiciones `purchase_bound`/`receipt_bound` (sólo las aporta Compras). `RECEIVED` es intermedio. Reglas puras `receivingTarget()` / `syncPath()`. |
| `Service/ReceivingService` | `startPurchase()` (`MANAGE_PURCHASING`; exige integridad limpia + APPROVED): **congela** `ordered_qty`, precio final y costo de cada línea, proveedor/cotización y la política de costo. `receive()` (`RIGHT_RECEIVE` + entidad, `idempotency_key` obligatoria): UNA transacción con `FOR UPDATE` de las líneas → lote → unidades (UUID v4 CSPRNG, costo exacto) → outbox por unidad inventariable → contadores → marcador → auditoría. Cualquier fallo ⇒ rollback total. `units()` con ACL. |
| `Service/ReceivingSync` | Saga post-COMMIT que lleva el motor a reflejar los contadores (0 ⇒ IN_PURCHASE; parcial ⇒ PARTIALLY_RECEIVED; completo ⇒ RECEIVED) con marcador durable `receiving_seq`/`receiving_synced_seq`; anomalías se reportan sin mutar. |
| `Service/CostPolicy` + `CostPolicyStore` + `CostAllocator` + `Model/CostPolicyVersion` | Costo atribuible exacto (sin float): base `final_unit_price`; ajustes de cabecera sólo si la política pinneada los incluye (por defecto no); asignación `line_value_largest_remainder`; reparto por unidad `floor_remainder_to_first_units`. La política pinnea también `currency_code` + `currency_scale` (PYG ⇒ 0): la compra usa **sólo** esa escala. |
| `Service/HandoffPayload` + `Model/OutboxEntry` | Payload v1 inmutable (canónico + sha256) con la unidad, solicitud/número, línea, entidad, serial, proveedor, moneda, `currency_scale` (la pinneada), `unit_cost` (string exacto a esa escala), fecha y correlación; autosuficiente (no consulta la configuración vigente). `validatedPayload()`: validación única de claim y lectura. |
| `Api/PurchasingIntegrationApi` | Contrato para SI-4 (sin SQL a tablas de Compras): `claimPending`, `getHandoff`, `acknowledgeProcessed`, `markRetry`, `markError`; lease con token (CSPRNG) y reloj de la BD (confirmar exige el lease VIGENTE); `RIGHT_INTEGRATION` (mínimo privilegio) + multi-entidad. |
| `Model/ReceiptBatch` / `ReceiptUnit` | Lote (idempotencia de la operación) y unidad física (identidad `receipt_unit_uuid`; FK `items_id`; serial único por línea; `unit_cost` inmutable). |

**Reglas:** `pending = ordered_qty − received_qty` (derivado). Tras `startPurchase` no se cotiza, selecciona,
cambia precio ni enmienda cantidad (fail-closed); una deriva de integridad posterior **no reabre** el circuito
(se reporta y la recepción queda bloqueada). La reconciliación (Acción automática `reconcileprojection` /
comando) además converge la saga de recepción y **reporta** recepción pendiente, anomalías e instancias con una
versión anterior de la definición (no se migran).

**Configuración P2D-3:** `cost_include_{discounts,taxes,freight}` (se pinnea al iniciar la compra; `0` por
defecto), `outbox_max_attempts`, `outbox_max_lease_seconds`, `receipt_max_units_per_batch`. La escala de la moneda
(`currency_scale_overrides`) también se pinnea al iniciar la compra: cambiarla después sólo afecta compras nuevas.

**Perfiles P2D-3:** receptor `plugin_companypurchasing:RIGHT_RECEIVE` (+ `plugin_companyworkflow:READ` para que
el motor refleje la recepción en vivo; sin él, la recepción se confirma igual y la Acción automática converge);
Compras `MANAGE_PURCHASING` para iniciar la compra; worker de integración **sólo** `RIGHT_INTEGRATION`.

## Qué agrega P2D-4 (entrega física, cierre, UI, bandejas, notificaciones nativas, métricas)
| Pieza | Rol |
|------|-----|
| `Service/PurchasingWorkflow` (extendido) | Nueva **versión** de la misma definición: `RECEIVED →deliver_complete→ DELIVERED →close→ CLOSED` (`delivery_bound` / `close_bound`, sólo las aporta Compras). `RECEIVED` y `DELIVERED` intermedios, `CLOSED` final. **Sin** `PARTIALLY_DELIVERED` (la entrega parcial vive en las unidades). Regla pura `physicalTarget()`; `syncPath()` extendido. |
| `Service/DeliveryService` + `DeliveryRules` + `DeliveryException` | `deliver()` (`RIGHT_DELIVER` + entidad, `idempotency_key` obligatoria): UNA transacción — `FOR UPDATE` de las unidades en orden estable (id) → clave (`FOR UPDATE`; replay exacto ⇒ mismo lote, otra entrada ⇒ conflicto) → validación bajo lock → **gate de inventario** (inventariable ⇒ su outbox `DONE`) → lote append-only → `UPDATE` condicionado (`affectedRows` exacto) → `delivery_seq` + auditoría. Cualquier fallo ⇒ ROLLBACK total. Un reintento acotado ante deadlock/lock-wait. `closeRequest()` (`MANAGE_PURCHASING` + entidad): exige motor `DELIVERED`, todas las unidades entregadas, nada pendiente de recibir, outbox `DONE` e integridad limpia; idempotente (`already_closed`). Errores funcionales tipados (`acl`, `entity`, `recipient`, `unit_not_deliverable`, `inventory_gate`, `not_ready_to_close`…), nunca SQL. |
| `Model/DeliveryBatch` + `ReceiptUnit` (extendido) | Lote de entrega APPEND-ONLY (destinatario, actor, fecha, notas, `units_count`, `input_sha256`, `correlation_id`); la unidad guarda `delivery_batches_id`, `delivered_at`, `delivered_to_users_id`. Una unidad se entrega **una vez**; identidad `receipt_unit_uuid`. |
| `Service/ReceivingSync` (saga física) | Recepción **y** entrega: COMMIT local primero, motor después; marcador durable `delivery_seq`/`delivery_synced_seq`; la Acción automática converge si el proceso cae. Jamás se deshace una entrega por un fallo del motor. |
| `Service/InboxService` + `WorkflowGateway` | Bandejas **derivadas del motor** (API de lectura de `companyworkflow` 0.6.0): "para mí" = `pendingDecisionsForCurrentUser()`; Compras / recepción / entrega = `availableActions()` de la instancia ∩ derecho de dominio. Nunca por `domain_state` hardcodeado. |
| `Controller/PageController` + `ActionController` + `Menu` + `templates/` | UI delgada con el layout, menú (*Gestión → Compras*) y CSRF **nativos**. 7 páginas GET (listado, bandejas, nueva, detalle, edición, métricas, configuración) y 17 acciones **POST-only** con CSRF, ACL y entidad del lado servidor y **PRG** (un GET sobre una acción ⇒ 405, `MethodGuardController`). Los botones salen de `RequestDetailBuilder` (`availableActions` / `actionsForCurrentUser` ∩ derecho). Twig escapa; sin tokens, `lease_token`, hashes internos ni errores SQL (`SafeError`). |
| `Service/NotificationDispatcher` + `NotificationRules` + `NotificationSeeder` + `Model/NotificationTargetRequest` | Notificaciones **nativas** (`NotificationEvent::raiseEvent` → `QueuedNotification`): 10 eventos con plantillas/notificaciones sembradas sólo si faltan. Disparador = ledger del motor (listener en vivo + recorrido con cursor en la Acción automática). Como mucho una vez por hecho (`notify:<history_id>:<evento>`). Un fallo de envío **no revierte** el negocio (`notification.failed`). |
| `Service/MetricsService` + `MetricsMath` | Tablero (`RIGHT_VIEW_METRICS`): solicitudes por estado/mes, montos solicitado/aprobado/comprado **separados por moneda** (exactos, sin float), desglose por entidad/departamento/categoría/proveedor, ciclo y duración por etapa (ledger del motor), recepción, inventario (outbox) y entrega. Aislamiento por entidad (`scopeEntities`). |
| `Service/RequestQuery` / `RequestDetailBuilder` / `Labels` / `ConfigForm` | Listado con ACL server-side (`VIEW_OWN` ⇒ sólo propias; `VIEW_ENTITY` ⇒ su entidad); detalle consolidado; etiquetas i18n de estados/acciones/eventos; validación del formulario de configuración. |
| `Service/RequestUiAccess` + `UiAccessException` | Lectura de UNA solicitud en la UI. `VIEW_*` es la lectura **general**. Una acción **actual** autorizada sobre **esa** solicitud (decisión del motor, compras, recepción, entrega o cierre) es la lectura **contextual**. Siempre exige la entidad. Usa los mismos predicados que las bandejas. Rechazo tipado: 404 / 403. |
| `IntegrationLinkGateway` → `companyintegrations` `InventoryLinkApi` | Muestra en el detalle de la unidad la fase SI-4 y el activo GLPI vinculado mediante una API pública READ-ONLY de `companyintegrations` (sin SQL cross-plugin). |

**Configuración P2D-4:** `delivery_max_units_per_batch` (1000), `inbox_scan_cap` (2000), `metrics_max_requests`
(5000), `notifications_enabled` (1; además requiere las notificaciones nativas de GLPI activas),
`notify_cursor` (estado interno del recorrido del ledger).

**Perfiles P2D-4:** entregador `plugin_companypurchasing:RIGHT_DELIVER` (+ `plugin_companyworkflow:READ`);
cierre `MANAGE_PURCHASING`; tablero `RIGHT_VIEW_METRICS`. El **destinatario** de una entrega debe tener un perfil en
la entidad de la solicitud.

**Lectura en la UI:** los perfiles operativos mínimos (`READ` + `RIGHT_RECEIVE` / `RIGHT_DELIVER` /
`MANAGE_PURCHASING`, sin `VIEW_*`) y los aprobadores efectivos del motor abren **sólo** la solicitud que hoy pueden
accionar, la misma que ven en su bandeja. Al terminar la acción, sin `VIEW_*`, dejan de verla (403). La búsqueda y el
historial general siguen exigiendo `VIEW_OWN` / `VIEW_ENTITY`.

**HTTP:**
- sin el derecho mínimo de la acción (o sin la entidad de la solicitud) ⇒ **403**;
- CSRF inválido ⇒ 403 (nativo);
- acción autorizada rechazada por una regla de negocio ⇒ PRG + mensaje;
- acción correcta ⇒ PRG.

**Notificaciones:** el administrador ajusta destinatarios/plantillas en *Configuración → Notificaciones* (tipo
"Solicitud de compra"); el plugin no envía correos por su cuenta ni tiene cola propia.

## Tests
- **Unit (puro):** `php plugins/companypurchasing/tests/unit/run.php` — dinero exacto, scopes, política,
  costo por unidad (incl. propiedades aleatorias), transiciones/`syncPath`, payload/hash, UUID v4, saneamiento
  de `last_error` y escaneo estático (sin HTTP/Snipe/activos/Infocom/companyqr/float en el código nuevo).
- **Integración + E2E (en GLPI, obligatorio en CI):** `php bin/console plugins:companypurchasing:selftest` —
  P2D-1, P2D-2, P2D-3 (`[UPGRADE-P2D3]`, `[RECEIVE-*]`, `[OUTBOX]`, `[LEGACY-DEF]`…) y P2D-4 (`[UPGRADE-P2D4]`,
  `[DELIVERY-*]`, `[CLOSE]`, `[LEGACY-DELIVERY]`, `[P2D4-RIGHTS]`, `[INBOX]`, `[METRICS]`, `[NOTIFY]`,
  `[E2E-FULL]`), con procesos paralelos reales (`plugins:companypurchasing:concurrency-probe`, sólo con
  `COMPANYPURCHASING_ALLOW_PROBE=1`).
- **E2E HTTP real (obligatorio en CI):** `bash tests/e2e/companypurchasing-http.sh` — sesión, métodos (GET sobre una
  acción ⇒ 405 vía `MethodGuardController`), CSRF nativo (sin token / inválido / reutilizado ⇒ 403), PRG, escape de
  salida y ACL del lado servidor con un usuario sin derechos.
- **Guardas estáticas de la UI** (en `tests/unit/p2d4.php`): rutas de acción sólo POST, páginas sólo GET,
  `SecurityStrategy` en cada controlador, PRG, sin SQL en controladores, CSRF en cada formulario POST, `|raw` sólo
  para dropdowns nativos, sin tokens en plantillas, botones derivados del motor (nunca por estado) y cobertura
  i18n ES/EN.

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
