# Monitoreo y observabilidad (Fase 0 — lineamientos)

Toda acción importante debe producir **logs estructurados, métricas y auditoría**
(ver `CLAUDE.md` y `docs/security/audit-logging.md`).

## Fuentes de señales
| Señal | Origen | Destino sugerido |
|-------|--------|------------------|
| Logs de aplicación | GLPI `files/_log/*.log`, Apache | Agregador de logs (stack a decidir por ADR) |
| Logs de plugins | Nuestros plugins (structured logging) | Igual que arriba |
| Métricas de negocio | Plugins/servicios exponen KPIs | Ver `docs/architecture/glpi11-capability-matrix.md` |
| Salud de integraciones | `integration-hub` (estado, latencia, reintentos) | Tablero de integraciones |
| Salud de servicios | Endpoint `/api/v1/health` de cada servicio | Health checks / uptime |

## Principios
- **Nunca** registrar secretos ni datos sensibles en logs.
- Correlación distribuida con `correlation_id` en cada request/webhook.
- Los KPIs obligatorios están en `docs/functional/metrics.md`.

> La pila concreta (Prometheus/Grafana/Loki u otra) se definirá en un ADR de
> observabilidad antes de implementarla. Fase 0 sólo fija los lineamientos.
