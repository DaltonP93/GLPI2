# Changelog — Company Purchasing (`companypurchasing`)

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y versionado [SemVer](https://semver.org/lang/es/).

## [Unreleased]
### Added
- **Fase 2D — Gate native-first (sólo documentación, sin lógica):**
  `../../docs/architecture/companypurchasing-native-first-gate.md`. Fija el alcance de v1
  reconciliado con el **código real ya mergeado** (`WorkflowApi`/`SignatureApi` reales; SI-1
  read-only): integración con `companyworkflow` (sin segundo motor) y `companysignature`
  (snapshot canónico + campos sustantivos), recepción física por unidad (`receipt_unit_uuid`),
  costo atribuible por línea, límite con Snipe-IT (v1 produce **outbox**; SI-4 futuro escribe),
  tablas mínimas, ACL/multi-entidad y tests obligatorios. Pendiente de aprobación humana.
- Esqueleto inicial del plugin (Fase 0): `setup.php`, `hook.php` y estructura
  de carpetas (`src/`, `locales/`, `templates/`, `tests/`).
