# Changelog — Company Integrations (`companyintegrations`)

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y versionado [SemVer](https://semver.org/lang/es/).

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
