# Changelog — Company Signature (`companysignature`)

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y versionado [SemVer](https://semver.org/lang/es/).

## [0.2.0] — Fase 2C
### Added
- **Evidencia de aprobación electrónica interna (ADR-0014 + gate D1–D5):** identidad autenticada +
  **hash del contenido versionado** + timestamp UTC + auditoría. **No** es firma digital certificada.
- **2 tablas propias reversibles** (prefijo `glpi_plugin_companysignature_`, `entities_id`):
  - `document_versions`: versión **inmutable** del contenido aprobado
    (`canonical_snapshot → content_sha256`) + artefacto PDF derivado (`documents_id`, `pdf_sha256`,
    `pdf_status`). Regeneración idempotente (**D1**).
  - `evidences`: evidencia **append-only** de decisión/invalidación (`verification_token` opaco,
    `idempotency_key` UNIQUE, `references_evidences_id`).
- **Canonicalización determinista (D4):** contrato domain-agnostic
  `{schema, subject_type, subject_id, entity_id, document_version, payload}`; orden determinista de
  claves, listas preservan orden, `schema`/`document_version` hasheados, **floats rechazados**
  (montos/decimales como string exacto). `Hasher` SHA-256 reproducible. Se conserva el snapshot exacto.
- **Integración con `companyworkflow` (D2):** escucha `companyworkflow:transitioned` y
  `:approval_invalidated` (listener **idempotente**: retry/duplicado/restart → una sola evidencia).
  La invalidación por cambio sustantivo usa la operación genérica
  `WorkflowApi::invalidateApprovals()`; **conserva** la evidencia previa (append-only).
- **PDF aprobado como `Document` NATIVO (D1):** `ApprovedPdfComposer` (TCPDF) con versión + hash + QR
  al endpoint de verificación; hereda ACL/entidad/almacenamiento del core; **no bloquea** la
  evidencia (fallo → `pdf_status=error` + reintento).
- **QR propio (D3):** `VerificationQrRenderer` reutiliza la librería QR nativa de GLPI; **sin
  dependencia funcional de `companyqr`**.
- **Verificación interna autenticada (§7/§13):** `GET /plugins/companysignature/verify/{token}`
  (AUTHENTICATED) + `VerificationService` (ACL `RIGHT_VERIFY` · multi-entidad estricta · recomputa
  el hash del snapshot · refleja `invalidated`/`tampered` · no filtra datos ni existencia).
- **Puerto de firma certificada (§8):** `CertifiedSignerInterface` + `NullSigner` (no-op). **Sin
  proveedor/PKI.** Una imagen de firma **no** es firma certificada.
- **ACL:** derecho `plugin_companysignature` con bits `READ · RIGHT_RECORD · RIGHT_VERIFY ·
  RIGHT_CONFIG` (patrón `ProfileRight`, Super-Admin en instalación).
- **Eventos propios:** `companysignature:evidence_recorded`, `companysignature:approval_invalidated`.
- **Tests:** unit puro (`tests/unit/run.php`, canonicalización/hash/idempotencia/token/NullSigner) +
  integración/E2E (`plugins:companysignature:selftest`).
- **i18n** ES/EN; **CHANGELOG**; **README** actualizado.

### Notes
- **Domain-agnostic (D5):** ninguna referencia a Compras (estados, proveedores, cotizaciones, ítems,
  montos). Los campos sustantivos exactos los definirá `companypurchasing` (Fase 2D).
- Regla 0: no se modifica el core (sólo hooks/API/servicios nativos).

## [0.1.0]
### Added
- Esqueleto inicial del plugin (Fase 0): `setup.php`, `hook.php` y estructura
  de carpetas (`src/`, `locales/`, `templates/`, `tests/`).
