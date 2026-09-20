# Changelog — Company Purchasing (`companypurchasing`)

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y versionado [SemVer](https://semver.org/lang/es/).

## [0.2.0] — Fase 2D · P2D-1 (núcleo de compras)
### Added
- **Esquema propio (migraciones reversibles):** `requests`, `items`, `numbering`, `events`,
  `scope_defs` (prefijo `glpi_plugin_companypurchasing_`). `install/uninstall/reinstall` verificados.
- **ACL por acción** (`plugin_companypurchasing`): `CREATE_REQUEST`, `VIEW_OWN`, `VIEW_ENTITY`,
  `EDIT_DRAFT`, `MANAGE_CONFIG` (activos) + reservados `MANAGE_PURCHASING`/`RECEIVE`/`DELIVER`/
  `VIEW_METRICS`. Multi-entidad **fail-closed**.
- **Dinero EXACTO** sin `float` (`Decimal`/`Money`/`CurrencyPolicy`): `DECIMAL(20,6)` + `currency_code`;
  importes como string exacto; **PYG escala 0**; validación de escala por moneda; **no redondea**
  (un importe inválido falla la validación).
- **Numeración** `UNIQUE(entities_id, scope, year)` **transaccional/concurrency-safe**; el número
  visible se asigna al **abandonar DRAFT** por primera vez; no se reutiliza; se aceptan huecos.
- **CRUD de borrador** (`RequestManager`): crear/editar solicitud y líneas; `submit` (asigna número +
  pinnea `scopes_version`). Identidad de línea = `id`; `line_no` sólo presentación.
- **Approval scopes** versionados/configurables (`ScopeDef`/`ScopeCatalog`) + **snapshot builder**
  determinista (`ScopeSnapshotBuilder`) que entrega a Firma el contrato esperado. `REQUEST_SCOPE` NO
  incluye proveedor/cotización/precio final.
- **Auditoría de negocio** append-only (`PurchasingEvent`/`Audit`), sin secretos, con `correlation_id`.
- **Tests:** unit puro (dinero/escala/scopes/numeración/cantidades) + `plugins:companypurchasing:selftest`
  (integración + E2E), ahora **obligatorio** en CI junto a los otros tres.
### Notes
- **No** integra `companyworkflow`/`companysignature` ni implementa recepción/outbox/Snipe/SI-4 (P2D-2…P2D-4).
- El `domain_state` es **snapshot/cache**; la autoridad de estados será `companyworkflow` (P2D-2).
- Deuda técnica: cantidad DECIMAL por unidad de medida (no inventariables) diferida; v1 exige entero positivo.

## [Unreleased]
### Added
- **Fase 2D — Gate native-first (sólo documentación, sin lógica):**
  `../../docs/architecture/companypurchasing-native-first-gate.md`. Fija el alcance de v1
  reconciliado con el **código real ya mergeado** (`WorkflowApi`/`SignatureApi` reales; SI-1
  read-only): integración con `companyworkflow` (sin segundo motor) y `companysignature`,
  recepción física por unidad (`receipt_unit_uuid`), costo atribuible por línea, límite con
  Snipe-IT (v1 produce **outbox**; SI-4 futuro escribe), tablas mínimas, ACL/multi-entidad y
  tests obligatorios. Pendiente de aprobación humana.
- **Precisión del gate (docs-only):** `evidence_ref` fijada al contrato REAL
  `{document_versions_id, document_version, content_sha256}` (validado por
  `Materializer::resolveRef()`); PDF vía `SignatureApi::composePdf()` **on-demand** (el
  listener materializa evidencia, **no** compone PDF; sin Cron); **approval scopes/checkpoint
  snapshots** en vez de una lista global de campos (invalida desde el checkpoint afectado,
  `reopen_to_code` derivado del scope); `receipt_units.items_id` como FK (no `line_no`);
  numeración `UNIQUE(entities_id, scope, year)`; cotización elegida por
  `requests.quotes_id_selected` (sin boolean concurrente) + adjuntos `Document_Item` N;
  **recepción+outbox atómicos** (`FOR UPDATE`, ROLLBACK) con test de recepción concurrente
  10=6+6→máx 10; **`PurchasingIntegrationApi`** para que SI-4 consuma por API (no SQL directo)
  con estados `PENDING/LEASED/DONE/RETRY/ERROR`; precisión monetaria sin `float` (PYG `scale=0`).
- Esqueleto inicial del plugin (Fase 0): `setup.php`, `hook.php` y estructura
  de carpetas (`src/`, `locales/`, `templates/`, `tests/`).
