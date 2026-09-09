# ADR-0010: Observabilidad, auditoría y métricas

- **Estado:** Aceptado
- **Fecha:** 2026-09-09
- **Decisores:** Plataforma, seguridad
- **Módulo/área:** Transversal (`infra/monitoring`, todos los módulos)

## Contexto
Todo proceso importante debe producir **métricas y eventos de auditoría**. Las
integraciones fallidas deben ser **visibles y reintentables**. GLPI incorpora
historial nativo y —en 11— **dashboards nativos** reutilizables.

## Decisión
- **Auditoría append-oriented** para acciones sensibles (creación, modificación,
  aprobación, rechazo, asignación, exportación, acciones de integraciones/IA):
  un administrador funcional **no** debe poder borrar evidencia silenciosamente.
- **Logs estructurados** sin secretos; correlación con `correlation_id`.
- **Métricas obligatorias por área** (soporte, compras, activos, conocimiento, IA,
  integraciones) según `docs/functional/metrics.md`.
- **Dashboards:** reutilizar los **dashboards nativos** de GLPI 11 y complementar
  con `companydashboard` sólo para KPIs no expresables nativamente.
- **Salud de integraciones/servicios:** endpoint `/api/v1/health` y tablero de
  estado en `integration-hub`.
- La **pila concreta** de observabilidad se elige en un ADR de implementación.

## Alternativas consideradas
- **Sólo logs planos** — descartado: no permite trazabilidad ni KPIs.
- **Métricas ad-hoc por módulo sin estándar** — descartado: inconsistencia.
- **Estándar transversal de auditoría/métricas/logs (elegida)**.

## Consecuencias
- (+) Trazabilidad y cumplimiento; problemas detectables y reintentables.
- (+) Reutiliza dashboards nativos, menos código propio.
- (−) Exige instrumentación consistente en cada módulo (*Definition of Done*).

## Cumplimiento de la Regla 0
Historial/dashboards nativos + instrumentación en plugins/servicios. Sin edición de core.
