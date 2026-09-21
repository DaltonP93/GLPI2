# Changelog — Company Purchasing (`companypurchasing`)

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y versionado [SemVer](https://semver.org/lang/es/).

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
- **Referencias nativas válidas PARA la entidad de la solicitud:** `assertReference()` pasa a
  `assertReferenceForEntity(itemtype, id, requestEntityId)`. Ya **no** alcanza con
  `Session::haveAccessToEntity()` (un usuario con acceso a A y B podía adjuntar un maestro de B a una
  solicitud de A). Se valida con la semántica NATIVA del árbol de entidades de GLPI (`getSonsOf`/
  `getAncestorsOf`): el objeto existe y es de la **misma entidad** o de un **ancestro recursivo**; una
  referencia de otra RAMA no se adjunta aunque el usuario vea ambas. Aplica a `Group`/`Supplier`/`Budget`
  en create y update. Sin SQL directo al core.
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
