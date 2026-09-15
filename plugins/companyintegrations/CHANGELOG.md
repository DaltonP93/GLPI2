# Changelog — Company Integrations (`companyintegrations`)

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y versionado [SemVer](https://semver.org/lang/es/).

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
