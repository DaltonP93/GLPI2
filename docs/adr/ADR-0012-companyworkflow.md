# ADR-0012: `companyworkflow` — motor de workflow configurable y reusable

- **Estado:** Aceptado (diseño); implementación pendiente de aprobación humana.
- **Fecha:** 2026-09-14
- **Decisores:** Producto, seguridad, plataforma
- **Módulo/área:** `plugins/companyworkflow` (Fase 2)
- **Complementa:** ADR-0001, ADR-0002 (core inmutable), ADR-0003, ADR-0006 (workflow de compras), ADR-0010 (observabilidad)

## Contexto
La plataforma necesita **aprobaciones multinivel configurables** para Compras y, más adelante,
para otros procesos (bajas de activos, accesos, gastos, RRHH…). GLPI 11 ofrece validación
multi-paso (`CommonITILValidation` + `ValidationStep`) **sólo para objetos ITIL** (Ticket/Change);
no es un motor genérico para objetos de negocio arbitrarios, ni cubre **devolución para
corrección**, **delegación**, **SLA por etapa** ni **escalamiento** de forma reutilizable.

## Decisión
Construir **`companyworkflow`**: un **motor de máquina de estados configurable** que cualquier
plugin puede acoplar a su objeto de dominio (por `itemtype`/`items_id`), con:

1. **Definiciones de workflow** versionadas (una definición → muchas instancias).
2. **Estados configurables** (inicial, intermedios, finales) — no hardcodeados.
3. **Transiciones** con **condiciones** (monto/categoría/campo) evaluadas por un evaluador
   acotado y declarativo (sin `eval`).
4. **Aprobadores por rol/grupo/departamento** (nunca por nombre de persona).
5. **Niveles/etapas** con **quórum** (% o N aprobadores) — inspirado en `ValidationStep`.
6. **Acciones:** aprobar, rechazar, **devolver para corrección**, cancelar, **delegar**.
7. **Comentario obligatorio configurable** por transición.
8. **SLA/tiempo por etapa** + **escalamiento** automático (tarea programada).
9. **Notificaciones** vía motor **nativo** de GLPI (plantillas/targets).
10. **Auditoría completa** append-only (ver ADR-0010) de cada transición.

`companyworkflow` **no conoce** el dominio de compras; expone una API PHP y hooks para que
`companypurchasing` (y futuros) definan su workflow y disparen transiciones.

## Alternativas consideradas
- **Reutilizar `CommonITILValidation`/`ValidationStep` nativos** → **rechazado como motor
  genérico**: están **atados a objetos ITIL** (Ticket/Change). Modelar una compra como Ticket
  fuerza el modelo ITIL, complica ítems/cotizaciones/estados propios y mezcla incidencias con
  compras. Se documenta como opción y se **toma prestado su modelo** (pasos + quórum) para el
  diseño propio. (Un despliegue podría, opcionalmente, además abrir validaciones ITIL nativas,
  pero no es el mecanismo principal.)
- **Motor de reglas nativo (`Rule`)** → insuficiente: enruta/asigna pero no modela estados,
  quórum, SLA por etapa ni devolución/delegación.
- **Workflow embebido dentro de `companypurchasing`** → **rechazado** por el propio requisito:
  el motor debe ser **reusable** y no duplicarse por dominio.
- **Motor externo (BPMN/Camunda)** → sobredimensionado para v1 y añade dependencia/operación;
  se deja como posible *Integrate* futuro tras un ADR.

## Consecuencias
- (+) Motor reutilizable → Compras, y luego otros procesos, sin reescribir aprobaciones.
- (+) Config-first: estados/aprobadores/SLA parametrizables, sin hardcode (cumple CLAUDE.md).
- (+) Reutiliza notificaciones/grupos/entidades/Log nativos.
- (−) Superficie propia mayor; exige buen diseño de API y pruebas (unit/integration/E2E).
- (−) Debe respetar multi-entidad estrictamente (un aprobador de entidad A no ve/actúa sobre
  instancias de entidad B salvo permiso explícito).

## Cumplimiento de la Regla 0
Sólo plugin + hooks/API/notificaciones/webhooks soportados. **Sin modificar el core.**

## Compatibilidad
`requirements.glpi` min `11.0`, max `12.0` (excluyente). Ver `../architecture/glpi-version-compatibility.md`.
