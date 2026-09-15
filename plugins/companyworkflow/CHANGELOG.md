# Changelog — Company Workflow (`companyworkflow`)

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y versionado [SemVer](https://semver.org/lang/es/).

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
