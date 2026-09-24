# ADR-0018: `companypurchasing` P2D-2 — circuito de aprobación sobre `companyworkflow` + `companysignature`

- **Estado:** Propuesto (implementado en P2D-2; pendiente de revisión humana)
- **Fecha:** 2026-09-24
- **Decisores:** Producto, Compras, finanzas, seguridad, plataforma
- **Módulo/área:** `plugins/companypurchasing` (+ ajuste genérico en `plugins/companysignature`)
- **Complementa:** ADR-0012 (motor), ADR-0013 (compras), ADR-0014 (firma), gate
  `../architecture/companypurchasing-native-first-gate.md` §3/§4/§8/§9/§10

## Contexto
P2D-1 dejó la solicitud en `DRAFT → PENDING` (número + scopes pinneados) sin motor de estados. P2D-2 debe
llevarla por el circuito real **DRAFT → jefe de área (REQUEST_SCOPE) → Compras/cotización →
gerencia financiera (COMMERCIAL_FINANCIAL_SCOPE) → APPROVED / REJECTED / RETURNED**, con evidencia
probatoria por aprobador, invalidación por checkpoint y PDF, **sin** construir un segundo motor ni una
segunda capa de evidencia.

Análisis native-first (orden Configurar → Existente → Extender → Integrar → Construir):

| Necesidad | Resolución | Tipo |
|---|---|---|
| Estados, aprobadores, quórum, delegación, SLA, lock_version, invalidación | `companyworkflow` (`WorkflowApi`) | **Integrate** (propio, ya mergeado) |
| Snapshot canónico, hash, versión inmutable, evidencia por aprobador, PDF | `companysignature` (`SignatureApi`) | **Integrate** |
| Proveedor | `Supplier` nativo | **Configure/Reuse** |
| Adjuntos de cotización (N por cotización) | `Document` + `Document_Item` nativos | **Reuse** |
| Cotización + precios finales por línea | tablas propias `..._quotes`, `..._quote_items` | **Build** (GLPI no modela cotizaciones comparables) |
| Versión documental de dominio | allocator propio `..._docseq` + ledger `..._doc_versions` | **Build** (companysignature valida/inmoviliza; no numera) |

## Decisión
1. **Definición de workflow publicada por Compras** en `companyworkflow` (`DefinitionBuilder::createVersion`),
   construida desde configuración (`plugin:companypurchasing`): grupos aprobadores, quórum y SLA por etapa
   **nunca** hardcodeados (publicar sin grupos configurados falla cerrado). Estados del proceso:
   `DRAFT`(inicial, editable) · `PENDING_AREA_HEAD` · `PURCHASING` · `PENDING_FINANCE` · `APPROVED`
   (**intermedio**: P2D-3 continúa desde aquí y la invalidación post-aprobación sigue siendo posible) ·
   `RETURNED`(editable) · `REJECTED`/`CANCELLED`(finales). Las transiciones `approve` exigen la condición
   `evidence_bound = 1`: la UI genérica del motor no puede aprobar sin evidencia (defensa en profundidad).
2. **Autoridad = instancia de `companyworkflow`**. `requests.domain_state` es una **proyección** (cache)
   del estado confirmado; ante discrepancia gana el motor (`reconcile`). Listener best-effort de
   `companyworkflow:transitioned/approval_invalidated` acelera la proyección; `reconcile` la garantiza.
3. **Saga idempotente, sin llamadas cruzadas dentro de una transacción local**, serializada por el lock
   de solicitud: snapshot semántico del scope de la etapa → `DocumentVersionAllocator` (reutiliza la
   última versión del scope si el payload no cambió; si cambió, asigna la siguiente, monotónica por
   solicitud, nunca reutilizada) → `SignatureApi::recordDocumentVersion()` (idempotente) →
   `evidence_ref = {document_versions_id, document_version, content_sha256}` →
   `WorkflowApi::transition(..., evidence_ref, expected_lock_version)` → auditoría → proyección.
   Un reintento tras caída en cualquier punto converge sin duplicar versión ni transición.
4. **Integridad por scope derivada del ledger del motor** (no de flags locales que podrían quedar
   stale): un scope tiene aprobaciones *vivas* si hay decisiones `approved` posteriores a la última
   entrada de la instancia en su checkpoint (o en un estado anterior). Si el contenido actual del scope
   difiere del de la versión aprobada → nueva versión + `invalidateApprovals()` con
   `reopen_to_code = checkpoint del scope` e `idempotency_key` derivada de la versión. Se evalúa antes
   de **cada** decisión y después de cada mutación comercial/enmienda (fail-closed: nunca se aprueba
   sobre una aprobación previa obsoleta).
5. **Cotizaciones**: `requests.quotes_id_selected` es la **única** fuente de verdad de la selección
   (sin `is_selected`); la selección es serializada por lock + `lock_version` esperado. Precios finales
   por línea referencian `..._items.id` (no `line_no`); los totales se **derivan** (no se almacenan)
   con aritmética decimal exacta (PYG escala 0).
6. **PDF**: tras aprobar una etapa configurada (`pdf_stages`), Compras llama
   `SignatureApi::composePdf(document_versions_id)`; un fallo se registra (`pdf_status=error`) y es
   reintentable (`retryPdf`), **sin** revertir workflow ni evidencia.
7. **Ajuste genérico en `companysignature`** (domain-agnostic): la materialización de una invalidación
   anula sólo las aprobaciones decididas **desde la última entrada de la instancia en el checkpoint de
   reapertura** (antes: todas). Sin checkpoint localizable → comportamiento previo (anula todas;
   conservador). Necesario para que "cambia el precio final tras Gerencia" no anule la evidencia del
   jefe de área sobre `REQUEST_SCOPE`.

## Alternativas consideradas
- **Máquina de estados local en Compras** — rechazada (segundo motor; viola ADR-0012).
- **Flags locales `is_bound` por versión** para saber qué está aprobado — rechazada: una caída entre la
  transición y el flag deja el estado local stale (fail-open). El ledger del motor es autoritativo.
- **Invalidar siempre desde el inicio** — rechazada: anula aprobaciones de scopes no afectados.
- **`quotes.is_selected`** — rechazada (dos fuentes de verdad; carrera de "dos seleccionadas").
- **Llamar Firma/Workflow dentro de una transacción local** — rechazada: ambos gestionan sus propias
  transacciones; se usa saga idempotente con reintento/reconciliación.

## Consecuencias
- (+) Un único motor y una única capa de evidencia; auditoría de negocio append-only con `correlation_id`.
- (+) Recuperación por reintento/reconciliación sin intervención manual.
- (−) Los perfiles deben configurarse: solicitante `plugin_companyworkflow:READ`; aprobadores
  `plugin_companyworkflow:RIGHT_ACT` + `plugin_companysignature:RIGHT_RECORD`; Compras además
  `plugin_companypurchasing:MANAGE_PURCHASING`. Grupos aprobadores globales en v1 (por entidad: futuro).
- (−) v1: cotización en la **misma moneda** que la solicitud (otra → rechazo explícito).

## Cumplimiento de la Regla 0
No modifica el core de GLPI: sólo tablas propias, modelos nativos (`Supplier`, `Document`,
`Document_Item`, `Entity`, `Group`) por su interfaz soportada y APIs de plugins propios.
