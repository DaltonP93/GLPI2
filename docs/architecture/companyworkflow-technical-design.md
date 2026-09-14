# Diseño técnico — `companyworkflow` (motor reusable)

Complementa `../adr/ADR-0012-companyworkflow.md`. **Diseño, sin implementación.** Todo vía
plugin + APIs/hooks/notificaciones **soportados**. **Sin tocar el core** (Regla 0).

## Estructura del plugin (PSR-4 `GlpiPlugin\Companyworkflow\`)
```
plugins/companyworkflow/
  setup.php  hook.php
  src/
    Model/       WorkflowDef · Statedef · Transition · Instance · Step · Assignment · Delegation · HistoryEvent
    Service/     Engine · ConditionEvaluator · ApproverResolver · SlaService · EscalationService · NotificationBridge · AuditBridge
    Controller/  (acciones de transición: approve/reject/return/cancel/delegate) — thin, AUTHENTICATED, CSRF nativo
    Api/         WorkflowApi (fachada PHP para plugins de dominio)
  templates/     bandeja.html.twig · timeline.html.twig · action_bar.html.twig
  locales/       es_ES · en_GB
  tests/         unit/ · integration/ (selftest fail-closed) · e2e/
```
- **Acoplamiento por referencia:** una instancia apunta al objeto de dominio con
  `itemtype`/`items_id` (como el core). El motor **no** conoce Compras.
- **Adaptadores** encapsulan el core: `NotificationBridge` (Notificaciones nativas),
  `AuditBridge` (`Log` + tabla propia), `ApproverResolver` (Group/Profile/Entity).

## Modelo de datos (tablas propias, migración reversible)
Prefijo `glpi_plugin_companyworkflow_`. Sin FK por SQL directo a tablas core.

| Tabla | Rol | Campos clave |
|---|---|---|
| `..._defs` | Definición de workflow (versionada) | `id, name, itemtype_target, version, is_active, entities_id, is_recursive` |
| `..._statedefs` | Estados de una definición | `id, workflowdefs_id, code, label, kind(initial/intermediate/final), is_editable, sla_hours` |
| `..._transitions` | Transiciones permitidas | `id, workflowdefs_id, from_statedefs_id, to_statedefs_id, action(approve/reject/return/cancel/submit/receive/...), requires_comment(bool), condition_json, required_right` |
| `..._steps` | Etapas/quórum de una transición de aprobación | `id, transitions_id, level, quorum_type(percent/count), quorum_value, approver_kind(group/profile/entity_manager), approver_ref` |
| `..._instances` | Instancia viva sobre un objeto | `id, workflowdefs_id, itemtype, items_id, entities_id, is_recursive, current_statedefs_id, status(open/closed/cancelled), date_creation, date_mod` |
| `..._assignments` | Aprobaciones pendientes/resueltas por instancia+etapa | `id, instances_id, steps_id, level, users_id(null hasta actuar), group_ref, decision(pending/approved/rejected/returned), comment, date, delegated_from` |
| `..._delegations` | Delegación temporal de aprobación | `id, users_id_from, users_id_to, scope(workflowdefs_id/entities_id), date_start, date_end, reason` |
| `..._history` | **Auditoría append-only** (una fila por evento) | `id, instances_id, event, from_code, to_code, actor_users_id, is_system, comment, date, meta_json` |

- **Sin borrado de historial** (append-only). La invalidación de aprobaciones (por edición del
  contenido) **no borra**: agrega eventos `approval_invalidated` y reabre etapas.

## Máquina de estados (definición por defecto para Compras)
Los **estados concretos los define la definición de workflow** (parametrizable). La definición
por defecto de `companypurchasing` (ver ADR-0013) es:

```
BORRADOR
   │ submit
   ▼
ENVIADA ──────────────► CANCELADA        (cancel: solicitante/admin, en estados no finales)
   │ route (auto)
   ▼
PENDIENTE_JEFE_AREA ─(reject)─► RECHAZADA
   │ approve (quórum jefe)          ▲
   │                                │ (reject en cualquier etapa de aprobación)
   │ return ─► DEVUELTA ─(resubmit)─┘  (DEVUELTA vuelve a BORRADOR/ENVIADA para corrección)
   ▼
EN_COMPRAS ─(return/reject)─► DEVUELTA/RECHAZADA
   │ approve (Compras revisa/cotiza)
   ▼
PENDIENTE_GERENCIA_FINANCIERA ─(reject)─► RECHAZADA / (return)─► DEVUELTA
   │ approve (quórum gerencia; condición por monto)
   ▼
APROBADA
   │ order
   ▼
EN_COMPRA
   │ receive  (→ integración inventario + companyqr)
   ▼
RECIBIDA
   │ deliver
   ▼
ENTREGADA
   │ close
   ▼
CERRADA   (final)
```
Estados **finales**: `RECHAZADA`, `CERRADA`, `CANCELADA`. `DEVUELTA` es intermedio (permite
edición: `is_editable=1`).

## Matriz de transiciones (workflow de compras por defecto)
| Desde \ Acción | submit | approve | reject | return | cancel | order | receive | deliver | close |
|---|---|---|---|---|---|---|---|---|---|
| BORRADOR | → ENVIADA | — | — | — | → CANCELADA | — | — | — | — |
| ENVIADA | — | → PENDIENTE_JEFE_AREA (auto-route) | — | — | → CANCELADA | — | — | — | — |
| PENDIENTE_JEFE_AREA | — | → EN_COMPRAS | → RECHAZADA | → DEVUELTA | → CANCELADA | — | — | — | — |
| EN_COMPRAS | — | → PENDIENTE_GERENCIA_FINANCIERA | → RECHAZADA | → DEVUELTA | → CANCELADA | — | — | — | — |
| PENDIENTE_GERENCIA_FINANCIERA | — | → APROBADA | → RECHAZADA | → DEVUELTA | → CANCELADA | — | — | — | — |
| APROBADA | — | — | — | — | → CANCELADA | → EN_COMPRA | — | — | — |
| EN_COMPRA | — | — | — | — | → CANCELADA | — | → RECIBIDA | — | — |
| RECIBIDA | — | — | — | — | — | — | — | → ENTREGADA | — |
| ENTREGADA | — | — | — | — | — | — | — | — | → CERRADA |
| DEVUELTA | → ENVIADA (resubmit) | — | — | — | → CANCELADA | — | — | — | — |
| RECHAZADA / CERRADA / CANCELADA | — (finales; sin transiciones salientes) |

Cada celda `→X` sólo procede si: (a) la transición existe en `..._transitions`; (b) el actor
tiene el **derecho** y **ACL de entidad**; (c) se cumple la **condición** (`condition_json`,
p. ej. monto ≥ umbral exige gerencia); (d) se alcanza el **quórum** de la etapa; (e) hay
**comentario** si `requires_comment`.

## Motor (`Engine`) — pasos de una transición
1. Cargar instancia + definición; validar que la acción es una transición válida desde el
   estado actual.
2. **ACL:** `Session::haveRight(required_right)` **y** acceso a la **entidad** de la instancia
   (multi-entidad estricto). Resolver aprobador efectivo considerando **delegaciones** vigentes.
3. Evaluar **condición** (`ConditionEvaluator`, declarativo: operadores `eq/neq/gt/gte/lt/in`
   sobre campos del objeto de dominio; **sin `eval`**).
4. Registrar la decisión en `..._assignments`; recomputar **quórum** de la etapa.
5. Si el quórum se cumple → cambiar `current_statedefs_id`; escribir `..._history`
   (append-only); disparar **hooks** (`companysignature` registra evidencia+hash; dominio
   reacciona, p. ej. `receive` → inventario).
6. **Notificar** (motor nativo) a los siguientes aprobadores/solicitante.
7. Programar/actualizar **SLA** de la nueva etapa.

## SLA, escalamiento y delegación
- **SLA por etapa:** `sla_hours` en `..._statedefs`; una **tarea programada** (CronTask nativo)
  marca vencimientos y ejecuta **escalamiento** (notificar al superior/rol configurado; nunca
  aprueba sola).
- **Delegación:** `..._delegations` permite que A delegue en B por rango de fechas/alcance; el
  `ApproverResolver` incluye al delegado como aprobador válido; queda en auditoría
  (`delegated_from`).
- **Devolución para corrección:** `return` lleva a `DEVUELTA` (editable); al reenviar, las
  etapas de aprobación se rehacen según política de invalidación (ver `companysignature`).

## API para plugins de dominio (`WorkflowApi`)
- `startInstance(WorkflowDef $def, string $itemtype, int $items_id): Instance`
- `availableActions(Instance $i, ?int $users_id): array` — acciones que el actor puede ejecutar.
- `transition(Instance $i, string $action, array $ctx): Result` — fail-closed (ACL/condición/quórum).
- Hooks emitidos: `companyworkflow:transitioned` (con from/to/actor/instancia) para que
  `companypurchasing`, `companysignature` y webhooks reaccionen.

## Plan de tests (a implementar tras aprobación)
- **Unit (puro):** validez de transición desde matriz; `ConditionEvaluator` (operadores);
  cálculo de quórum (percent/count); resolución de aprobador con delegación.
- **Integración (selftest fail-closed):** 🔒 multi-entidad (aprobador de entidad A no puede
  actuar sobre instancia de entidad B); quórum incompleto no avanza; `return`→editable→reenvío;
  cancelación desde estados no finales; historial append-only (no se borra).
- **E2E HTTP:** login → bandeja → approve/reject/return con CSRF → estado y auditoría correctos.
