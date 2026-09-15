# Company Workflow (`companyworkflow`)

Plugin propio de la **Plataforma GLPI Modular**.

- **Estrategia (matriz):** Build/Extend
- **Propósito:** **Motor genérico y reusable** de máquina de estados (definiciones versionadas,
  estados/transiciones/condiciones configurables, quórum, delegación, SLA, escalamiento,
  notificaciones, auditoría append-only, multi-entidad, ACL). **No conoce el dominio de Compras.**
- **GLPI soportado:** `>=11.0` y `<12.0` (probado en 11.0.8 — ver `../../docs/architecture/glpi-version-compatibility.md`)
- **Estado:** Fase 2 — motor implementado (SI aplica: ADR-0012).

## Regla 0
Este plugin **no modifica el core de GLPI**. Solo usa hooks/API oficiales.
Ver `../../CLAUDE.md`, `../../docs/adr/ADR-0002-glpi-core-immutable.md` y
`../../docs/adr/ADR-0012-companyworkflow.md`.

## Qué implementa
- **Definiciones versionadas** (`code`,`version`): una edición administrativa crea una versión
  nueva; **las instancias en ejecución conservan su versión** (no cambian silenciosamente).
- **Estados** configurables (initial/intermediate/final, editable, `sla_hours`).
- **Transiciones** con **condiciones declarativas** (`ConditionEvaluator`, sin `eval`),
  **comentario obligatorio** configurable y **derecho requerido** por transición.
- **Etapas con quórum** (`count`/`percent`), **aprobadores por group/profile/user** (nunca por
  nombre de persona) y **delegación** temporal por rango/alcance.
- **Acciones:** submit, approve, reject, **return** (devolución → invalida votos previos), cancel
  (y las que aporte la definición del dominio).
- **Transición ATÓMICA y fail-closed:** `ACL → entidad → versión (optimista) → comentario →
  condición → [quórum] → cambio de estado (UPDATE condicionado por lock_version) → auditoría →
  evento`. Evita **doble aprobación** por concurrencia/replay (UNIQUE del voto + `lock_version`).
- **Multi-entidad ESTRICTA:** un aprobador de la entidad A no actúa sobre instancias de la B.
- **SLA + escalamiento** vía **CronTask** nativo (`Instance::cronEscalation`); nunca aprueba solo.
- **Auditoría append-only** (`..._history`): una fila por evento; jamás se borra.
- **API de dominio** (`Api\WorkflowApi`) + hook `companyworkflow:transitioned` para que
  `companypurchasing` (y futuros) definan su workflow y reaccionen.

## Estructura
| Ruta | Rol |
|------|-----|
| `setup.php` / `hook.php` | metadatos, init, migraciones reversibles (8 tablas), ACL, cron |
| `src/Model/` | `WorkflowDef · StateDef · Transition · Step · Instance · Assignment · Delegation · HistoryEvent` |
| `src/Service/` | `Engine · ConditionEvaluator · QuorumCalculator · TransitionResolver · DelegationResolver · ApproverResolver · SlaService · AuditBridge · NotificationBridge · DefinitionBuilder · PluginConfig · TransitionResult` |
| `src/Api/WorkflowApi.php` | fachada para plugins de dominio |
| `src/Controller/` | `WorkflowActionController` (POST, AUTHENTICATED, CSRF nativo) |
| `src/Command/SelftestCommand.php` | `plugins:companyworkflow:selftest` (integración + E2E) |
| `locales/` | i18n ES/EN |
| `tests/unit/run.php` | tests unitarios puros (sin GLPI) |

## Tests
- **Unit (puro):** `php plugins/companyworkflow/tests/unit/run.php` — condiciones, quórum,
  resolución de transición, ventana de delegación.
- **Integración + E2E (en GLPI):** `php bin/console plugins:companyworkflow:selftest` — persistencia,
  ACL, multi-entidad, quórum, delegación, versión, SLA, y el ciclo completo + negativos.

## Limitaciones / deuda técnica (v1)
- `approver_kind = entity_manager` aún no resuelve aprobadores (devuelve conjunto vacío,
  fail-closed); v1 cubre `group`/`profile`/`user`. Seguimiento en fase posterior.
- **Notificaciones:** se emite el evento de dominio y se calculan destinatarios; el cableado de
  **plantillas/targets de correo nativo** se difiere a un follow-up (no se construye motor propio).
- **UI (bandeja/timeline/action_bar):** la superficie HTTP expone la acción por API JSON; las
  vistas Twig de bandeja quedan como follow-up (el E2E de v1 valida el ciclo por la API + la
  declaración de rutas por reflexión).
