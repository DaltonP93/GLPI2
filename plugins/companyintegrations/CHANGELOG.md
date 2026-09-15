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

### Security
- TLS siempre verificado en el transporte cURL; token fuera de Git/logs; headers/errores saneados.

### Notes
- SI-1 no escribe en Snipe ni modifica activos core de GLPI. Sin Snipe real en CI (contract tests);
  `GLPI_ONLY` y la ficha companyqr como destino del gateway quedan como follow-up (ver README).
