# Changelog — Company Integrations (`companyintegrations`)

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y versionado [SemVer](https://semver.org/lang/es/).

## [0.6.0] — API pública READ-ONLY de vínculo de inventario (P2D-4, ADR-0023)
### Added
- `Api\InventoryLinkApi::forUnits(receiptUnitUuids)`: para que Compras muestre el estado de integración de cada
  unidad **sin** SQL cross-plugin.
  - Devuelve sólo `phase` (coarse: `pending` / `in_progress` / `completed` / `attention`), `itemtype`, `items_id` y
    `public_code`.
  - Sólo considera sagas de entidades accesibles por la sesión.
  - Incluye el activo y el `public_code` sólo si la sesión puede **ver** ese activo con la ACL nativa
    (`canViewItem`); si no, sólo la fase.
  - Nunca devuelve token QR, lease, ids de Snipe, `last_error` ni datos del worker.
  - UUIDs inválidos se ignoran; máximo 500 por llamada; nunca escribe.
- Selftest `[SI4-LINK-API]`: whitelist de claves, sin secretos, sin derecho sobre el activo ⇒ sólo la fase,
  otra entidad ⇒ nada, fases coarse, sólo lectura. Unit: `phaseOf()` y forma de la API (427 en total).

### Unchanged
- Sin cambios de esquema ni de comportamiento del worker SI-4 (sigue **deshabilitado por defecto**, sin Acción
  automática).

## [0.5.0] — SI4-3 (código companyqr + etiqueta + ack del outbox + cierre de la saga, ADR-0022)
### Added
- **Etapa QR de la saga** (`Si4QrStage`): `BRIDGED → QR_READY → COMPLETED`. `BLOCKED_CONFIG` admite
  `resume_state = BRIDGED`.
- **Código companyqr por la API pública** (`QrGateway` ⇒ `CoreQrGateway` ⇒ `GlpiPlugin\Companyqr\Api\CompanyQrApi`):
  - get-or-create idempotente por activo (un crash entre crear y registrar encuentra el MISMO código);
  - sin otro sistema de QR, sin SQL a tablas de companyqr y sin conocer el token;
  - `QrCodeRules` exige: código ACTIVO, del mismo activo y entidad, con `public_code` = número de inventario de la
    unidad y metadatos sin claves extra (fail-closed si llegara el token);
  - revocado, suspendido, de otro activo/entidad o con otro código visible ⇒ `MANUAL_REVIEW`, sin rotar ni reactivar.
- **Etiqueta renderizable** con el renderer de companyqr: se valida el PDF (`%PDF-` … `%%EOF`) y **no se guarda**. Si
  no se puede renderizar ⇒ `BLOCKED_CONFIG` (`qr_label`).
- **`acknowledgeProcessed()` como ÚLTIMO efecto externo** (único punto: `Si4Worker::continueQr`):
  - antes, revalidación sólo de lecturas (saga `QR_READY` y dueño del lease, puente 1:1, activo GLPI, código ACTIVO
    del mismo activo y mismo `public_code`); divergencia ⇒ `MANUAL_REVIEW` sin ack;
  - ack fallido ⇒ la saga queda en `QR_READY` y el reintento no rehace nada.
- **Finalizador durable** (`Si4Finalizer`): cierra en `COMPLETED` las sagas `QR_READY` cuyo outbox está DONE según
  `getHandoff()`.
  - **Recorrido round-robin acotado y durable:**
    - lote por corrida (`DEFAULT_BATCH` = 200);
    - cursor persistente (`FinalizerCursor` ⇒ tabla propia `si4_runtime`);
    - el cursor es la última saga INSPECCIONADA, así que una saga pendiente nunca bloquea a las posteriores;
    - wrap-around al final;
    - compare-and-set ante corridas concurrentes.
  - Usa `SagaStore::complete()`, con guarda `state = QR_READY` y sin lease; `completed_at` con el reloj de la BD.
  - Corre al inicio de cada corrida y tras cada ack. **Nunca** `COMPLETED` con el outbox ≠ DONE.
  - `transition()` rechaza `COMPLETED`.
- Columnas de `si4_sagas`: `qr_code_id` (UNIQUE), `qr_public_code`, `qr_outcome`, `label_ready_at`, `completed_at`.
  Sin columnas para el token ni el PDF.
- `SagaStore::NOW` (fecha escrita con el reloj de la BD) y `SagaStore::listByStateAfter()`, que alimenta el recorrido
  con cursor.
- Tabla propia `si4_runtime`: estado operativo del worker, con la fila `si4_finalizer_cursor`.
- Métricas de la corrida: `qr_ready`, `completed`, `finalized`, `qr_created`, `qr_existing`.
- Tests:
  - unit/contract `si4q.php` (424 en total);
  - recorrido del finalizador `[SI4Q-FINALIZER-CURSOR]`:
    - lote 2 con objetos reconstruidos (otro proceso CLI);
    - wrap-around;
    - propiedad con más sagas que el lote;
    - compare-and-set ante una carrera;
    - mutantes "cursor no persistido", "avanza sólo al completar" y "sin wrap-around": muertos en unit y en BD;
  - selftest `[SI4Q-*]`: upgrade, resume, E2E con PDF real, códigos preexistentes, 8 crash points, dos workers,
    fencing, ack fallido, finalizador, revalidación, ACL, multi-entidad, token, sin efectos laterales;
  - mutation testing: 13 mutantes unitarios + 20 de BD, todos muertos.

### Changed
- **Usuario técnico:** `WorkerSession::assertRights()` exige además `plugin_companyqr` `RIGHT_GENERATE` +
  `RIGHT_PRINT`, antes de reclamar (fail-closed).
- `Si4Config::minLeaseSeconds` suma 30 s para la etapa QR. Una configuración existente por debajo del nuevo mínimo
  queda fail-closed: el worker no reclama.
- `Si4RunCommand::buildWorker()` cablea la etapa QR. Parámetros opcionales sólo para tests: modo SI4-2 sin QR, fuente y
  sonda.
- Los selftests `[SI4G-*]` usan el worker en modo SI4-2 (sin la etapa QR) para seguir probando ese incremento.

### Upgrade 0.4.0 → 0.5.0
- `install()` idempotente: columnas, índice y tabla `si4_runtime` sólo si faltan.
- Las sagas `BRIDGED` y anteriores, los mapeos, `asset_bridge`, la configuración y los derechos quedan intactos.
  Verificado con huellas en el selftest y con un upgrade real 0.4.0/0.2.0 → 0.5.0/0.3.0: 4 sagas `BRIDGED` de main
  llegaron a `COMPLETED`.
- `si4_enabled` sigue en `0`; sin Acción automática.

## [0.4.0] — SI4-2 (activo GLPI + Infocom + asset_bridge en la misma saga, ADR-0021)
### Added
- **Etapas GLPI de la saga** (`Si4GlpiStage`): `SNIPE_CREATED → GLPI_RESOLVED_OR_CREATED → INFOCOM_READY → BRIDGED`,
  `BLOCKED_CONFIG` con `resume_state` (reanuda desde la etapa post-Snipe) y `MANUAL_REVIEW`. En `BRIDGED` la unidad
  queda estacionada: **sin `acknowledgeProcessed()`**, sin companyqr, sin etiqueta.
- **Resolver-o-crear el activo GLPI** con la API nativa (`CommonDBTM::find/add/update/can`):
  - identidad determinista `otherserial` (número de inventario) = `asset_tag` de la saga;
  - buscar primero por número de inventario y serial en todas las entidades (`GlpiCandidateMatcher`): crear, vincular
    (GLPI Agent, reclamando el número de inventario vacío) o `MANUAL_REVIEW` (ambiguo, otra entidad, serial o número
    de inventario distinto, papelera);
  - intención persistida con fencing antes de `add()`, id registrado sin verificar y verificación posterior;
  - al vincular un activo del agente, después de reclamar el número de inventario se vuelve a buscar y clasificar el
    conjunto completo: otro candidato ⇒ `MANUAL_REVIEW` sin registrar el vínculo, Infocom ni puente.
- **Destino GLPI pinneado por saga:** `glpi_mapping_id` + `glpi_itemtype` + `glpi_model_id` + `glpi_mapping_hash`, escritos
  una sola vez en el primer uso; los retries nunca releen el mapeo vivo. Pin alterado, inválido (modelo eliminado) o
  ausente con activo ⇒ `MANUAL_REVIEW`.
- **Infocom exacto** (`InfocomPolicy`): costo de la unidad a `decimal(20,4)` sin redondeo, moneda configurada
  (`si4_glpi_infocom_currency`, por defecto `PYG`), proveedor de la compra (debe seguir aplicable a la entidad de la
  unidad: misma entidad o ancestro recursivo, con el `ReferenceValidator` de Compras), n.º de solicitud y fecha de recepción;
  completa sólo campos propios vacíos; conflicto ⇒ `MANUAL_REVIEW`.
- **`asset_bridge` 1:1 idempotente** (`BridgeMatcher`, `DbBridgeStore`): inserta con alias vigente, adopta un puente SI-1
  idéntico, nunca reasigna.
- Tabla `map_glpi_assettypes` (categoría ⇒ itemtype + modelo GLPI opcional, aprobado) y modelo `MapGlpiAssetType`.
- `SagaStore::holds()` (dueño + lease restante con el reloj de la BD) antes de cada escritura en GLPI.
- Re-verificación del activo de Snipe al reanudar (nunca se corrige Snipe).
- Selftest `Si4GlpiSelftestScenarios` sobre GLPI real y 136 tests unitarios nuevos (crash points, agente, ACL,
  multi-entidad, Infocom, puente, upgrade, pin del mapeo, carrera tras el reclamo, proveedor por entidad).

### Changed
- `si4_sagas`: columnas `glpi_itemtype`, `glpi_items_id`, `glpi_entity_id`, `glpi_outcome`, `glpi_create_calls`,
  `glpi_infocom_id`, `infocom_outcome`, `asset_bridge_id`, `resume_state`, `glpi_mapping_id`, `glpi_model_id`,
  `glpi_mapping_hash` y `UNIQUE(glpi_itemtype, glpi_items_id)`.
- `GlpiAssetGateway::supplierUsable()` recibe la entidad de la unidad (`supplierUsable(supplierId, entityId)`).
- `asset_bridge`: columna `receipt_unit_uuid` (UNIQUE; NULL para los puentes SI-1).
- `install()` agrega columnas/índices sólo si faltan (mismo camino para instalación nueva y upgrade 0.3.0 → 0.4.0).
- El lease mínimo suma un margen para las etapas GLPI; `si4-run` cablea la etapa GLPI (`Si4RunCommand::buildWorker`).

## [0.3.0] — SI4-1 (primer incremento de SI-4, ADR-0020)
### Added
- **Contrato WRITE con Snipe-IT** (`SnipeAssetWriter`), verificado contra el código real de v8.7.2:
  - éxito y error de negocio llegan con HTTP 200; se decide por el cuerpo (`SnipeEnvelope`);
  - `lookupByTag` con `?deleted=true`;
  - POST de **un solo disparo** con resultado clasificado (`CreateResult`: created / validation / conflict / auth /
    rate_limit / uncertain / rejected / circuit);
  - User-Agent propio y TLS obligatorio.
- **Identidad remota determinista:** `asset_tag = <prefijo><32 hex del receipt_unit_uuid>` (`AssetTagDeriver`).
- **Create-or-reconcile idempotente** (`Si4Worker`):
  - busca primero en cada intento;
  - enfría tras un POST incierto;
  - vincula tras un 409 o una validación de tag;
  - nunca borra ni recrea.
- **Identidad remota estricta** (`RemoteAssetMatcher`):
  - un activo se adopta sólo si coinciden tag, compañía, **modelo**, serial (si la unidad lo trae) y la **marca de
    procedencia** `receipt_unit_uuid=<uuid>` en notes;
  - y sólo como recuperación de un POST propio: un tag preexistente nunca se adopta;
  - si no ⇒ `MANUAL_REVIEW` (`MODEL_MISMATCH`, `OWNERSHIP_MISMATCH`, `PREEXISTING`…).
- **Verificación posterior al POST con las mismas exigencias:** el `snipe_asset_id` queda no verificado
  (`SNIPE_CREATING`) hasta que el GET cumple todo; diferencia ⇒ `MANUAL_REVIEW`.
- `WorkerSession` usa `Request::RIGHT_INTEGRATION` de Compras (contrato público; sin copiar el número).
- **Saga durable** `si4_sagas` + bitácora append-only `si4_saga_log`:
  - `UNIQUE(receipt_unit_uuid)` y `UNIQUE(snipe_asset_id)`;
  - fencing por época monótona del lease + token + reloj de la BD (`DbSagaStore`);
  - presupuesto de lease antes de cada POST.
- **Consumo por lease exclusivamente vía `PurchasingIntegrationApi`** (puerto `HandoffSource`). **Sin
  `acknowledgeProcessed()`** hasta completar SI-4: la unidad queda estacionada en `SNIPE_CREATED`.
- **Mapeos validados:**
  - nueva tabla `map_models` (categoría exacta → modelo Snipe, aprobado);
  - compañía por `map_companies` aprobado (exactamente uno por entidad);
  - estado por `si4_snipe_status_id`, validado contra Snipe en el preflight.
  - Mapeo ausente ⇒ `BLOCKED_CONFIG` + RETRY.
- **Comando `plugins:companyintegrations:si4-run`** (deshabilitado por defecto, sin Acción automática):
  - sesión GLPI real del usuario técnico;
  - exige `RIGHT_SI4` (nuevo bit 16) + `RIGHT_INTEGRATION` de Compras.
- **Tests:**
  - Unit/contract: +140 (`tests/unit/si4.php`; total 198): contrato WRITE (401/403/409/429/5xx/timeout), TLS, token nunca en
    logs, crash en 3 puntos, POST incierto, N procesamientos ⇒ 1 activo, dos workers ⇒ 1 saga, lease vencido,
    multi-entidad, mapeos, conflictos y nunca ack.
  - Selftest: `[SI4-*]` con Compras REAL.
- **i18n** ES/EN de los textos nuevos.

### Fixed
- `install()` **seguro en upgrade**:
  - el derecho sólo se agrega si falta (antes: `Duplicate entry` al actualizar);
  - la configuración sólo siembra claves **ausentes** (antes pisaba `snipe_base_url` y los demás ajustes del admin);
  - los bits del Super-Admin se **suman**.
- SI-1: `getHardwareByTag`/`getHardwareById` devuelven `null` también ante `HTTP 200 + status:"error"` (así responde
  Snipe v8.7.2 "no existe"); antes devolvían el sobre de error como si fuera un activo.

### Notes
- Todavía **no** se crean activos GLPI, Infocom, llamadas a companyqr, etiquetas ni entrega (incrementos siguientes
  de SI-4 / P2D-4).
- No se refleja costo en Snipe (`purchase_cost` es decimal(20,2) de moneda única; el costo exacto irá a Infocom).

## [Unreleased]
### Added
- **Integración Snipe-IT SI-1 (ADR-0015), READ-ONLY:**
  - `SnipeItClient` resiliente (sólo GET): timeout, retry+backoff, manejo 401/403/404/409/429/5xx,
    circuit breaker, correlation_id, logs saneados (token nunca en logs). Transporte inyectable
    (`HttpTransport`) con `CurlTransport` (TLS siempre verificado) y `ArrayTransport` para tests.
  - Token desde variable de entorno/secret (`COMPANYINTEGRATIONS_SNIPEIT_TOKEN`), nunca en Git/BD.
  - 5 tablas propias con migración reversible: `asset_bridge`, `asset_tag_aliases`, `map_companies`,
    `map_users`, `recon`.
  - Reconciliación conservadora (`ReconciliationClassifier` + `Reconciler`) que clasifica
    MATCHED/SNIPE_ONLY/AMBIGUOUS/COMPANY_UNMAPPED/SERIAL_CONFLICT/ERROR; **nunca auto-corrige**;
    crea puente sólo con evidencia inequívoca.
  - Gateway `GET /asset/{asset_tag}` (AUTHENTICATED) con resolución estable (tag actual/histórico) +
    ACL nativa (multi-entidad). Chequeo de configuración de etiquetas (`plain_asset_tag`).
- **Tests:** unit + **contract** (`tests/unit/run.php`, sin GLPI) e integración/E2E
  (`plugins:companyintegrations:selftest`).
- **i18n** ES/EN. **ACL** por bits del derecho `plugin_companyintegrations`.
- **CI:** cambio genérico y guardado (idéntico al de companyworkflow) para correr unit e integración
  de los plugins Fase 2.

### Hardening (pasada de consistencia SI-1)
- **TLS obligatorio en `SnipeClientConfig`:** una `base_url` que no sea `https://` se **rechaza por
  defecto** (un `http://` no usa TLS y expondría el Bearer token); HTTP inseguro sólo con override
  explícito de DEV (`allow_insecure_http`, off por defecto). Además de `CURLOPT_SSL_VERIFYPEER`.
- **Paginación completa** de la reconciliación (`limit/offset` con `reconcile_max_assets` y guardas
  anti-loop) — ya no procesa sólo la primera página.
- **`sync_status` del puente refleja la clasificación REAL** (nunca "MATCHED" por defecto): un
  puente cuya compañía dejó de estar mapeada pasa a `company_unmapped`, no se reporta sano.
- **Integridad del objetivo GLPI:** para un puente existente se verifica que el objeto GLPI siga
  existiendo y con entidad coherente; si no, `ERROR` (no "MATCHED" silencioso).
- **Rename de asset_tag** con identidad estable: tag viejo→alias histórico, nuevo→actual, ambos
  resuelven al mismo puente; si el nuevo tag ya pertenece a otro puente → conflicto fail-closed
  (sin reasignación silenciosa).
- **`createBridge` y el rename transaccionales/race-safe** (un fallo del alias no deja medio mapeo;
  idempotencia concurrente por `snipe_asset_id`).
- **Tests añadidos:** TLS https/http (unit); paginación >50; bridge existente + quitar map_company →
  COMPANY_UNMAPPED; objetivo GLPI borrado → ERROR; rename estable + conflicto; createBridge idempotente.

### Security
- TLS **obligatorio por esquema** (https) además de verificación de certificado en cURL; token fuera
  de Git/logs; headers/errores saneados.

### Notes
- SI-1 no escribe en Snipe ni modifica activos core de GLPI. Sin Snipe real en CI (contract tests);
  `GLPI_ONLY` y la ficha companyqr como destino del gateway quedan como follow-up (ver README).
