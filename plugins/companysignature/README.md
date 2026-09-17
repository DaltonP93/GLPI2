# Company Signature (`companysignature`)

Plugin propio de la **Plataforma GLPI Modular**.

- **Estrategia (matriz):** Build + Integrate
- **Propósito:** Evidencia de aprobación electrónica interna (identidad autenticada + **hash del
  contenido versionado** + timestamp + auditoría) y **puerto** de integración para firma digital
  certificada. **No** es firma digital certificada.
- **GLPI soportado:** `>=11.0` y `<12.0` (el `max=12.0` es límite superior **excluyente**; probado en 11.0.8; GLPI 12 no soportado hasta suite de regresión — ver `../../docs/architecture/glpi-version-compatibility.md`)
- **Estado:** Fase 2C — v1 implementada (evidencia + versionado/hash + verificación interna + PDF nativo)
- **Diseño:** `../../docs/architecture/companysignature-native-first-gate.md` (gate D1–D5) y
  `../../docs/adr/ADR-0014-companysignature.md`.

## Qué hace (v1)
| Pieza | Rol |
|------|-----|
| `Service/Canonicalizer` + `Hasher` | Canonicaliza el snapshot `{schema, subject_type, subject_id, entity_id, document_version, payload}` (determinista, floats rechazados) y calcula `content_sha256` reproducible (**D4**). |
| `Model/DocumentVersion` + `Service/VersionStore` | Versión **inmutable** del contenido aprobado; el PDF es artefacto derivado con regeneración idempotente (**D1**). |
| `Model/ApprovalEvidence` + `Service/EvidenceRecorder` | Evidencia **append-only** e idempotente (`verification_token` opaco, `idempotency_key` UNIQUE). |
| `Service/WorkflowEventListener` | Consume `companyworkflow:transitioned` / `:approval_invalidated` (idempotente). La invalidación usa `WorkflowApi::invalidateApprovals()` (**D2**) y **conserva** la evidencia previa. |
| `Service/ApprovedPdfComposer` | PDF aprobado como `Document` **nativo** (TCPDF) con versión + hash + QR; no bloquea la evidencia. |
| `Service/VerificationQrRenderer` | QR propio sobre la librería nativa de GLPI (**D3**, sin depender de `companyqr`). |
| `Controller/VerifyController` + `Service/VerificationService` | `GET /plugins/companysignature/verify/{token}` (AUTHENTICATED): ACL + multi-entidad, recomputa hash, refleja `invalidated`/`tampered`, no filtra datos. |
| `Service/CertifiedSignerInterface` + `NullSigner` | Puerto de firma certificada (**sin proveedor** en Fase 2). |
| `Api/SignatureApi` | Fachada para el dominio (companypurchasing y futuros). Domain-agnostic (**D5**). |

## Hardening / integridad probatoria (§1–§7)
| Pieza | Rol |
|------|-----|
| `Service/Materializer` | Única vía de creación de evidencia desde el **ledger** de `companyworkflow`. Idempotente por `workflow_history_id`; identidad por **`evidence_ref` explícita** (no infiere por fecha); `event_date` = fecha original del ledger; copia el **contexto histórico** del aprobador; invalidación **exacta** por referencia. |
| `Service/ReconcileService` + `Model/ReconcileTask` | Reconciliación DURABLE: **CronTask nativa** `reconcile` + cola propia (`UNIQUE(workflow_history_id)`, estados/reintentos/backoff) + high-watermark. Un pendiente no bloquea a los posteriores ni se pierde; sobrevive a reinicios. |
| `Command/ReconcileCommand` | `plugins:companysignature:reconcile` (misma lógica harvest+worker, on-demand). |
| Verificación fail-closed | Sin versión/snapshot/hash válido → nunca `valid`. Invalidación reflejada por referencia exacta (`references_evidences_id`). |
| PDF crash + concurrency safe | `SELECT … FOR UPDATE` por `document_versions_id` + marcador técnico estable (relink) + `Document_Item` idempotente: ni carrera ni duplicado en reintento. |

Cada evidencia guarda `event_date` (momento original de la decisión, del ledger) y `materialized_at`
(cuándo companysignature la creó/reconcilió). La `idempotency_key` de invalidación es obligatoria y
el actor de la invalidación se conserva durablemente.

## Tests
- Unit puro: `php plugins/companysignature/tests/unit/run.php`
- Integración + E2E (en GLPI): `php bin/console plugins:companysignature:selftest`
- Reconciliación durable (harvest + worker): `php bin/console plugins:companysignature:reconcile`
  (también automática vía CronTask `reconcile`).

## Regla 0
Este plugin **no modifica el core de GLPI**. Solo usa hooks/API oficiales.
Ver `../../CLAUDE.md` y `../../docs/adr/ADR-0002-glpi-core-immutable.md`.

## Definition of Done (por módulo)
código · migración reversible · ACL · i18n ES/EN · auditoría · métricas/logs ·
tests · documentación · changelog · verificación de core intacto.

## Estructura
| Ruta | Rol |
|------|-----|
| `setup.php` | Metadatos, requisitos e `init` (registro de hooks) |
| `hook.php` | `install()` / `uninstall()` con migraciones reversibles |
| `src/` | Clases del plugin (PSR-4: `GlpiPlugin\...`) |
| `locales/` | Traducciones ES/EN (i18n) |
| `templates/` | Vistas Twig |
| `tests/` | Pruebas del plugin |

## Antes de desarrollar
Ejecutar el **análisis nativo GLPI 11**
(`../../docs/architecture/native-first-process.md`) y registrar la decisión
en un ADR bajo `../../docs/adr/`.
