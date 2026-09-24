# Changelog — Company Signature (`companysignature`)

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y versionado [SemVer](https://semver.org/lang/es/).

## [0.5.0] — Fase 2D · P2D-2 (invalidación exacta por checkpoint)
### Changed
- **Invalidación EXACTA POR CHECKPOINT** (domain-agnostic): al materializar `approval_invalidated`, sólo se
  anulan las aprobaciones tomadas en una **visita de estado** iniciada en/después de la última entrada de
  la instancia en el checkpoint de reapertura (`reopen_to_code`). Antes se anulaban **todas** las
  aprobaciones previas de la instancia, lo que invalidaba evidencia de scopes no afectados (p. ej. la del
  jefe de área al reabrir la etapa comercial en `companypurchasing`). La visita se deriva del ledger porque
  el motor registra la decisión de un actor único **después** de la fila `transitioned` (en quórum, antes).
  Sin ledger/checkpoint localizable ⇒ comportamiento previo (anula todas; conservador).
- **Lectura incierta del ledger ⇒ PENDING, nunca "anular todo"**: `resolveCheckpoint()` devuelve un
  resultado explícito (`ok`/`pending`/`error`/`legacy`). Con `reopen_to_code`, si `history()` falla o la lectura
  no contiene la propia invalidación ⇒ `R_PENDING` (retryable) y **cero** evidencias de invalidación; ledger
  legible pero checkpoint nunca ingresado ⇒ `R_ERROR` visible. Sólo una invalidación legacy sin
  `reopen_to_code` anula todas (documentado).
- Tests: unit (`checkpointEntryId`, `visitStartOf`, `isVoidedByCheckpoint` en ambos órdenes,
  `resolveCheckpoint`) + selftest `[INVALIDATE-CHECKPOINT]` (reabrir S2 deja S1 `valid` y S2 `invalidated`) y
  `[INVALIDATE-LEDGER-FAIL]` (`history()` caído con `historyById()` disponible ⇒ pending, nada anulado; al
  volver, anulación exacta).

### Fixed — `install()` seguro en upgrade (0.4.0 → 0.5.0)
- GLPI vuelve a llamar `plugin_companysignature_install()` al actualizar el plugin. Antes,
  `ProfileRight::addProfileRights()` re-insertaba el derecho y el upgrade abortaba con
  `Duplicate entry '<perfil>-plugin_companysignature' for key 'unicity'`; ahora sólo se agrega si falta.
- La configuración ya no se sobrescribe: se siembran **sólo las claves ausentes**. Un upgrade conserva lo
  que ajustó el administrador y el high-watermark durable de la reconciliación (`last_seen_history_id`),
  que antes volvía a `0`.
- `CronTask::register()` es idempotente (no inserta si ya existe `(itemtype, name)`): el upgrade no duplica
  la Acción automática `reconcile` ni pisa su frecuencia/estado.
- Test: selftest `[UPGRADE]` sobre una instalación existente con derecho, configuración, derecho de perfil y
  frecuencia personalizados + evidencias reales: `install()` ×2 sin excepción; derecho una vez por perfil;
  personalizaciones preservadas; default ausente agregado; Acción automática única (mismo id);
  evidencias/versiones/cola intactas (conteo + huella sha256).

## [0.4.0] — Fase 2C (integridad probatoria)
### Hardening — durabilidad fail-closed (cola de reconciliación + lock del PDF)
- **Watermark FAIL-CLOSED (§1):** `ReconcileService::harvest()` sólo avanza `last_seen_history_id`
  sobre eventos **durablemente encolados y contiguos**. Si un evento no queda durable (`add()` falla y
  tampoco lo insertó otro worker), el watermark se detiene en el último id durable: la próxima corrida
  reintenta el hueco. Nunca "`history #N add FAIL → watermark pasa por encima`". Nuevo helper puro
  `safeWatermark()` con prueba unitaria (#100 OK, #101 falla, #102 existe → watermark queda en 100).
- **Anti-starvation por backoff (§2):** el worker filtra la **elegibilidad en SQL** (`status` +
  `attempts < MAX` + `next_retry_at <= now`), de modo que N tareas en backoff **no acaparan el batch**
  ni hambrean a una elegible posterior. Se mantiene el orden causal por `workflow_history_id`.
- **Estados del Materializer fail-closed (§3):** una dependencia caída **nunca** consume la tarea.
  `companyworkflow` no disponible → `PENDING` (retry); fila del ledger no legible → `PENDING`;
  instancia/sujeto **inconsistente** → `ERROR` **visible/auditable** (`last_error` saneado), no éxito
  silencioso. `DONE` sólo significa evidencia creada, ya existente, o evento sin evidencia por diseño.
- **PDF lock FAIL-CLOSED (§6):** si no se adquiere `GET_LOCK`, `compose()` **no** crea el `Document`
  (sin exclusión mutua no se materializa): la evidencia no se toca, el PDF queda **retryable**
  (`pending`) con diagnóstico saneado (`$lastError`) y **retryable e idempotente** vía
  `SignatureApi::composePdf()` (on-demand: el dominio/API/comando lo reintenta; un reintento con lock
  materializa exactamente un `Document`). La CronTask `reconcile` reconcilia la **evidencia** del
  ledger; **no** recompone el PDF (hoy no hay tarea automática que reintente PDFs `pending`). La
  aprobación nunca se revierte por esto.
- **Tests:** unit (`safeWatermark`); integración/E2E (starvation 200-en-backoff + 1 elegible;
  Materializer PENDING/PENDING/ERROR; PDF sin lock → 0 `Document`, retry → exactamente 1, idempotente).

### Fixed
- **PDF: persistencia por el MODELO.** Al introducir el lock advisory, `ApprovedPdfComposer::compose()`
  guardaba la versión con un `UPDATE` crudo que incluía `date_mod`, columna **inexistente** en
  `document_versions` (`Unknown column 'date_mod'`) → `pdf_status=error`. Se vuelve a
  `VersionStore::markPdfReady()/markPdfError()` (CommonDBTM), que no referencian `date_mod`. Se
  conservan el lock advisory (§6), el recovery por marcador y el relink idempotente.
- **Diagnóstico probatorio:** `ApprovedPdfComposer::$lastError` conserva la excepción REAL (clase +
  mensaje, **sin secretos**) en vez de esconderla sólo como `pdf_status=error` (el PDF nunca bloquea
  la evidencia, pero el motivo queda disponible).

### Added
- **Reconciliación DURABLE automática (§3):** tabla propia `reconcile_queue`
  (`UNIQUE(workflow_history_id)`, `status`/`attempts`/`last_error`/`next_retry_at`) + high-watermark
  `last_seen_history_id`. `Service/ReconcileService` = **harvest** (encola lo nuevo del ledger y
  avanza el cursor, sin full-scan) + **worker** (procesa pendientes con reintentos, independientes).
  `Model/ReconcileTask` expone la **CronTask nativa** `reconcile` (frecuencia configurable). Un
  evento sin snapshot/`evidence_ref` queda `pending` sin bloquear a los posteriores ni perderse.
- **`materialized_at`** separado de `event_date` (columna nueva).
### Changed
- **`event_date` PROBATORIO (§1):** la evidencia guarda como `event_date` la fecha ORIGINAL del
  ledger (momento de la decisión), y en `materialized_at` cuándo se materializó/reconcilió.
  `EvidenceRecorder` exige `event_date` válido (fail-closed).
- **Identidad por `evidence_ref` EXPLÍCITA (§2):** el `Materializer` ya **no infiere** la versión por
  timestamp; usa la `evidence_ref` del ledger y la valida (existe · mismo sujeto/entidad · versión y
  `content_sha256` coinciden). Sin `evidence_ref` válida ⇒ `pending` (no adivina). Dos versiones en
  el mismo segundo se resuelven sin ambigüedad. `versionInEffectAt()` queda como utilidad no probatoria.
- **Contexto histórico del aprobador (§5):** el `Materializer` copia `statedefs_id`/`steps_id`/
  `approver_kind`/`approver_ref`/`delegated_from` del ledger a `actor_role`/`actor_context`.
- **PDF concurrency-safe (§6):** `ApprovedPdfComposer` serializa la materialización por
  `document_versions_id` con un **lock con nombre de MySQL** (`GET_LOCK`/`RELEASE_LOCK`), INDEPENDIENTE
  de transacciones (dos workers no crean dos `Document`). No se envuelve `Document::add()` en un
  `beginTransaction()`/`FOR UPDATE` propio porque el `Document` nativo gestiona SUS PROPIAS
  transacciones y hace E/S de fichero: anidar rompería el commit. Se conserva el recovery por marcador
  (Document creado → caída antes de marcar `READY`) y el relink idempotente de `Document_Item`.
  (El lock se endureció luego a **FAIL-CLOSED** — ver «Hardening — durabilidad fail-closed» arriba: sin
  `GET_LOCK` **no** se crea `Document`; el marcador sigue evitando duplicados en el reintento.)
- **Invalidación:** se materializa con `idempotency_key` obligatoria y **actor DURABLE** reconstruido
  desde el ledger (aunque el listener en vivo nunca corra).
### Tests
- Nuevos: `event_date` original tras reconcile tardío; `evidence_ref` explícita (v1/v2 mismo segundo);
  cola cron durable (pendiente no bloquea / idempotente / restart); `idempotency_key` obligatoria;
  actor de invalidación durable; contexto de aprobador/delegación histórico; PDF concurrency/crash.

## [0.3.0] — Fase 2C (hardening probatorio)
### Added
- **Entrega DURABLE + reconciliador (§1):** `Service/Materializer` es la única vía de creación de
  evidencia desde el ledger de `companyworkflow`; `Command/ReconcileCommand`
  (`plugins:companysignature:reconcile`) materializa lo pendiente de forma **idempotente** y
  recupera `commit → caída → restart → reconcile → evidencia creada una sola vez`. Sin dependencia
  DB-a-DB externa (lee el ledger por la API de workflow) ni cambios de core.
- **Evidencia por aprobador (§4):** escucha `companyworkflow:decision_recorded` y crea una evidencia
  por CADA decisión individual; la **transición** de estado se registra como evidencia **separada**
  (`event_type=transition`). Un quórum 3/3 produce 3 evidencias `APPROVED` + 1 transición.
### Changed
- **Identidad real de eventos (§3):** la `idempotency_key` se basa en `workflow_history_id` +
  `document_version` + `evidence_type` (columna nueva `evidences.workflow_history_id` + índice).
  Un replay del mismo evento → misma evidencia; dos eventos históricos distintos (aunque compartan
  `from/to/action`) → evidencias distintas.
- **Prohibida la evidencia sin snapshot (§2):** el listener/reconciliador **nunca** crea evidencia
  válida sin versión documental vigente con `content_sha256` válido; si aún no hay snapshot, el
  evento queda **pendiente**. `VerificationService` es **fail-closed**: sin versión/snapshot/hash el
  estado nunca es `valid` (→ `tampered`). La versión se ata a la **vigente en el instante** del evento.
- **Invalidación EXACTA (§5):** se invalidan sólo las aprobaciones VIGENTES de la **instancia** dada,
  y cada evidencia `INVALIDATION` referencia explícitamente (`references_evidences_id`) la aprobación
  afectada. `VerificationService::isSuperseded()` usa esa referencia exacta: invalidar el workflow A
  no toca la evidencia del workflow B aunque compartan sujeto; `v1` invalidada / `v2` válida.
- **PDF crash-safe (§7):** `ApprovedPdfComposer` busca/relinкea el `Document` por un **marcador
  técnico estable** antes de crear; una caída entre crear el `Document` y `markPdfReady` no duplica
  al reintentar (no depende sólo de `documents_id`). El `Document_Item` se reconecta idempotente.
- **`invalidateApprovals()`** ahora se invoca con `RIGHT_ACT` (endurecido en `companyworkflow` 0.4.0).
### Tests
- Integración/E2E ampliados: por-aprobador y quórum 3/3; reconciliación tras listener perdido
  (idempotente); fail-closed sin snapshot; verificación fail-closed; invalidación exacta
  (dos instancias mismo sujeto; v1→v2); PDF crash-recovery. Unit: clave por `workflow_history_id`
  (replay vs. dos eventos distintos).

## [0.2.0] — Fase 2C
### Added
- **Evidencia de aprobación electrónica interna (ADR-0014 + gate D1–D5):** identidad autenticada +
  **hash del contenido versionado** + timestamp UTC + auditoría. **No** es firma digital certificada.
- **2 tablas propias reversibles** (prefijo `glpi_plugin_companysignature_`, `entities_id`):
  - `document_versions`: versión **inmutable** del contenido aprobado
    (`canonical_snapshot → content_sha256`) + artefacto PDF derivado (`documents_id`, `pdf_sha256`,
    `pdf_status`). Regeneración idempotente (**D1**).
  - `evidences`: evidencia **append-only** de decisión/invalidación (`verification_token` opaco,
    `idempotency_key` UNIQUE, `references_evidences_id`).
- **Canonicalización determinista (D4):** contrato domain-agnostic
  `{schema, subject_type, subject_id, entity_id, document_version, payload}`; orden determinista de
  claves, listas preservan orden, `schema`/`document_version` hasheados, **floats rechazados**
  (montos/decimales como string exacto). `Hasher` SHA-256 reproducible. Se conserva el snapshot exacto.
- **Integración con `companyworkflow` (D2):** escucha `companyworkflow:transitioned` y
  `:approval_invalidated` (listener **idempotente**: retry/duplicado/restart → una sola evidencia).
  La invalidación por cambio sustantivo usa la operación genérica
  `WorkflowApi::invalidateApprovals()`; **conserva** la evidencia previa (append-only).
- **PDF aprobado como `Document` NATIVO (D1):** `ApprovedPdfComposer` (TCPDF) con versión + hash + QR
  al endpoint de verificación; hereda ACL/entidad/almacenamiento del core; **no bloquea** la
  evidencia (fallo → `pdf_status=error` + reintento).
- **QR propio (D3):** `VerificationQrRenderer` reutiliza la librería QR nativa de GLPI; **sin
  dependencia funcional de `companyqr`**.
- **Verificación interna autenticada (§7/§13):** `GET /plugins/companysignature/verify/{token}`
  (AUTHENTICATED) + `VerificationService` (ACL `RIGHT_VERIFY` · multi-entidad estricta · recomputa
  el hash del snapshot · refleja `invalidated`/`tampered` · no filtra datos ni existencia).
- **Puerto de firma certificada (§8):** `CertifiedSignerInterface` + `NullSigner` (no-op). **Sin
  proveedor/PKI.** Una imagen de firma **no** es firma certificada.
- **ACL:** derecho `plugin_companysignature` con bits `READ · RIGHT_RECORD · RIGHT_VERIFY ·
  RIGHT_CONFIG` (patrón `ProfileRight`, Super-Admin en instalación).
- **Eventos propios:** `companysignature:evidence_recorded`, `companysignature:approval_invalidated`.
- **Tests:** unit puro (`tests/unit/run.php`, canonicalización/hash/idempotencia/token/NullSigner) +
  integración/E2E (`plugins:companysignature:selftest`).
- **i18n** ES/EN; **CHANGELOG**; **README** actualizado.

### Notes
- **Domain-agnostic (D5):** ninguna referencia a Compras (estados, proveedores, cotizaciones, ítems,
  montos). Los campos sustantivos exactos los definirá `companypurchasing` (Fase 2D).
- Regla 0: no se modifica el core (sólo hooks/API/servicios nativos).

## [0.1.0]
### Added
- Esqueleto inicial del plugin (Fase 0): `setup.php`, `hook.php` y estructura
  de carpetas (`src/`, `locales/`, `templates/`, `tests/`).
