# Changelog — Company Signature (`companysignature`)

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y versionado [SemVer](https://semver.org/lang/es/).

## [0.4.0] — Fase 2C (integridad probatoria)
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
  `document_versions_id` con `SELECT … FOR UPDATE` (dos workers no crean dos `Document`), conservando
  el recovery por marcador (Document creado → caída antes de `markPdfReady`) y el relink idempotente
  de `Document_Item`.
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
