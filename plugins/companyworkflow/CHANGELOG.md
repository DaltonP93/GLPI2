# Changelog — Company Workflow (`companyworkflow`)

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y versionado [SemVer](https://semver.org/lang/es/).

## [0.5.0]
### Changed
- **Referencia probatoria EXPLÍCITA en el ledger (§2):** las transiciones/decisiones propagan una
  `evidence_ref` OPACA aportada por el dominio (`{document_versions_id, document_version,
  content_sha256}`) en `meta_json` de `DECISION_RECORDED`/`TRANSITIONED`. El motor **no** interpreta
  ni valida esa tabla (sigue siendo domain-agnostic); el consumidor materializa evidencia por
  referencia explícita, **sin inferir por timestamp**.
- **Contexto HISTÓRICO del aprobador (§5):** `DECISION_RECORDED` guarda `statedefs_id`, `steps_id`,
  `approver_kind`, `approver_ref` y `delegated_from` **en el momento** de la decisión (nuevo
  `ApproverResolver::approverContext()`), para que la evidencia conserve el contexto aunque luego
  cambien grupos/delegaciones.
- **`invalidateApprovals()` (§4):** `idempotency_key` **obligatoria** y validada (8–190,
  `[A-Za-z0-9._:-]`) — fail-closed **antes** de mutar votos/estado. El **actor** de la invalidación
  se conserva DURABLEMENTE en `meta_json` (+ `reopen_to_code`/`document_version`) y `is_system`
  refleja si hubo usuario, de modo que la reconciliación reconstruye el mismo actor aunque el
  listener en vivo nunca corra.

## [0.4.0]
### Added
- **Ledger durable + identidad real de eventos (hardening §1/§3/§4):**
  - `HistoryEvent::EVENT_DECISION_RECORDED`: una fila DURABLE por **decisión individual** de actor
    (en quórum, una por aprobador), que actúa de **ledger** para materializar evidencia externa
    idempotente y recuperable.
  - Los eventos de dominio ahora llevan `workflow_history_id` (id de la fila de historial, devuelto
    por `AuditBridge::record()`): `companyworkflow:decision_recorded`, `:transitioned` (id de la
    transición) y `:approval_invalidated` (id de la invalidación). Identidad **estable** para
    idempotencia y reconciliación (ya no `from->to:action`).
  - Emisión **post-commit** acumulada (`queueEmit`/`flushEmits`): el historial se compromete
    atómicamente con la transición y los eventos se disparan tras confirmar; un consumidor caído se
    recupera por reconciliación.
  - `WorkflowApi::history()` / `historyById()`: lectura del ledger como **API** (para reconciliadores
    externos como `companysignature`, sin acoplar a la tabla).
  - Evidencia **por aprobador**: cada voto de quórum emite su propia decisión durable; la transición
    de estado se mantiene como evento **separado**.
- **`NotificationBridge`**: el evento `:transitioned` se emite **siempre** (los consumidores de
  evidencia dependen de él); `notifications_enabled` sólo gobierna el envío de notificaciones.

### Changed
- **`invalidateApprovals()` ACL endurecida (§6):** exige `WorkflowDef::RIGHT_ACT` (no basta `READ`),
  además del acceso a entidad. Mantiene idempotencia, `lock_version`/`expectedVersion` y fail-closed.
  El evento emitido incluye `workflow_history_id` y `document_version`.
- **Tests:** el escenario `[INVALIDATE]` del selftest verifica ahora `RIGHT_ACT` (READ→`DENIED_ACL`;
  `RIGHT_ACT`+entidad→permitido) y la presencia de `decision_recorded` en el ledger tras un voto.

## [0.3.0]
### Added
- **Extensión genérica `invalidateApprovals()` (D2, para Fase 2C `companysignature`):**
  - `Engine::invalidateApprovals(int $instanceId, string $reason, array $context = [], ?int $expectedVersion = null)`
    y su fachada `Api\WorkflowApi::invalidateApprovals(...)`. **Domain-agnostic**: no conoce firmas,
    documentos ni Compras — cualquier consumidor la invoca al detectar que el contenido aprobado
    cambió de forma sustantiva.
  - **Fail-closed:** instancia inexistente → `ERROR`; sin acceso a la entidad → `DENIED_ENTITY`;
    sin derecho `plugin_companyworkflow` (READ) → `DENIED_ACL`; instancia no abierta → `CLOSED`.
  - **Concurrencia:** `SELECT ... FOR UPDATE` + `expectedVersion`/`lock_version` (control optimista);
    conflicto → `CONFLICT_VERSION`.
  - **Idempotente:** `context['idempotency_key']` (misma clave ⇒ no-op OK, no reabre dos veces).
  - **Política v1:** limpia los votos y **reabre** al checkpoint configurable
    (`context['reopen_to_code']`) o, por defecto, al **estado inicial** de la definición.
  - **Auditoría append-only** (`approval_invalidated` con `reason` + `idempotency_key` + trazabilidad
    opcional `subject_type`/`subject_id`/`document_version`). **Nunca borra historial.**
  - Emite el evento post-commit `companyworkflow:approval_invalidated` (best-effort, no revierte).
- **Tests:** escenario `[INVALIDATE]` en `plugins:companyworkflow:selftest` (integración/E2E):
  reapertura al inicial, idempotencia, checkpoint configurable, conflicto de versión, fail-closed
  sobre instancia cerrada e instancia inexistente.

## [Unreleased]
### Added
- **Motor de workflow genérico (ADR-0012):**
  - 8 tablas propias con migración reversible (`defs · statedefs · transitions · steps ·
    instances · assignments · delegations · history`), prefijo `glpi_plugin_companyworkflow_`.
  - Definiciones **versionadas**; las instancias conservan su versión (sin cambios silenciosos).
  - Estados/transiciones/condiciones configurables; `ConditionEvaluator` declarativo (sin `eval`).
  - Etapas con **quórum** (`count`/`percent`), aprobadores por group/profile/user, **delegación**.
  - Acciones submit/approve/reject/**return**/cancel; comentario obligatorio y derecho por transición.
  - Transición **atómica y fail-closed** con **control optimista** (`lock_version`) y voto UNIQUE
    → evita doble aprobación por concurrencia/replay.
  - **Multi-entidad estricta** y **ACL** por bits del derecho `plugin_companyworkflow`.
  - **SLA + escalamiento** vía CronTask (`Instance::cronEscalation`); nunca aprueba solo.
  - **Auditoría append-only** (`..._history`).
  - Fachada `Api\WorkflowApi` + evento `companyworkflow:transitioned`.
  - Controlador `WorkflowActionController` (POST, AUTHENTICATED, CSRF nativo).
- **Tests:** unit puro (`tests/unit/run.php`) + integración/E2E (`plugins:companyworkflow:selftest`).
- **i18n** ES/EN.
- **CI:** el job estático corre los runners unitarios de todos los plugins; el job de integración
  ejecuta los selftests de los plugins Fase 2 (cambio genérico y guardado).

### Hardening (pasada de consistencia/concurrencia)
- **`DefinitionBuilder::createVersion` fail-closed y transaccional:** validación previa
  (`DefinitionSpecValidator`, pura) → rechazo sin escrituras; definición creada **inactiva** +
  hijos verificados; **switch atómico** de versión activa al final; **rollback** ante cualquier
  fallo (la versión anterior sigue activa); **retry** ante colisión de versión (UNIQUE code,version);
  nunca quedan cero versiones activas.
- **Aprobación recovery-safe y atómica:** voto + quórum + cambio de estado en **una** transacción
  con `SELECT ... FOR UPDATE` de la instancia (serializa por instancia; conteo sin carreras). Un
  reintento con voto ya presente y quórum alcanzado **avanza** (no queda bloqueado como DUPLICATE).
- **Multi-entidad de aprobadores:** `EntityAccess` excluye del conjunto efectivo (y del
  denominador del quórum) a usuarios que no pueden actuar en la entidad de la instancia
  (group/profile/user + delegación).
- **`startInstance` fail-closed:** valida definición activa/versionada, `itemtype_target`,
  existencia del objeto, coherencia de entidad y ACL; creación + evento inicial transaccionales.
- **Tests añadidos:** validador de spec (unit); fallo creando estado→versión anterior activa;
  transición a estado inexistente→rechazo sin escrituras; colisión de versión→consistente;
  fallo durante el avance→recuperable; retry de voto atascado→avanza; multi-entidad del quórum;
  validaciones de `startInstance`.

### Notes
- No hay lógica de Compras en el motor (config-first). `entity_manager`, plantillas de correo
  nativo y UI de bandeja quedan como follow-up (ver README).
