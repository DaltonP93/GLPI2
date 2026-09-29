# Company Integrations (`companyintegrations`)

Plugin propio de la **Plataforma GLPI Modular**.

- **Estrategia (matriz):** Integrate
- **Propósito (SI-1, ADR-0015):** integración **READ-ONLY** con **Snipe-IT** por API — cliente HTTP
  resiliente, `asset_bridge`, mapeos, reconciliación con detección de conflictos y gateway estable.
- **GLPI soportado:** `>=11.0` y `<12.0` (probado en 11.0.8).
- **Estado:** Fase 2 — **SI-1** (read-only) + **SI4-1** (primer incremento de SI-4, ADR-0020; worker
  **deshabilitado por defecto**).

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

## Alcance SI4-1 (ADR-0020) — compra recibida → activo en Snipe-IT
Primer incremento de SI-4. **Todavía NO** crea/modifica activos core de GLPI, ni crea Infocom, ni invoca companyqr,
ni genera etiquetas, ni implementa la entrega (P2D-4).

**Ownership:** `companypurchasing` es dueño del hecho de negocio recibido (`receipt_unit_uuid`, `unit_cost`, outbox).
SI-4 es dueño de la saga de integración, de la escritura por API en Snipe y del mapping/bridge.

- **Consumo por lease, sólo por API:** SI-4 usa exclusivamente `PurchasingIntegrationApi` (`claimPending`, `getHandoff`,
  `acknowledgeProcessed`, `markRetry`, `markError`) a través del puerto `HandoffSource`. No hay SQL contra tablas de
  Compras.
- **Identidad remota determinista:** `asset_tag = <si4_asset_tag_prefix><32 hex del receipt_unit_uuid>`.
  - Cada intento **busca primero** (`bytag?deleted=true`).
  - El POST es de un solo disparo y nunca se reintenta a ciegas.
  - Un resultado incierto (timeout/5xx) pasa a `RETRY` con enfriamiento y el próximo intento vuelve a buscar primero.
  - Resultado: *crash después del POST y antes de persistir* ⇒ el retry **vincula**, no duplica.
- **Saga durable** (`si4_sagas`, bitácora `si4_saga_log`), una por `receipt_unit_uuid`:
  `PENDING → SNIPE_CREATING → SNIPE_CREATED`, más `BLOCKED_CONFIG` y `MANUAL_REVIEW`.
  - Fencing: época monótona del lease (`attempts` del claim) + `sha256(token)` + `lease_until >= NOW()` (reloj de la BD).
  - Antes de un POST se exige un lease restante ≥ presupuesto de escritura.
- **Sin ack todavía:** en `SNIPE_CREATED` la unidad queda **estacionada**. `acknowledgeProcessed()` se llamará sólo
  cuando SI-4 completo termine; mientras tanto la fila del outbox nunca llega a `DONE`.
- **Mapeos validados, sin IDs literales:**
  - compañía = `map_companies` aprobado para la entidad (exactamente uno);
  - modelo = `map_models` (nueva) por `category` exacta, aprobado;
  - estado = `si4_snipe_status_id`, verificado contra Snipe en el preflight.
- **Clasificación:**

  | Situación | Resultado |
  |---|---|
  | mapeo ausente o rechazado | `BLOCKED_CONFIG` + `markRetry` tardío |
  | 401/403 | fail-closed (preflight **antes** de reclamar; aborta la corrida) |
  | 429/5xx/timeout | `markRetry` con backoff |
  | 409 o validación de tag | buscar y vincular |
  | duplicado, borrado, compañía/serial divergente | `MANUAL_REVIEW` + `markError` |

- **Worker:** `php bin/console plugins:companyintegrations:si4-run --user=<id técnico> [--profile=<id>]`.
  - Abre una sesión GLPI **real** del usuario técnico y exige `RIGHT_SI4` (bit 16 de este plugin) y
    `RIGHT_INTEGRATION` (bit 1024 de Compras).
  - Token sólo por `COMPANYINTEGRATIONS_SNIPEIT_TOKEN`. La cuenta de servicio de Snipe sólo necesita `assets.view`,
    `assets.create` y `statuslabels.view` (sin superuser).
  - **Sin Acción automática:** no programar en producción hasta completar SI-4.

### Configuración SI-4 (contexto `plugin:companyintegrations`)
| Clave | Por defecto | Uso |
|---|---|---|
| `si4_enabled` | `0` | habilita el worker |
| `si4_asset_tag_prefix` | `GP2-` | prefijo del tag determinista (`^[A-Z0-9][A-Z0-9-]{0,15}$`) |
| `si4_snipe_status_id` | `0` | status label de Snipe para activos nuevos (**obligatorio**, se valida contra Snipe) |
| `si4_lease_seconds` | `900` | lease del outbox; debe cubrir el peor caso (se valida contra timeout/reintentos) |
| `si4_max_units_per_run` | `50` | tope de unidades por corrida |
| `si4_retry_base_seconds` / `si4_retry_max_seconds` | `60` / `3600` | backoff de fallas transitorias |
| `si4_config_retry_seconds` | `3600` | reintento de `BLOCKED_CONFIG` |
| `si4_auth_retry_seconds` | `900` | reintento tras 401/403 |
| `si4_uncertain_cooldown_seconds` | `300` | enfriamiento tras un POST incierto (≥ max(60, 2 × timeout)) |
| `si4_worker_id` | `''` | identificador del worker (por defecto `si4:<host>:<pid>`) |

## Estructura
| Ruta | Rol |
|------|-----|
| `setup.php` / `hook.php` | metadatos, init, migraciones reversibles (8 tablas; `install()` seguro en upgrade), ACL, config |
| `src/Model/` | `AssetBridge · MapCompany · MapUser · AssetTagAlias · ReconResult` |
| `src/Client/` | `HttpTransport · HttpResponse · CurlTransport · ArrayTransport · SnipeClientConfig · SnipeException · SnipeItClient` |
| `src/Service/` | `ErrorClassifier · BackoffPolicy · CircuitBreaker · LogSanitizer · CorrelationId · ReconciliationClassifier · LabelConfigChecker · PluginConfig · SnipeConfigFactory · Reconciler · AssetResolver` |
| `src/Controller/GatewayController.php` | gateway QR (GET, AUTHENTICATED) |
| `src/Client/` (SI4-1) | `SnipeAssetWriter` (contrato WRITE) · `SnipeEnvelope` · `CreateResult` · `FakeSnipeServer` (doble de prueba del contrato v8.7.2) |
| `src/Si4/` (SI4-1) | `Si4Worker` · `HandoffSource` (+ `PurchasingHandoffSource`, `InMemoryHandoffSource`) · `SagaStore` (+ `DbSagaStore`, `InMemorySagaStore`) · `MappingResolver` (+ `DbMappingResolver`, `ArrayMappingResolver`, `MappingRules`) · `AssetTagDeriver` · `RemoteAssetMatcher` · `Si4Config` · `Si4Errors` · `WorkerSession` |
| `src/Command/Si4RunCommand.php` | `plugins:companyintegrations:si4-run` (deshabilitado por defecto) |
| `src/Command/SelftestCommand.php` | `plugins:companyintegrations:selftest` (integración + E2E; SI-4 en `Si4SelftestScenarios`) |
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
- **Integración + E2E (en GLPI):** `php bin/console plugins:companyintegrations:selftest`.
  - **SI-1:** tablas, ACL/multi-entidad (gateway), reconciliación con puente persistido, serial conflict, gateway con
    alias histórico y garantía **read-only** (sólo GET + activo GLPI intacto).
  - **SI4-1:** upgrade 0.2.0 → 0.3.0; fencing con el reloj real de la BD.
  - **SI4-1, E2E con Compras REAL:** solicitud → aprobaciones → compra → recepción → outbox →
    `PurchasingIntegrationApi` → Snipe fake → saga, sin ack.
  - **SI4-1, crash y lease:** crash después del POST con lease vencido y dos workers ⇒ un solo activo y una sola saga.
  - **SI4-1, resto:** mapeo ausente; secretos; ACL; comando real con sesión técnica; sin efectos laterales.

## Limitaciones / deuda técnica (SI-1)
- **Sin Snipe-IT real en CI:** la integración se valida con **tests de contrato** (transporte fake) —
  levantar Snipe (Laravel + su DB) volvería el CI excesivamente costoso (permitido por ADR-0015).
  `CurlTransport` es el transporte productivo; un **smoke real** contra un Snipe sandbox se hará al
  configurar `snipe_base_url` + token (fuera de CI).
- `GLPI_ONLY` (pasada inversa GLPI→Snipe) queda como extensión; SI-1 cubre la dirección Snipe→GLPI.
- La ficha segura `companyqr` como destino del gateway es el punto de integración (SI-1 entrega la
  referencia resuelta y aplica la ACL).
