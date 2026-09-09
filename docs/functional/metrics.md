# Funcional — Métricas / KPIs obligatorios

Todos los módulos exponen métricas. Se reutilizan los **dashboards nativos** de
GLPI 11 y se complementan con `companydashboard` sólo para KPIs no nativos
(`../adr/ADR-0010-observability-audit-metrics.md`).

| Área | KPIs |
|------|------|
| **Soporte** | tickets creados/resueltos, backlog, primera respuesta, tiempo de resolución, cumplimiento SLA, reaperturas, satisfacción, tasa de autoservicio |
| **Compras** | solicitudes, monto (PYG), tiempo por etapa, tiempo total, rechazos, devoluciones, gasto por área/categoría/proveedor |
| **Activos** | altas/bajas, asignados, sin responsable, garantía, antigüedad, incidentes por activo/modelo |
| **Conocimiento** | artículos consultados, útiles/no útiles, resolución sin ticket, artículos obsoletos |
| **IA** | consultas, resolución sin ticket, escalamiento, aceptación de respuesta, costo/latencia, fuentes usadas, errores |
| **Integraciones** | éxito/error, latencia, reintentos, mensajes pendientes, webhooks fallidos |

## Reglas
- KPIs con moneda en **PYG** y fechas en `America/Asuncion`.
- Sin secretos en las métricas/logs; correlación con `correlation_id`.
- Cada KPI define su fuente (nativo GLPI vs. plugin/servicio) al implementarse.
