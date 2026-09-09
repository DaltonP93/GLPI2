# Integration Hub (`integration-hub`)

Servicio **desacoplado** de la Plataforma GLPI Modular.

- **Propósito:** Hub de integraciones: normaliza webhooks entrantes/salientes, mapeo, idempotencia, correlation_id, reintentos y estado de integraciones.
- **Estilo:** API-first (`/api/v1`), OpenAPI documentado, OAuth2/tokens de mínimo privilegio.
- **Estado:** Fase 0 — esqueleto (sin lógica de negocio).

## Límites (boundaries)
- **No** accede a la base de datos de GLPI directamente. Solo consume la
  **API REST v2** de GLPI y/o recibe **webhooks** oficiales.
- **No** modifica el core de GLPI ni vive dentro de él.
- Escrituras externas **idempotentes** y trazadas con `correlation_id`.
- Secretos por variables de entorno / secret manager (nunca en Git).

## Estructura
| Ruta | Rol |
|------|-----|
| `src/` | Código del servicio (stack a definir por ADR) |
| `openapi/openapi.yaml` | Contrato OpenAPI del servicio |
| `tests/` | Pruebas del servicio |
| `.env.example` | Variables de entorno de ejemplo (sin secretos reales) |
| `Dockerfile` | Empaquetado del servicio (placeholder en Fase 0) |

## Antes de desarrollar
Definir stack y contrato en un ADR bajo `../../docs/adr/` y completar
`openapi/openapi.yaml`. Ver `../../docs/api/api-standards.md`.
