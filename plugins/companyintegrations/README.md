# Company Integrations (`companyintegrations`)

Plugin propio de la **Plataforma GLPI Modular**.

- **Estrategia (matriz):** Integrate
- **Propósito (SI-1, ADR-0015):** integración **READ-ONLY** con **Snipe-IT** por API — cliente HTTP
  resiliente, `asset_bridge`, mapeos, reconciliación con detección de conflictos y gateway estable.
- **GLPI soportado:** `>=11.0` y `<12.0` (probado en 11.0.8).
- **Estado:** Fase 2 — **SI-1** (read-only).

## Regla 0 y licencia
No modifica el core de GLPI **ni el de Snipe-IT**. **Snipe-IT es AGPL-3.0 → integración SÓLO por
API**, sin copiar código, sin DB-a-DB. Ver `../../CLAUDE.md`, `../../docs/adr/ADR-0015-snipeit-integration.md`
y `../../docs/security/snipeit-integration-security.md`.

## Alcance SI-1 (lo que hace)
- **`SnipeItClient`** (read-only, sólo GET): `base_url` configurable, **token desde variable de
  entorno/secret** (`COMPANYINTEGRATIONS_SNIPEIT_TOKEN`, nunca en Git/BD), **timeout**, **retry con
  backoff**, manejo de **401/403/404/409/429/5xx**, **circuit breaker**, **correlation_id**, **logs
  saneados** (el token nunca aparece).
- **Tablas propias** (migración reversible, prefijo `glpi_plugin_companyintegrations_`):
  `asset_bridge` (mapeo 1:1 por ID), `asset_tag_aliases` (identidad estable del QR),
  `map_companies` (compañía↔entidad, aprobado), `map_users` (identidad = GLPI/IdP, aprobado),
  `recon` (auditoría de reconciliación, append-only).
- **Reconciliación** conservadora que clasifica: `MATCHED · SNIPE_ONLY · GLPI_ONLY · AMBIGUOUS ·
  COMPANY_UNMAPPED · SERIAL_CONFLICT · ERROR`. **Nunca auto-corrige un conflicto**; sólo crea el
  puente cuando la evidencia es **inequívoca** (compañía mapeada + exactamente un candidato por serial).
- **Gateway** `GET /asset/{asset_tag}` (AUTHENTICATED): resuelve tag **actual o histórico** →
  puente → activo GLPI, y aplica **ACL nativa** (multi-entidad). *El asset tag identifica; GLPI autoriza.*
- **Chequeo de configuración de etiquetas** (`plain_asset_tag` + prefijo del gateway), read-only.

## Alcance SI-1 (lo que NO hace)
No crea/edita activos en Snipe · no checkout/checkin · no aceptación · no sincroniza
accesorios/consumibles/licencias · no crea/modifica activos core de GLPI · no recepción desde
Compras · no ejecuta la saga SI-4 · no toca la DB de Snipe · no copia código AGPL.
**Read-only = sin escrituras en Snipe ni en activos core de GLPI**; sí persiste puente,
reconciliación, conflictos y timestamps en **tablas propias**.

## Estructura
| Ruta | Rol |
|------|-----|
| `setup.php` / `hook.php` | metadatos, init, migraciones reversibles (5 tablas), ACL, config |
| `src/Model/` | `AssetBridge · MapCompany · MapUser · AssetTagAlias · ReconResult` |
| `src/Client/` | `HttpTransport · HttpResponse · CurlTransport · ArrayTransport · SnipeClientConfig · SnipeException · SnipeItClient` |
| `src/Service/` | `ErrorClassifier · BackoffPolicy · CircuitBreaker · LogSanitizer · CorrelationId · ReconciliationClassifier · LabelConfigChecker · PluginConfig · SnipeConfigFactory · Reconciler · AssetResolver` |
| `src/Controller/GatewayController.php` | gateway QR (GET, AUTHENTICATED) |
| `src/Command/SelftestCommand.php` | `plugins:companyintegrations:selftest` (integración + E2E) |
| `locales/` | i18n ES/EN |
| `tests/unit/run.php` | unit + **contract tests** (sin GLPI) |

## Seguridad
Token fuera de Git/logs (env/secret) · **TLS siempre verificado** (`CurlTransport`) · cuenta de
servicio Snipe con **rol mínimo real** (RBAC por usuario; sin scopes por endpoint) · headers/errores
saneados. Secret scan del repo en verde.

## Tests
- **Unit + contract (sin GLPI):** `php plugins/companyintegrations/tests/unit/run.php` — clasificación
  de errores, backoff, circuit breaker, saneado de logs, clasificación de reconciliación, etiquetas, y
  el **contrato del cliente** (auth/429/timeout/5xx/circuit/found/not-found/**token nunca en logs**).
- **Integración + E2E (en GLPI):** `php bin/console plugins:companyintegrations:selftest` — tablas,
  ACL/multi-entidad (gateway), reconciliación con puente persistido, serial conflict, gateway con
  alias histórico, y garantía **read-only** (sólo GET + activo GLPI intacto).

## Limitaciones / deuda técnica (SI-1)
- **Sin Snipe-IT real en CI:** la integración se valida con **tests de contrato** (transporte fake) —
  levantar Snipe (Laravel + su DB) volvería el CI excesivamente costoso (permitido por ADR-0015).
  `CurlTransport` es el transporte productivo; un **smoke real** contra un Snipe sandbox se hará al
  configurar `snipe_base_url` + token (fuera de CI).
- `GLPI_ONLY` (pasada inversa GLPI→Snipe) queda como extensión; SI-1 cubre la dirección Snipe→GLPI.
- La ficha segura `companyqr` como destino del gateway es el punto de integración (SI-1 entrega la
  referencia resuelta y aplica la ACL).
