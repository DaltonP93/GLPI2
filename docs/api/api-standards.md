# Estándares de APIs propias (`/api/v1`)

Aplican a los servicios (`services/*`) y a endpoints propios de plugins.

## Versionado y contrato
- Prefijo **`/api/v1`**; cambios incompatibles → nueva versión mayor.
- **OpenAPI** obligatorio por servicio (`services/*/openapi/openapi.yaml`).

## Autenticación y autorización
- **OAuth2 / tokens** con **mínimo privilegio**; sin credenciales compartidas.
- Autorización por RBAC (perfil/entidad/departamento/rol de proceso).
- Todo endpoint: authn + authz + **validación de entrada** + rate limit cuando
  aplique + **logging** + manejo uniforme de errores.

## Fiabilidad
- **Idempotencia** en escrituras externas (clave de idempotencia por operación).
- **`correlation_id`** propagado en cada request/respuesta/evento
  (cabecera `X-Correlation-Id`).
- Reintentos con backoff; operaciones seguras ante reejecución.

## Errores (formato uniforme)
```json
{
  "error": {
    "code": "string",
    "message": "descripción legible",
    "correlation_id": "uuid",
    "details": {}
  }
}
```

## Webhooks
- Preferir **webhooks nativos** de GLPI 11 para eventos del core.
- Firmar/verificar payloads; registrar entrega, estado y reintentos en
  `integration-hub`.

## Seguridad de datos
- Nunca exponer secretos en respuestas ni logs.
- Validar tipo/tamaño/MIME de archivos.
