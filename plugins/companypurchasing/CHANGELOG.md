# Changelog — Company Purchasing (`companypurchasing`)

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y versionado [SemVer](https://semver.org/lang/es/).

## [0.4.0] — Fase 2D · P2D-3 (recepción física + handoff a SI-4)
Ver `../../docs/adr/ADR-0019-companypurchasing-receiving.md` (sólo decisiones nuevas; el resto lo fija el gate §5–§7).

### Added
- **Fase de compra en el motor** (nueva VERSIÓN de la definición; las instancias previas conservan la suya):
  `APPROVED →start_purchase→ IN_PURCHASE →receive_partial→ PARTIALLY_RECEIVED →receive_complete→ RECEIVED`
  (+ `IN_PURCHASE →receive_complete→ RECEIVED`), con condiciones `purchase_bound`/`receipt_bound` que sólo
  aporta Compras. `RECEIVED` es intermedio (no cierra la instancia). `domain_state` sigue siendo proyección.
- **`ReceivingService::startPurchase()`**: exige integridad limpia + APPROVED; congela `ordered_qty`, precio
  final, costo de línea, proveedor/cotización y política de costo (pinneada) en una transacción.
- **`ReceivingService::receive()`** atómico con `idempotency_key` obligatoria: `FOR UPDATE` de las líneas, lote,
  unidades con `receipt_unit_uuid` (UUID v4 CSPRNG), costo exacto por unidad, outbox por unidad inventariable,
  contadores y marcador de sincronización en la MISMA transacción; rollback total ante cualquier fallo; misma
  clave ⇒ mismo lote. Recepción parcial por lotes; serial opcional único por línea; tope de unidades por lote.
- **Saga `ReceivingSync`** post-COMMIT con marcador durable (`receiving_seq` / `receiving_synced_seq`);
  convergencia en vivo o por la Acción automática nativa; anomalías reportadas sin mutar.
- **Costo atribuible exacto** (`CostPolicy`/`CostPolicyStore`/`CostAllocator`): base `final_unit_price`;
  descuentos/impuestos/flete de cabecera sólo si se incluyen (por defecto no); asignación por valor de línea con
  mayor resto; reparto por unidad sin pérdida (Σ unidades = costo de línea). `Decimal::mulStr/divModStr`.
- **Outbox + `Api\PurchasingIntegrationApi`** (`claimPending`, `getHandoff`, `acknowledgeProcessed`,
  `markRetry`, `markError`): payload v1 inmutable (canónico + sha256), lease con token y reloj de la BD
  (`FOR UPDATE SKIP LOCKED`), token viejo rechazado, RETRY con `next_retry_at`, ERROR final, intentos máximos,
  `last_error` saneado. Nuevo derecho de mínimo privilegio `RIGHT_INTEGRATION` (1024).
- Esquema (upgrade idempotente desde 0.3.0): tablas `receipt_batches`, `receipt_units`, `inventory_outbox`,
  `cost_policies`; columnas `items.ordered_qty/received_qty/purchase_unit_price/line_cost_total` y
  `requests.purchase_started_at/purchase_quotes_id/purchase_suppliers_id/cost_policies_id/receiving_seq/
  receiving_synced_seq`. Configuración nueva sembrada sólo si falta.
- i18n ES/EN de los textos nuevos; eventos de auditoría `purchase.started`, `receipt.recorded`,
  `receiving.synced`, `receiving.anomaly`, `handoff.done/retry/error`.

### Changed
- `RIGHT_RECEIVE` pasa de reservado a **activo**. Super-Admin recibe también `RIGHT_INTEGRATION`.
- Congelamiento tras iniciar la compra: `QuoteManager` y `amendLineQuantity` rechazan cambios (fail-closed);
  una política no puede habilitar cotizar/enmendar en estados de la fase de compra.
- Deriva de integridad tras iniciar la compra: `enforceIntegrity()` **no reabre** el circuito (reporta; la
  recepción queda bloqueada hasta resolverla).
- Reconciliación (`reconcile`/`reconcileAll`/comando/Acción automática): converge la saga de recepción y reporta
  `recepcion_pendiente`, `anomalias_recepcion` y `definicion_anterior` (falla si hay instancias APROBADAS con una
  versión anterior sin fase de compra: requieren decisión humana).

### Fixed
- Selftest: `CronTask::launch()` deja `glpicronuserrunning` en la sesión CLI (GLPI no lo limpia) y, con él,
  `Session::haveRight()` devolvía siempre `true` en los escenarios POSTERIORES; `applySession()` ahora sale del
  modo cron en cada cambio de sesión (las comprobaciones de ACL posteriores vuelven a ser reales).

### Tests
- Unit: +46 (definición/`syncPath`/`receivingTarget`, política, Decimal, CostPolicy, CostAllocator con
  propiedades aleatorias, HandoffPayload, UUID v4, saneamiento, escaneo estático de límites).
- Selftest: `[UPGRADE-P2D3]`, `[P2D3-PERSIST]`, `[PURCHASE-START]`, `[FREEZE]`, `[RECEIVE-PARTIAL]`,
  `[RECEIVE-IDEMPOTENT]`, `[RECEIVE-VALIDATION]`, `[RECEIVE-ATOMIC]`, `[RECEIVE-CRASH-SYNC]`, `[RECEIVE-CONC]`
  (procesos reales), `[POST-PURCHASE-INTEGRITY]`, `[RECEIVE-ACL]`, `[OUTBOX]` (claim concurrente real, lease,
  token), `[LEGACY-DEF]`, `[NO-SIDE-EFFECTS]`; `[MIGRATE]` cubre las tablas nuevas.

## [0.3.0] — Fase 2D · P2D-2 (circuito de aprobación)
Ver `../../docs/adr/ADR-0018-companypurchasing-approvals.md`.

### Hardening — integridad de la saga (pasada acotada)
- **Marca DURABLE de integridad** (`..._integrity` + `requests.integrity_state`): `selectQuote`,
  `updateQuote` (cotización seleccionada) y `amendLineQuantity` la escriben en **su misma transacción local**
  (solicitud, scope, causa, actor, estado/lock del motor, `idempotency_key`). Se resuelve sólo tras
  `invalidateApprovals()` CONFIRMADA o verificación contra el ledger del motor; una reparación fallida la deja.
  REQUEST_SCOPE tiene prioridad sobre COMMERCIAL. `integrityStatus()`/`isFullyApproved()`: nunca "aprobación
  limpia" mientras haya marca o deriva, aunque el motor diga APPROVED. `decide()` repara o falla cerrado.
  Las mutaciones que pueden exigir reabrir verifican **antes** `RIGHT_ACT` (+ `RIGHT_RECORD` de Firma).
- **`expectedState` obligatorio**: `decide(requestId, action, expectedState, comment)`; etapa distinta ⇒
  `stage_changed` sin nueva decisión (un usuario en Compras y Finanzas no "aprueba de más" al reintentar).
- **Política pinneada por solicitud** (`..._policies`, JSON canónico + hash; `requests.policies_id`, fijada
  en `submitDraft` junto a `scopes_version`): `stage_scopes`, `scope_checkpoints`, `pdf_stages`,
  `quote_states`, `amend_states` ya no se leen en vivo de la configuración.
- **Reconciliación convergente**: `reconcileAll` por lotes con cursor persistido + wrap-around (sin
  starvation); **Acción automática nativa** `reconcileprojection` (CronTask); reporta solicitudes enviadas sin
  instancia e integridad pendiente (el comando sale con error ante esas anomalías). `submit()` exige definición
  activa **antes** de `submitDraft()` (sin definición la solicitud queda en DRAFT).
- **Evidencia exacta**: toda aprobación viva debe coincidir en `document_versions_id`, `document_version`,
  `content_sha256` y scope con el ledger propio; ref incompleta/alterada ⇒ deriva. El snapshot comercial
  revalida en vivo que el Supplier seleccionado siga aplicando a la entidad.
- Tests: unit 134 (política, `evidenceRefMatches`, `planBatch`); selftest `[INTEGRITY-DIRTY]`,
  `[REOPEN-CAPABILITY]`, `[EXPECTED-STATE]`, `[POLICY]`, `[CRASH-0]`, `[SUBMIT-PRECHECK]`,
  `[RECONCILE-CURSOR]` (incl. CronTask nativa), `[EVIDENCE-TAMPER]`, `[SUPPLIER-MOVED]`; `[MIGRATE]` cubre
  las tablas/columnas nuevas y el (des)registro de la Acción automática.

### Added
- **Integración con `companyworkflow` (único motor):** `PurchasingWorkflow` describe el proceso
  (`DRAFT → PENDING_AREA_HEAD → PURCHASING → PENDING_FINANCE → APPROVED`, más `RETURNED`/`REJECTED`/
  `CANCELLED`) y `ApprovalOrchestrator::publishDefinition()` lo publica con `DefinitionBuilder` desde la
  **configuración** (grupo aprobador, quórum y SLA por etapa; sin grupos ⇒ no publica). `APPROVED` es
  intermedio (P2D-3 continúa; la invalidación post-aprobación sigue siendo posible). Las transiciones
  `approve` exigen la condición `evidence_bound = 1` (la UI genérica del motor no aprueba sin evidencia).
- **Saga idempotente `ApprovalOrchestrator`** (`submit`/`decide`): snapshot del scope de la etapa →
  `DocumentVersionAllocator` → `SignatureApi::recordDocumentVersion()` → `evidence_ref =
  {document_versions_id, document_version, content_sha256}` → `WorkflowApi::transition()` con
  `expected_lock_version` → auditoría → proyección. **Ninguna llamada a workflow/firma dentro de una
  transacción local.** Reintentos convergen sin duplicar versión, transición ni evento. Una aprobación se
  liga SIEMPRE al scope de su etapa (no producible ⇒ fail-closed); un rechazo/devolución se liga a
  `REQUEST_SCOPE` si el scope de la etapa aún no es producible (Compras puede devolver sin cotización).
- **`REQUEST_SCOPE` / `COMMERCIAL_FINANCIAL_SCOPE`:** el snapshot comercial incluye proveedor y cotización
  seleccionados, `final_unit_price`/`line_total` por línea, descuentos, impuestos, flete, moneda, total
  final y presupuesto — importes como **string exacto** (PYG escala 0).
- **Allocator de versión documental de dominio** (`..._docseq` + ledger `..._doc_versions`):
  monotónico por solicitud, concurrency-safe, no reutilizable; reutiliza la última versión del scope sólo
  si el contenido semántico no cambió. Nunca `document_version = 1` hardcodeada ni inferida por fecha.
- **Invalidación por scope/checkpoint** derivada del **ledger del motor** (aprobaciones vivas por visita de
  estado): cambio de un scope aprobado ⇒ nueva versión + `invalidateApprovals()` con `idempotency_key` y
  `reopen_to_code` del checkpoint (cantidad tras el jefe → reabre al jefe; precio final tras Gerencia →
  reabre Compras, el jefe sigue válido). Se re-evalúa tras cada mutación comercial y **antes de cada
  decisión** (red de seguridad fail-closed).
- **Cotizaciones (`QuoteManager`)**: `Supplier` nativo y `Document`/`Document_Item` nativos (N adjuntos)
  validados PARA la entidad de la solicitud; selección con `requests.quotes_id_selected` como **única**
  fuente de verdad (lock común + `lock_version` esperado); totales derivados (`QuoteMath`, sin float).
- **Enmienda post-aprobación** (`RequestManager::amendLineQuantity`, `MANAGE_PURCHASING`, estados
  `amend_states`).
- **PDF**: `composePdf()` explícito tras aprobar una etapa `pdf_stages`; fallo ⇒ `pdf_status=error` +
  `pdf.failed`, **sin** revertir workflow/evidencia; `retryPdf()` idempotente.
- **Proyección `domain_state`** (`StateProjection`): cache del estado confirmado del motor (el motor gana);
  listener best-effort `companyworkflow:transitioned/approval_invalidated`; `reconcile()`/`reconcileAll()`
  y comando `plugins:companypurchasing:reconcile` (idempotente; re-enlaza instancia tras caída).
- Editabilidad **autoritativa**: con instancia enlazada decide `is_editable` del estado del motor
  (`DRAFT`/`RETURNED`); `ReferenceValidator` extraído (misma semántica P2D-1) y compartido.
- Eventos de negocio P2D-2 (`workflow.*`, `checkpoint.recorded`, `approval.decided`, `scope.invalidated`,
  `quote.*`, `line.amended`, `pdf.*`, `state.reconciled`); `Audit::recordOnce()` idempotente.
- Esquema: tablas `quotes`, `quote_items`, `doc_versions`, `docseq`; columnas `requests.quotes_id_selected`,
  `workflow_lock_version`, `workflow_synced_at` (con **upgrade** idempotente desde P2D-1). i18n ES/EN.

### Fixed
- `install()` idempotente en **upgrade**: ya no re-agrega el derecho del plugin (UNIQUE de
  `glpi_profilerights`) ni pisa la configuración ajustada por un administrador (sólo siembra claves ausentes).

### Tests
- Unit: 113 en la entrega inicial (resta exacta, `QuoteMath`, hash de payload, spec de la definición, reinicio de scopes,
  aprobaciones vivas desde el ledger —ambos órdenes del motor—, mapas, registro comercial).
- Selftest obligatorio (mismo comando, trait `ApprovalSelftestScenarios`): flujo completo, rechazo,
  devolución, quórum secuencial y **concurrente**, delegación, selección concurrente de cotización,
  invalidación REQUEST/COMMERCIAL (evidencia del jefe válida / Gerencia invalidada), allocator concurrente,
  `evidence_ref` exacta, caída antes/después de `transition`, PDF ok/falla/retry, ACL, multi-entidad,
  cross-branch, dinero; `[MIGRATE]` con upgrade desde el esquema P2D-1.

## [0.2.0] — Fase 2D · P2D-1 (núcleo de compras)
### Hardening — atomicidad de mutaciones (pasada final acotada P2D-1)
- **Mutación + auditoría atómicas (todas las mutaciones):** además de `submitDraft`, ahora `createDraft`,
  `updateDraft`, `addLine`, `updateLine` y `removeLine` confirman su cambio de datos **y** su evento de
  auditoría JUNTOS, en una transacción local sobre tablas propias (`BEGIN {mutar → recomputar total →
  Audit::record()} COMMIT`; ante cualquier excepción → `ROLLBACK` + rethrow, sin estado parcial). El lock
  común `request_<id>` queda igual. `createDraft` es atómico respecto de `REQUEST_CREATED`: si la auditoría
  no persiste, no queda una **solicitud huérfana**.
- **Ningún write fallido se vuelve éxito en silencio:** `recomputeEstimated()` comprueba el resultado del
  `Request::update()` y lanza si falla; `removeLine()` comprueba el resultado de `RequestItem::delete()`.
  Se mantienen los checks de add/update previos.
- **Referencias nativas válidas PARA la entidad de la solicitud (resolución AUTORITATIVA):**
  `assertReference()` pasa a `assertReferenceForEntity(itemtype, id, requestEntityId)`. Ya **no** alcanza
  con `Session::haveAccessToEntity()` (un usuario con acceso a A y B podía adjuntar un maestro de B a una
  solicitud de A). La aplicabilidad se resuelve recorriendo la cadena de padres (`entities_id`) de la
  solicitud con el modelo `Entity` (`getFromDB`, valor ACTUAL de la columna), **sin usar nunca la caché
  del árbol** (`getSonsOf()`/`getAncestorsOf()`): en GLPI 11 ambas usan `$GLPI_CACHE` +
  `ancestors_cache`/`sons_cache`, que pueden quedar **stale** tras mover una entidad entre ramas y
  autorizar una referencia cross-branch inexistente (**FAIL-OPEN**). Nunca se acepta una relación por
  coincidencia de una caché. El objeto debe ser de la **misma entidad** o de un **ancestro recursivo
  ACTUAL**; otra RAMA → rechazo. Recorrido **fail-closed** (conjunto `visited`, profundidad máxima,
  rechazo ante ciclo/`entities_id` inválido/entidad inexistente). Aplica a `Group`/`Supplier`/`Budget` en
  create y update. Sin SQL directo al core (se usa el modelo soportado).
- **Campos de identidad inmutables en update:** un intento de cambiar `users_id_requester` en `updateDraft`
  se **rechaza explícitamente** (ya no se ignora en silencio); `is_recursive != 0` enviado por el
  solicitante se **rechaza** (baseline 0). No hay "crear/editar en nombre de otro".
- **Tests de rollback deterministas:** inyección de fallo de `Audit` por evento para `createDraft`,
  `updateDraft`, `addLine`, `updateLine`, `removeLine` → la operación lanza, el dato anterior queda intacto,
  no queda evento parcial y `amount_estimated` no cambia. Se mantienen y re-ejecutan los tests concurrentes
  (numeración, doble submit, edit/addline vs submit, mutaciones simultáneas).

### Hardening — invariantes A/B/C/D (pasada acotada previa P2D-1)
- **A · Lock COMÚN por solicitud (todas las mutaciones):** `updateDraft`/`addLine`/`updateLine`/
  `removeLine`/`submitDraft` se serializan ahora bajo el MISMO advisory lock `request_<id>` (antes el
  lock era sólo del submit). Cada mutación: adquirir lock → **recargar FRESCO** → entidad+ACL → DRAFT
  si corresponde → mutar → recomputar total → auditar → liberar. Impide "editar un DRAFT que otro
  worker ya envió" (efecto parcial tras quedar PENDING) y los **totales stale** por mutaciones de líneas
  simultáneas. Probado con procesos REALES en paralelo: edit-vs-submit, addline-vs-submit y dos
  `addLine` simultáneos (line_no serializado, total siempre cuadra).
- **B · Validación SEMÁNTICA de approval scopes:** vocabulario explícito `ScopeCatalog::ALLOWED_KEYS`
  (incluye las claves comerciales futuras de P2D-2). `assertVersionComplete()` rechaza clave
  desconocida/typo, duplicada, `REQUEST_SCOPE` contaminado con claves comerciales, incumplir baseline
  (`requester`,`lines`) y scope vacío. `ScopeSnapshotBuilder::selectFields()` es **fail-closed**: una
  clave protegida que el builder NO puede producir **lanza** (jamás un snapshot parcial que luego se firma).
- **C · Identidad del solicitante y departamento cerrados:** `users_id_requester` = usuario autenticado
  SIEMPRE; declarar otro solicitante se **rechaza** (no hay "crear en nombre de" en P2D-1).
  `is_recursive` no queda bajo control del solicitante (baseline 0). `groups_id_department > 0` exige un
  `Group` existente y **visible desde la entidad** de la solicitud (fail-closed).
- **D · Frontera submit + auditoría ATÓMICA y DURABLE:** la reserva de número sigue en su transacción
  independiente (hueco permitido si lo posterior falla, nunca reciclado); luego, en UNA transacción
  local sobre tablas propias: `DRAFT→PENDING` + INSERT `REQUEST_SUBMITTED` confirman JUNTOS. `Audit::record()`
  comprueba el resultado de `PurchasingEvent::add()` y **lanza** si el evento no persiste (→ ROLLBACK: la
  solicitud NO queda PENDING). Nuevo `events.idempotency_key` **UNIQUE** (`request-submit:<id>`) hace el
  evento idempotente durable. Invariante: `PENDING ⇔ existe exactamente un REQUEST_SUBMITTED durable`.
  Probado con inyección de fallo determinista (crash entre UPDATE e INSERT) + reintento que completa.

### Hardening — consistencia/concurrencia (pasada previa P2D-1)
- **Numeración multi-entidad:** se elimina el `UNIQUE` GLOBAL de `requests.number` (hacía colisionar
  A#1 con B#1) y se reemplaza por **`UNIQUE(entities_id, number)`** + **`UNIQUE(entities_id, number_scope,
  number_year, number_seq)`**. El texto visible `REQUEST-<año>-<seq>` puede coexistir ENTRE entidades y
  jamás repetirse DENTRO de una entidad/año. `number`/`number_seq` son NULL en borrador.
- **`submitDraft()` concurrency-safe + idempotente:** serializado por **advisory lock**
  (`GET_LOCK(cpur_<db>_submit_<id>)`): re-lectura fresca → ACL/entidad/DRAFT → scopes → reservar número →
  update → audit → `RELEASE_LOCK`. Un reenvío de una solicitud ya enviada devuelve el **mismo** número
  (idempotente): nunca reserva otro número ni emite otro `REQUEST_SUBMITTED`.
- **Scope pinning FAIL-CLOSED:** `ScopeCatalog::fields()` ya **no** cae a defaults en runtime;
  `assertVersionComplete()` exige que la versión vigente exista y sea válida ANTES de reservar número
  (si falta/corrupta, la solicitud sigue DRAFT y no consume número). `ScopeSnapshotBuilder` **no** captura
  un `Money::ofStored()` inválido: un importe persistido inconsistente **lanza** (nunca entra crudo al snapshot).
- **`document_version` no hardcodeada:** `ScopeSnapshotBuilder::build()` produce sólo el snapshot
  SEMÁNTICO (sin `document_version`); `envelope($semantic, $documentVersion)` exige el número `> 0` como
  parámetro (lo declara el dominio en P2D-2; el allocator NO es de P2D-1).
- **Referencias Supplier/Budget validadas:** en create/update, `>0` debe EXISTIR y ser VISIBLE en su
  entidad (fail-closed); `0` = sin referencia. No se duplican maestros ni se saltan sus modelos.
- **Concurrencia REAL probada:** nuevo probe interno `plugins:companypurchasing:concurrency-probe` +
  selftest que lanza **procesos paralelos reales** contra MariaDB: N asignaciones simultáneas → N números
  distintos (secuencia contigua); doble `submit` del mismo request → una sola transición/número/evento.

### Added
- **Esquema propio (migraciones reversibles):** `requests`, `items`, `numbering`, `events`,
  `scope_defs` (prefijo `glpi_plugin_companypurchasing_`). `install/uninstall/reinstall` verificados.
- **ACL por acción** (`plugin_companypurchasing`): `CREATE_REQUEST`, `VIEW_OWN`, `VIEW_ENTITY`,
  `EDIT_DRAFT`, `MANAGE_CONFIG` (activos) + reservados `MANAGE_PURCHASING`/`RECEIVE`/`DELIVER`/
  `VIEW_METRICS`. Multi-entidad **fail-closed**.
- **Dinero EXACTO** sin `float` (`Decimal`/`Money`/`CurrencyPolicy`): `DECIMAL(20,6)` + `currency_code`;
  importes como string exacto; **PYG escala 0**; validación de escala por moneda; **no redondea**
  (un importe inválido falla la validación).
- **Numeración** `UNIQUE(entities_id, scope, year)` **transaccional/concurrency-safe**; el número
  visible se asigna al **abandonar DRAFT** por primera vez; no se reutiliza; se aceptan huecos.
- **CRUD de borrador** (`RequestManager`): crear/editar solicitud y líneas; `submit` (asigna número +
  pinnea `scopes_version`). Identidad de línea = `id`; `line_no` sólo presentación.
- **Approval scopes** versionados/configurables (`ScopeDef`/`ScopeCatalog`) + **snapshot builder**
  determinista (`ScopeSnapshotBuilder`) que entrega a Firma el contrato esperado. `REQUEST_SCOPE` NO
  incluye proveedor/cotización/precio final.
- **Auditoría de negocio** append-only (`PurchasingEvent`/`Audit`), sin secretos, con `correlation_id`.
- **Tests:** unit puro (dinero/escala/scopes/numeración/cantidades) + `plugins:companypurchasing:selftest`
  (integración + E2E), ahora **obligatorio** en CI junto a los otros tres.
### Notes
- **No** integra `companyworkflow`/`companysignature` ni implementa recepción/outbox/Snipe/SI-4 (P2D-2…P2D-4).
- El `domain_state` es **snapshot/cache**; la autoridad de estados será `companyworkflow` (P2D-2).
- Deuda técnica: cantidad DECIMAL por unidad de medida (no inventariables) diferida; v1 exige entero positivo.

## [Unreleased]
### Added
- **Fase 2D — Gate native-first (sólo documentación, sin lógica):**
  `../../docs/architecture/companypurchasing-native-first-gate.md`. Fija el alcance de v1
  reconciliado con el **código real ya mergeado** (`WorkflowApi`/`SignatureApi` reales; SI-1
  read-only): integración con `companyworkflow` (sin segundo motor) y `companysignature`,
  recepción física por unidad (`receipt_unit_uuid`), costo atribuible por línea, límite con
  Snipe-IT (v1 produce **outbox**; SI-4 futuro escribe), tablas mínimas, ACL/multi-entidad y
  tests obligatorios. Pendiente de aprobación humana.
- **Precisión del gate (docs-only):** `evidence_ref` fijada al contrato REAL
  `{document_versions_id, document_version, content_sha256}` (validado por
  `Materializer::resolveRef()`); PDF vía `SignatureApi::composePdf()` **on-demand** (el
  listener materializa evidencia, **no** compone PDF; sin Cron); **approval scopes/checkpoint
  snapshots** en vez de una lista global de campos (invalida desde el checkpoint afectado,
  `reopen_to_code` derivado del scope); `receipt_units.items_id` como FK (no `line_no`);
  numeración `UNIQUE(entities_id, scope, year)`; cotización elegida por
  `requests.quotes_id_selected` (sin boolean concurrente) + adjuntos `Document_Item` N;
  **recepción+outbox atómicos** (`FOR UPDATE`, ROLLBACK) con test de recepción concurrente
  10=6+6→máx 10; **`PurchasingIntegrationApi`** para que SI-4 consuma por API (no SQL directo)
  con estados `PENDING/LEASED/DONE/RETRY/ERROR`; precisión monetaria sin `float` (PYG `scale=0`).
- Esqueleto inicial del plugin (Fase 0): `setup.php`, `hook.php` y estructura
  de carpetas (`src/`, `locales/`, `templates/`, `tests/`).
