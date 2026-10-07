# Company Integrations (`companyintegrations`)

Plugin propio de la **Plataforma GLPI Modular**.

- **Estrategia (matriz):** Integrate
- **Propósito (SI-1, ADR-0015):** integración **READ-ONLY** con **Snipe-IT** por API — cliente HTTP
  resiliente, `asset_bridge`, mapeos, reconciliación con detección de conflictos y gateway estable.
- **GLPI soportado:** `>=11.0` y `<12.0` (probado en 11.0.8).
- **Estado:** Fase 2 — **SI-1** (read-only) + **SI-4 completo por unidad**: SI4-1 (Snipe, ADR-0020), SI4-2 (activo
  GLPI + Infocom + `asset_bridge`, ADR-0021) y SI4-3 (código companyqr + etiqueta + ack + cierre, ADR-0022). Worker
  **deshabilitado por defecto** y sin Acción automática. Desde 0.6.0 expone además `Api\InventoryLinkApi` (READ-ONLY)
  para que Compras muestre la fase SI-4 y el activo vinculado de cada unidad (ver CHANGELOG).
- **Sin UI propia:**
  - la configuración se cambia con `php bin/console config:set --context=plugin:companyintegrations <clave> <valor>`;
  - los mapeos todavía no tienen interfaz operativa.

  Requisitos para habilitar SI-4: `../../docs/operations/si4-readiness.md`.

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
Primer incremento de SI-4: llega hasta el activo en Snipe-IT (`SNIPE_CREATED`).
- Por sí solo no crea ni modifica activos core de GLPI, no crea Infocom, no invoca companyqr ni genera etiquetas.
- Eso lo agregan SI4-2 y SI4-3 (secciones siguientes).
- La entrega es de Compras (P2D-4).

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
  - Sólo se adopta un activo si coinciden tag, compañía, **modelo**, serial (si lo hay) y la **marca de procedencia**
    `receipt_unit_uuid=<uuid>` (al final de notes), y sólo como recuperación de un POST propio. Un tag preexistente o
    cualquier diferencia ⇒ `MANUAL_REVIEW`.
  - Tras el POST, el `snipe_asset_id` queda **no verificado** hasta que un GET cumple exactamente lo mismo.
- **Saga durable** (`si4_sagas`, bitácora `si4_saga_log`), una por `receipt_unit_uuid`:
  `PENDING → SNIPE_CREATING → SNIPE_CREATED`, más `BLOCKED_CONFIG` y `MANUAL_REVIEW`.
  - Fencing: época monótona del lease (`attempts` del claim) + `sha256(token)` + `lease_until >= NOW()` (reloj de la BD).
  - Antes de un POST se exige un lease restante ≥ presupuesto de escritura.
- **Sin ack en SI4-1:** en modo SI4-1 la unidad queda **estacionada** en `SNIPE_CREATED`. `acknowledgeProcessed()` lo
  hace sólo SI4-3, como último efecto externo (ver más abajo).
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
  - **Sin Acción automática:** activarlo y programarlo es una decisión operativa posterior (staging primero).

## Alcance SI4-2 (ADR-0021) — la misma saga hasta el activo GLPI, su Infocom y `asset_bridge`
Continúa cada unidad desde `SNIPE_CREATED`: `GLPI_RESOLVED_OR_CREATED → INFOCOM_READY → BRIDGED`. Sin la etapa QR
(modo SI4-2) la unidad queda **estacionada** en `BRIDGED` y el outbox sigue abierto; con SI4-3 continúa (ver abajo).
Todo con la API nativa de GLPI 11.0.8 (`CommonDBTM::find/add/update/can`, `Infocom`); **sin SQL contra tablas del
core**.

- **Mapeo aprobado categoría ⇒ tipo de activo GLPI** (tabla nueva `map_glpi_assettypes`, separada de `map_models`):
  `glpi_itemtype` + `glpi_model_id` opcional. Ausente, itemtype no soportado o modelo inexistente ⇒ `BLOCKED_CONFIG`.
  Soportados: `Computer`, `Monitor`, `NetworkEquipment`, `Peripheral`, `Phone`, `Printer`.
  **Pinneado por saga:** el primer uso guarda en la saga `glpi_mapping_id`, `glpi_itemtype`, `glpi_model_id` y una
  huella; los retries usan ese destino aunque el administrador cambie el mapeo (sólo las unidades nuevas ven el
  cambio). Si el modelo pinneado se elimina ⇒ `MANUAL_REVIEW`, nunca otro modelo en silencio.
- **Identidad determinista observable en GLPI:** `otherserial` (número de inventario) = `asset_tag` de la saga.
  Buscar primero por número de inventario y por serial en **todas** las entidades:

  | Situación | Resultado |
  |---|---|
  | ningún candidato | crear (`add()` nativo, verificado después) |
  | 1 inequívoco (propio por tag, o del GLPI Agent por serial) | vincular; si el número de inventario estaba vacío se reclama (GLPI lo bloquea frente al agente) y se **re-clasifican todos los candidatos**: si apareció otro ⇒ `MANUAL_REVIEW` sin vincular |
  | > 1, otra entidad, serial o número de inventario distinto, papelera | `MANUAL_REVIEW`, nada se modifica |

  Crash después de `add()` ⇒ el retry encuentra **el mismo** activo. Si la saga ya registró su activo y no aparece ⇒
  `MANUAL_REVIEW`, nunca un segundo alta.
- **Infocom exacto:** `value` = `unit_cost` de la unidad convertido exactamente a `decimal(20,4)` (nunca redondeo);
  moneda distinta de `si4_glpi_infocom_currency` o escala no representable ⇒ `MANUAL_REVIEW` **antes** de crear nada.
  Proveedor de la compra (existente, fuera de la papelera y **aplicable a la entidad de la unidad**: misma entidad o
  ancestro recursivo, con la regla autoritativa de Compras), `order_number` = n.º de solicitud, `delivery_date` = fecha
  de recepción. Un Infocom
  existente sólo se completa en campos propios vacíos; si difiere ⇒ `MANUAL_REVIEW`.
- **`asset_bridge` 1:1** con `receipt_unit_uuid` (columna nueva, UNIQUE; NULL en puentes SI-1). Un puente SI-1 idéntico
  se adopta; cualquier coincidencia parcial ⇒ `MANUAL_REVIEW`. Nunca se reasigna.
- **Snipe no se toca:** al reanudar se relee el activo de Snipe antes de escribir en GLPI; si diverge ⇒ `MANUAL_REVIEW`.
- **Fencing:** cada escritura en GLPI exige ser dueño del lease con ≥ 30 s restantes (reloj de la BD).
- **Usuario técnico (mínimo privilegio):** además de `RIGHT_SI4` y `RIGHT_INTEGRATION`, READ/CREATE/UPDATE de los
  itemtypes mapeados en las entidades de las unidades e `infocom` READ/CREATE/UPDATE. Sin derecho ⇒ `BLOCKED_CONFIG`.

## Alcance SI4-3 (ADR-0022) — código companyqr, etiqueta, ack y cierre
Continúa cada unidad desde `BRIDGED`: `QR_READY → COMPLETED`. La entrega/UI es de Compras (P2D-4), que lee el
resultado sólo por `Api\InventoryLinkApi` (READ-ONLY, 0.6.0).

- **companyqr sólo por su API pública** (`GlpiPlugin\Companyqr\Api\CompanyQrApi`, vía `CoreQrGateway`):
  - sin otro sistema de QR ni de PDF;
  - sin SQL a las tablas de companyqr;
  - sin conocer el token opaco ni armar la URL del QR.
- **Código idempotente:** `ensureForItem()` reutiliza `CodeManager::getOrCreateForItem()`.
  - Un solo código por activo, también si el proceso cae entre crear el código y registrarlo.
  - Debe estar **ACTIVO**, ser del mismo activo y de la entidad de la unidad.
  - Su `public_code` debe ser el número de inventario de la unidad (= `asset_tag` de Snipe).
  - Revocado, suspendido, de otro activo o entidad, o con otro código visible ⇒ `MANUAL_REVIEW`. **Nunca** se rota ni
    se reactiva.
- **`public_code` identifica, no autoriza:** el QR sigue codificando la ruta autenticada
  `/plugins/companyqr/scan/{token}`.
- **Etiqueta:** el renderer existente de companyqr (`LabelRenderer::pdf`, medidas/config actuales).
  - Se verifica que se renderiza un PDF y **no se guarda**.
  - Sin IP, MAC, hostname, VLAN ni serial.
- **Saga (sin token ni PDF):** `qr_code_id` (UNIQUE), `qr_public_code`, `qr_outcome` (created | existing),
  `label_ready_at`, `completed_at`.
- **Ack = último efecto externo.** Recién con `QR_READY` y una revalidación sólo de lecturas (puente y activo vigentes;
  código ACTIVO, mismo activo y mismo `public_code`) se llama `acknowledgeProcessed()`.
  - Divergencia ⇒ `MANUAL_REVIEW` sin ack.
  - Ack fallido ⇒ la saga queda en `QR_READY` y el reintento sólo revalida y confirma, sin rehacer nada.
- **Finalizador durable:** al inicio de cada corrida (y tras cada ack) cierra en `COMPLETED` las sagas `QR_READY` cuyo
  outbox ya está **DONE** según `getHandoff()`.
  - **Recorrido round-robin acotado:** un lote (200) por corrida desde el cursor durable de la tabla propia
    `si4_runtime`.
  - El cursor es la última saga **inspeccionada**, así que una pendiente nunca bloquea a las posteriores.
  - Al llegar al final, vuelve a empezar (wrap-around).
  - Usa `SagaStore::complete()`, con guarda `state = QR_READY` y sin lease.
  - **Nunca** `COMPLETED` con el outbox en otro estado.
- **Usuario técnico:** además de lo anterior, `plugin_companyqr` `RIGHT_GENERATE` + `RIGHT_PRINT`. Se verifican
  **antes de reclamar** (la corrida no empieza) y por unidad ⇒ `BLOCKED_CONFIG` (`resume_state = BRIDGED`), sin bypass.

### Configuración SI-4 (contexto `plugin:companyintegrations`)
| Clave | Por defecto | Uso |
|---|---|---|
| `si4_enabled` | `0` | habilita el worker |
| `si4_asset_tag_prefix` | `GP2-` | prefijo del tag determinista (`^[A-Z0-9][A-Z0-9-]{0,15}$`) |
| `si4_snipe_status_id` | `0` | status label de Snipe para activos nuevos (**obligatorio**, se valida contra Snipe) |
| `si4_lease_seconds` | `900` | lease del outbox; debe cubrir el peor caso, incluidas las etapas GLPI y QR (se valida contra timeout/reintentos) |
| `si4_max_units_per_run` | `50` | tope de unidades por corrida |
| `si4_retry_base_seconds` / `si4_retry_max_seconds` | `60` / `3600` | backoff de fallas transitorias |
| `si4_config_retry_seconds` | `3600` | reintento de `BLOCKED_CONFIG` |
| `si4_auth_retry_seconds` | `900` | reintento tras 401/403 |
| `si4_uncertain_cooldown_seconds` | `300` | enfriamiento tras un POST incierto (≥ max(60, 2 × timeout)) |
| `si4_worker_id` | `''` | identificador del worker (por defecto `si4:<host>:<pid>`) |
| `si4_glpi_infocom_currency` | `PYG` | moneda que representan los importes del Infocom de GLPI (SI4-2) |

## Estructura
| Ruta | Rol |
|------|-----|
| `setup.php` / `hook.php` | metadatos, init, migraciones reversibles (9 tablas + columnas SI4-2/SI4-3; `install()` seguro en upgrade), ACL, config |
| `src/Model/` | `AssetBridge · MapCompany · MapUser · AssetTagAlias · ReconResult` |
| `src/Client/` | `HttpTransport · HttpResponse · CurlTransport · ArrayTransport · SnipeClientConfig · SnipeException · SnipeItClient` |
| `src/Service/` | `ErrorClassifier · BackoffPolicy · CircuitBreaker · LogSanitizer · CorrelationId · ReconciliationClassifier · LabelConfigChecker · PluginConfig · SnipeConfigFactory · Reconciler · AssetResolver` |
| `src/Controller/GatewayController.php` | gateway QR (GET, AUTHENTICATED) |
| `src/Client/` (SI4-1) | `SnipeAssetWriter` (contrato WRITE) · `SnipeEnvelope` · `CreateResult` · `FakeSnipeServer` (doble de prueba del contrato v8.7.2) |
| `src/Si4/` (SI4-1) | `Si4Worker` · `HandoffSource` (+ `PurchasingHandoffSource`, `InMemoryHandoffSource`) · `SagaStore` (+ `DbSagaStore`, `InMemorySagaStore`) · `MappingResolver` (+ `DbMappingResolver`, `ArrayMappingResolver`, `MappingRules`) · `AssetTagDeriver` · `RemoteAssetMatcher` · `Si4Config` · `Si4Errors` · `WorkerSession` |
| `src/Si4/` (SI4-2) | `Si4GlpiStage` · `GlpiAssetGateway` (+ `CoreGlpiAssetGateway`, `InMemoryGlpiAssets`) · `GlpiCandidateMatcher` · `InfocomPolicy` · `BridgeStore` (+ `DbBridgeStore`, `InMemoryBridgeStore`) · `BridgeMatcher` · `GlpiMappingResolver` (+ `DbGlpiMappingResolver`, `ArrayGlpiMappingResolver`, `GlpiMappingRules`) |
| `src/Si4/` (SI4-3) | `Si4QrStage` · `Si4Finalizer` (+ `FinalizerCursor`: `DbFinalizerCursor`, `InMemoryFinalizerCursor`) · `QrGateway` (+ `CoreQrGateway` ⇒ `CompanyQrApi`, `InMemoryQrGateway`) · `QrGatewayException` · `QrCodeRules` |
| `src/Api/InventoryLinkApi.php` (0.6.0) | API pública READ-ONLY: fase SI-4 + activo vinculado por `receipt_unit_uuid` (ACL nativa del activo, multi-entidad) |
| `src/Command/Si4RunCommand.php` | `plugins:companyintegrations:si4-run` (deshabilitado por defecto) |
| `src/Command/SelftestCommand.php` | `plugins:companyintegrations:selftest` (integración + E2E; SI4-1 en `Si4SelftestScenarios`, SI4-2 en `Si4GlpiSelftestScenarios`, SI4-3 en `Si4QrSelftestScenarios`) |
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
  - **SI4-2 (GLPI real):** upgrade 0.3.0 → 0.4.0 con sagas SI4-1 intactas que luego continúan; E2E con el worker del
    comando real hasta `BRIDGED`; GLPI Agent (vincular + Lockedfield), ambiguo, otra entidad; mapeo/itemtype/modelo;
    9 crash points con toma por época; los 6 itemtypes soportados; multi-entidad; ACL nativa; Infocom distinto o en
    otra moneda; mapeo pinneado (retry con el modelo pinneado, unidad nueva con el vigente, modelo eliminado);
    carrera tras el reclamo del agente; proveedor movido de rama / ancestro recursivo; sin companyqr ni ack en modo SI4-2.
  - **SI4-3 (GLPI + companyqr + Compras reales):**
    - upgrade 0.4.0 → 0.5.0 con sagas `BRIDGED` intactas que luego llegan a `COMPLETED`;
    - E2E con el comando real hasta `COMPLETED` y una etiqueta PDF real;
    - código preexistente: activo (se reutiliza), otro código visible, revocado o suspendido;
    - 8 crash points con el outbox DONE una sola vez;
    - dos workers ⇒ un código; fencing con el reloj de la BD;
    - ack fallido y reintento sin rehacer nada; finalizador tras un crash post-ack;
    - recorrido del finalizador con cursor durable: lote 2, reconstruido en cada corrida, wrap-around, propiedad con
      más sagas que el lote y carrera sobre el cursor;
    - revalidación previa al ack (código revocado, puente divergente);
    - ACL de companyqr; multi-entidad;
    - token ausente de saga/bitácora/outbox/logs; sin Acción automática.
  - **`[UPGRADE]` (0.6.1):** `install()` ×2 conserva EXACTOS los derechos que ajustó un administrador (Super-Admin sin
    `RIGHT_SI4` sigue sin él; ya no `actual | todos`), con configuración, sagas, mappings y `asset_bridge` intactos.

## Limitaciones / deuda técnica (SI-1)
- **Sin Snipe-IT real en CI:** la integración se valida con **tests de contrato** (transporte fake) —
  levantar Snipe (Laravel + su DB) volvería el CI excesivamente costoso (permitido por ADR-0015).
  `CurlTransport` es el transporte productivo; un **smoke real** contra un Snipe sandbox se hará al
  configurar `snipe_base_url` + token (fuera de CI).
- `GLPI_ONLY` (pasada inversa GLPI→Snipe) queda como extensión; SI-1 cubre la dirección Snipe→GLPI.
- La ficha segura `companyqr` como destino del gateway es el punto de integración (SI-1 entrega la
  referencia resuelta y aplica la ACL).
