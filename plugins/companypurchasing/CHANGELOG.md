# Changelog — Company Purchasing (`companypurchasing`)

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y versionado [SemVer](https://semver.org/lang/es/).

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
