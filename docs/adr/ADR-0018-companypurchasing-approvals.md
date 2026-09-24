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
   del estado confirmado; ante discrepancia gana el motor. Listener best-effort de
   `companyworkflow:transitioned/approval_invalidated` acelera la proyección; la convergencia la da la
   **Acción automática nativa** `reconcileprojection` (y el comando `plugins:companypurchasing:reconcile`):
   lotes con **cursor persistido y wrap-around** (sin starvation: con N solicitudes y lote L, cada una se
   revisa en ⌈N/L⌉ ejecuciones). La reconciliación también **reporta** (no oculta) solicitudes enviadas sin
   instancia y con integridad pendiente; no las repara sin un actor con `RIGHT_ACT`.
3. **Saga idempotente, sin llamadas cruzadas dentro de una transacción local**, serializada por el lock
   de solicitud: snapshot semántico del scope de la etapa → `DocumentVersionAllocator` (reutiliza la
   última versión del scope si el payload no cambió; si cambió, asigna la siguiente, monotónica por
   solicitud, nunca reutilizada) → `SignatureApi::recordDocumentVersion()` (idempotente) →
   `evidence_ref = {document_versions_id, document_version, content_sha256}` →
   `WorkflowApi::transition(..., evidence_ref, expected_lock_version)` → auditoría → proyección.
   Toda decisión declara **obligatoriamente** la etapa sobre la que actúa (`decide(request, action,
   expectedState, comment)`): si la instancia ya cambió de etapa ⇒ `stage_changed` sin crear otra decisión
   (dentro de la misma etapa/quórum un reintento es `duplicate`). El envío exige una definición ACTIVA
   **antes** de cualquier cambio local. Convergencia **demostrada** (ver "Garantías demostradas") para las
   caídas: tras el envío local y antes de `startInstance`; tras `recordDocumentVersion` y antes de
   `transition`; tras `transition` confirmada y antes de la proyección; y tras un cambio sustantivo con la
   invalidación caída.
4. **Integridad por scope derivada del ledger del motor** + **marca durable**:
   - Un scope tiene aprobaciones *vivas* si hay decisiones `approved` tomadas en una visita de estado
     iniciada desde la última entrada de la instancia en su checkpoint (o en un estado anterior).
   - Cada aprobación viva debe coincidir **exactamente** con el ledger de Compras: las tres claves
     `document_versions_id`, `document_version`, `content_sha256`, el **scope** de la fila y el contenido
     ACTUAL. Ref incompleta/alterada/ajena o contenido cambiado ⇒ deriva.
   - Una mutación sustantiva posterior al envío (selección/precio de la cotización seleccionada, enmienda de
     cantidad) escribe en **su misma transacción local** una marca `..._integrity` (`dirty`: solicitud,
     scope, causa, actor, estado y lock_version del motor, `idempotency_key`) y `requests.integrity_state`.
     La mutación exige **antes** que el perfil pueda reabrir (`RIGHT_ACT` del motor + `RIGHT_RECORD` de Firma).
   - Reparación (REQUEST_SCOPE con prioridad sobre COMMERCIAL): deriva ⇒ nueva versión +
     `invalidateApprovals()` (`reopen_to_code` = checkpoint, `idempotency_key` derivada de la versión). La
     marca se resuelve **sólo** tras la invalidación CONFIRMADA por el motor, o cuando el ledger del motor
     demuestra que no hay aprobación viva afectada (nada que invalidar). Una reparación fallida deja la marca.
   - Mientras haya marca o deriva, la solicitud **no** está íntegramente aprobada (`integrityStatus()` /
     `isFullyApproved()`), aunque el motor diga APPROVED; y `decide()` repara primero o **falla cerrado**.
     Con esto (y sólo en los casos demostrados abajo) no se decide sobre una aprobación obsoleta.
   - Validación **en vivo**: al construir el snapshot comercial se revalida que el Supplier seleccionado
     siga siendo aplicable a la entidad (cadena viva); si no, el scope no es producible ⇒ fail-closed.
5. **Cotizaciones**: `requests.quotes_id_selected` es la **única** fuente de verdad de la selección
   (sin `is_selected`); la selección es serializada por lock + `lock_version` esperado. Precios finales
   por línea referencian `..._items.id` (no `line_no`); los totales se **derivan** (no se almacenan)
   con aritmética decimal exacta (PYG escala 0).
6. **PDF**: tras aprobar una etapa configurada (`pdf_stages`), Compras llama
   `SignatureApi::composePdf(document_versions_id)`; un fallo se registra (`pdf_status=error`) y es
   reintentable (`retryPdf`), **sin** revertir workflow ni evidencia.
7. **Ajuste genérico en `companysignature`** (domain-agnostic): la materialización de una invalidación
   anula sólo las aprobaciones tomadas en visitas de estado iniciadas **desde la última entrada de la
   instancia en el checkpoint de reapertura** (antes: todas). Resolución con resultado EXPLÍCITO: si hay
   `reopen_to_code` y el ledger no se puede leer con certeza (falla temporal de `history()`, lectura
   incompleta) ⇒ `PENDING` (retryable) y **cero** evidencias de invalidación; ledger legible pero checkpoint
   nunca ingresado ⇒ `ERROR` visible. Sólo una invalidación **legacy sin `reopen_to_code`** conserva el
   comportamiento global previo (anula todas). Necesario para que "cambia el precio final tras Gerencia" no
   anule la evidencia del jefe de área sobre `REQUEST_SCOPE`.
8. **Política de aprobación pinneada por solicitud** (`..._policies`, JSON canónico + hash, inmutable;
   `requests.policies_id`): etapa→scope, scope→checkpoint, `pdf_stages`, `quote_states`, `amend_states` se
   fijan al abandonar DRAFT (junto a `scopes_version`) y rigen toda la vida de la instancia. Un cambio de
   configuración sólo afecta a solicitudes NUEVAS. `sync_on_workflow_events` sigue siendo operacional global.

## Garantías demostradas (selftest obligatorio + unit)
| Afirmación | Prueba |
|---|---|
| Caída tras envío local y antes de `startInstance` ⇒ `reconcile` la reporta; reintento converge | `[CRASH-0]` |
| Sin definición activa ⇒ la solicitud queda en DRAFT (sin número) | `[SUBMIT-PRECHECK]` |
| Caída tras `recordDocumentVersion` y antes de `transition` ⇒ reintento sin duplicar versión/decisión | `[CRASH-1]` |
| Caída tras `transition` confirmada ⇒ `reconcile` corrige; reintento ⇒ `stage_changed` | `[CRASH-2]` |
| Mismo usuario en Compras y Finanzas: reintento tras caída NO aprueba Finanzas | `[EXPECTED-STATE]` |
| Cambio sustantivo con invalidación caída ⇒ marca durable; nunca "limpia"; retry la resuelve; `decide()` repara o falla cerrado; prioridad REQUEST | `[INTEGRITY-DIRTY]` |
| Perfil sin capacidad de reabrir no puede cambiar lo aprobado | `[REOPEN-CAPABILITY]` |
| `evidence_ref` alterada/incompleta/ajena con `evidence_bound=1` ⇒ no íntegra; se repara | `[EVIDENCE-TAMPER]`, unit `evidenceRefMatches` |
| Supplier movido de rama tras seleccionar ⇒ aprobación financiera fail-closed | `[SUPPLIER-MOVED]` |
| Política A pinneada sobrevive a cambio de config; R2 usa B | `[POLICY]`, unit `ApprovalPolicy` |
| Reconciliación sin starvation + Acción automática nativa | `[RECONCILE-CURSOR]`, unit `planBatch` |
| Firma: `history()` caído ⇒ PENDING y cero invalidaciones; al volver, exacta | Firma `[INVALIDATE-LEDGER-FAIL]`, unit `resolveCheckpoint` |

Fuera de estas pruebas NO se afirma convergencia general (p. ej. corrupción manual de tablas o de la
configuración de perfiles).

## Alternativas consideradas
- **Máquina de estados local en Compras** — rechazada (segundo motor; viola ADR-0012).
- **Flags locales `is_bound` por versión** para saber qué está aprobado — rechazada: una caída entre la
  transición y el flag deja el estado local stale (fail-open). El ledger del motor es autoritativo; la marca
  `..._integrity` NO decide qué está aprobado: sólo registra durablemente "puede haber quedado obsoleto".
- **Leer la política desde la configuración vigente** — rechazada: un administrador cambiaría
  retroactivamente la semántica de solicitudes ya iniciadas.
- **`reconcileAll` sobre los primeros N por id** — rechazada: starvation de las solicitudes posteriores.
- **Invalidar siempre desde el inicio** — rechazada: anula aprobaciones de scopes no afectados.
- **`quotes.is_selected`** — rechazada (dos fuentes de verdad; carrera de "dos seleccionadas").
- **Llamar Firma/Workflow dentro de una transacción local** — rechazada: ambos gestionan sus propias
  transacciones; se usa saga idempotente con reintento/reconciliación.

## Consecuencias
- (+) Un único motor y una única capa de evidencia; auditoría de negocio append-only con `correlation_id`.
- (+) Recuperación por reintento/reconciliación en los casos demostrados; las anomalías que exigen un actor
  (enviada sin instancia, integridad pendiente) quedan **reportadas** por la Acción automática/comando.
- (−) Los perfiles deben configurarse: solicitante `plugin_companyworkflow:READ`; aprobadores
  `plugin_companyworkflow:RIGHT_ACT` + `plugin_companysignature:RIGHT_RECORD`; Compras además
  `plugin_companypurchasing:MANAGE_PURCHASING` + `RIGHT_ACT` + `RIGHT_RECORD` (para aceptar cambios que
  exijan reabrir). Grupos aprobadores globales en v1 (por entidad: futuro).
- (−) v1: cotización en la **misma moneda** que la solicitud (otra → rechazo explícito).

## Cumplimiento de la Regla 0
No modifica el core de GLPI: sólo tablas propias, modelos nativos (`Supplier`, `Document`,
`Document_Item`, `Entity`, `Group`) por su interfaz soportada y APIs de plugins propios.
