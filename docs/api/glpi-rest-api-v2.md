# Uso de la API REST de GLPI 11

## Preferir v2
GLPI 11 incorpora **API REST v2**. Se prefiere sobre la v1 (`apirest.php`) para
capacidades soportadas. La v1 permanece por compatibilidad.

## Principios de consumo
- **Tokens de mínimo privilegio** por integración (App Token / credenciales de
  usuario técnico con el perfil justo).
- Respetar **ACL de GLPI**: la API aplica los permisos del usuario; los servicios
  (p. ej. IA) **no** deben sortearlos.
- **No** acceder a la base de datos directamente cuando la API cubre el caso
  (`../adr/ADR-0004-api-first-integrations.md`, `CLAUDE.md`).
- Manejar paginación, *rate limits* y errores de forma uniforme.
- Propagar `correlation_id` para trazar llamadas hacia GLPI.

## Endpoints (a fijar contra la versión objetivo)
Los paths exactos de v2 se documentan al implementar cada integración, contra la
documentación oficial de **11.0.8**:
- Referencia: https://help.glpi-project.org/documentation/modules/configuration/general/api/restful-api-v2

## Eventos salientes
Para "GLPI avisa a terceros" usar **webhooks nativos** en lugar de *polling*.
