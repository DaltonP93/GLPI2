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

### Notes
- No hay lógica de Compras en el motor (config-first). `entity_manager`, plantillas de correo
  nativo y UI de bandeja quedan como follow-up (ver README).
