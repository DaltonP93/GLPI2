# Auditoría y logs

## Auditoría (append-oriented)
Registrar acciones sensibles de forma **no borrable silenciosamente**:
creación, modificación, aprobación, rechazo, asignación, exportación y acciones de
integraciones/IA. Un administrador funcional **no** debe poder eliminar evidencia.

Cada evento de auditoría incluye, según aplique: actor, rol, fecha/hora,
entidad/objeto, acción, estado anterior/nuevo, canal/origen, `correlation_id`.

## Logs estructurados
- Formato estructurado (clave/valor o JSON), **sin secretos ni datos sensibles**.
- `correlation_id` en cada request/evento para trazabilidad distribuida.
- Niveles de log coherentes; errores accionables.

## Fuentes
- GLPI: historial nativo por ítem + `files/_log/*`.
- Plugins/servicios: logging propio consistente.
- Integraciones: estado, latencia, reintentos y fallos en `integration-hub`.

## Retención y monitoreo
- Definir retención por tipo de evento (a fijar en ADR de observabilidad).
- Alertas ante integraciones fallidas (visibles y **reintentables**).

## Referencia
`../adr/ADR-0010-observability-audit-metrics.md`, `../../infra/monitoring/README.md`.
