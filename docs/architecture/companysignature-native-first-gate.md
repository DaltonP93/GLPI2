# Gate native-first — `companysignature` (Fase 2C)

> **Documento de decisión previa a implementar. NO contiene lógica.**
> Objetivo: confirmar el diseño aprobado (ADR-0014 + `companysignature-technical-design.md`)
> contra el **proceso native-first** (Configurar → Plugin existente → Extender → Integrar →
> Construir) usando lo que **ya está mergeado en `main`** (`companyworkflow`, `companyqr`),
> y **surfacing** de las decisiones que necesitan tu aprobación **antes** de escribir código.
> Base: `main` post-merge de PR #6 (`1be127e`). Fases 2A (`companyworkflow`) y 2B (SI-1) ✅.

---

## 0. Resumen ejecutivo

`companysignature` = **evidencia de aprobación electrónica interna** (identidad autenticada +
acción explícita + auditoría + **hash del contenido versionado** + timestamp), **no** firma
digital certificada (esa queda como **puerto** `CertifiedSignerInterface`, sin proveedor).
El diseño de ADR-0014 sigue siendo válido y es **native-first**: reutiliza identidad/sesión,
entidades, `Log`, TCPDF y el patrón QR; construye **sólo** lo que el core no ofrece (evidencia
con hash + versionado + regla de invalidación). Antes de codificar hay **5 decisiones abiertas**
(§12) que conviene cerrar con vos.

---

## 1. ¿ADR-0014 sigue vigente?

**Sí, con dos precisiones surgidas de la Fase 2A/2B ya implementada:**
- La integración con `companyworkflow` puede apoyarse en superficies **que ya existen** en `main`
  (evento `companyworkflow:transitioned`, `WorkflowApi::transition()`, y el evento de historial
  `approval_invalidated`). Ver §4 y decisión D2.
- El **PDF aprobado** puede almacenarse como **`Document` nativo** de GLPI (no sólo como archivo
  del plugin), ganando ACL/entidad/almacenamiento nativos. Ver §2 y decisión D1.

Ninguna precisión contradice ADR-0014; la **complementan**. Recomendación: mantener ADR-0014
"Aceptado" y registrar estas precisiones como addendum al aprobar este gate.

---

## 2. Reuso de GLPI 11 (documentos, usuarios, entidades, historial, TCPDF, QR)

| Capacidad | Nativo GLPI 11 | Decisión native-first |
|---|---|---|
| **Identidad / autenticación** | `Session::getLoginUserID()`, `User`, `Profile_User` | **Reutilizar**. La evidencia usa la **sesión autenticada**; jamás una imagen de firma. |
| **Entidades / aislamiento** | `entities_id`, `Session::haveAccessToEntity()`, recursividad | **Reutilizar** en todas las tablas y en la verificación. |
| **Historial del ítem** | `Log` nativo | **Reutilizar** para el timeline visible del objeto aprobado (además de la evidencia propia). |
| **Generación de PDF** | **TCPDF** (bundled; ya usado por `companyqr`) | **Reutilizar** TCPDF vía un `ApprovedPdfComposer` propio (mismo patrón que `companyqr/src/Service/LabelRenderer.php`). |
| **QR** | patrón `companyqr/src/Service/QrRenderer.php` | **Reutilizar el patrón** (no acoplar clase entre plugins). Ver decisión **D3**. |
| **Almacenamiento del PDF** | `Document` + `Document_Item` | **Integrar** (recomendado): guardar el PDF aprobado como `Document` nativo enlazado al ítem → hereda ACL/entidad/almacenamiento. Ver decisión **D1**. |
| **Notificaciones** | `Plugin::doHookFunction` + (a futuro) plantillas nativas | **Integrar** vía hooks/eventos (§10). |
| **Firma digital certificada** | — (no hay nativo) | **Construir sólo un puerto** (`CertifiedSignerInterface` + `NullSigner`); sin proveedor en Fase 2. |
| **Evidencia con hash + versionado + invalidación** | — (no hay nativo; `Log` no fija hash de contenido versionado) | **Construir** (mínimo imprescindible; §3). |

**Regla 0:** todo lo anterior es plugin + APIs/servicios nativos. **Sin tocar el core.**

---

## 3. Tablas propias realmente necesarias

El core **no** modela "aprobación con hash de contenido versionado que se invalida al cambiar el
contenido". Mínimo imprescindible = **2 tablas propias** (prefijo `glpi_plugin_companysignature_`,
migración reversible, `entities_id` para aislamiento):

1. **`evidences`** (append-only) — evidencia de aprobación:
   `id · itemtype · items_id · document_version · content_hash(SHA-256) · users_id · profiles_id ·
   role · entities_id · decision(approved/rejected/returned) · comment · date · timezone ·
   workflow_instances_id · transition · verification_code`.
2. **`document_versions`** — versionado del contenido aprobado:
   `id · itemtype · items_id · version · canonical_hash · date · users_id_author · is_substantive(bool)`.

**Justificación de no construir de más:**
- El **PDF** no es una tabla propia: se guarda como `Document` nativo (D1) o, si se rechaza D1,
  como archivo del plugin — pero **no** una tabla nueva.
- El **verification_code** vive dentro de `evidences` (no requiere tabla aparte).
- No se crean tablas para usuarios, entidades ni historial (son nativos).

---

## 4. Integración con `companyworkflow` (ya mergeado)

Superficies **reales** disponibles hoy en `main`:
- **Evento** `companyworkflow:transitioned` (emitido por `NotificationBridge` vía
  `Plugin::doHookFunction`). → `companysignature` **escucha** este evento para **registrar
  evidencia** en cada decisión (approve/reject/return), tomando `instance`, `transition`,
  `users_id`, `entities_id`.
- **API de dominio** `WorkflowApi`: `startInstance()`, `availableActions()`, `transition()`,
  `builder()`.
- **Invalidación ya soportada**: el `Engine` de `companyworkflow` registra
  `HistoryEvent::EVENT_APPROVAL_INVALIDATED` y **reabre etapas** en la acción de **devolución**.

**Contrato de integración propuesto (a confirmar — decisión D2):**
- **Registrar evidencia**: `companysignature` se suscribe a `companyworkflow:transitioned` y
  persiste `evidences` (append-only) + una entrada en `Log`.
- **Disparar invalidación**: ante una edición **sustantiva** del contenido (§6),
  `companysignature` pide a `companyworkflow` **reabrir** las etapas aprobadas. Dos opciones:
  - **(a) Reutilizar** `WorkflowApi::transition($instance, <acción de devolución/reapertura>, ctx)`
    con una transición ya definida en la plantilla del workflow (sin cambiar `companyworkflow`).
  - **(b) Agregar** a `companyworkflow` un método de dominio explícito
    (p. ej. `WorkflowApi::reopenApprovals($instance, $reason)`) — **pequeña** ampliación de su API.
  Recomendación: **(a)** si la plantilla de compras ya define esa transición; **(b)** si se
  necesita una semántica "reabrir por cambio de contenido" independiente del actor humano.
  **Esto es lo más importante a cerrar antes de codificar.**

`companysignature` **no** reimplementa quórum/estados/aprobadores: eso es de `companyworkflow`.

---

## 5. Versionado y hash del documento

- Cada **edición** del objeto aprobado crea/actualiza una fila en `document_versions` con
  `version`, `canonical_hash` e `is_substantive`.
- `Canonicalizer` produce una **representación canónica del contenido aprobado** (no del PDF con
  metadatos volátiles): JSON **ordenado y normalizado**
  (`{number, items[], amounts, currency, selected_supplier, approvers_hasta_aquí, version}`).
- `Hasher` = `hash('sha256', canonical)`. Propiedad clave: **reproducible** — recomputar la
  canónica del mismo contenido da el mismo `content_hash`. Cada evidencia referencia
  `document_version` + `content_hash`.
- El PDF impreso lleva **versión + hash**; un cambio de contenido ⇒ **versión nueva** ⇒ PDF nuevo.

---

## 6. Política de invalidación al cambiar el contenido

- **Campos sustantivos declarados** (ítems, cantidades, montos, proveedor seleccionado, categoría):
  cambiarlos ⇒ `is_substantive = 1` ⇒ **reabrir** las aprobaciones afectadas vía `companyworkflow`
  (registrando `approval_invalidated` en el historial **append-only**; **no** se borra evidencia).
- **Cambios no sustantivos** (p. ej. una observación) ⇒ **no** invalidan (configurable).
- Regla resumida: *aprobás una versión; si cambia el contenido aprobado, esas aprobaciones se
  reinician.* La **lista exacta** de campos sustantivos requiere sign-off del dominio (decisión D4)
  y depende de `companypurchasing` (aún no construido; decisión D5).

---

## 7. Verificación pública/interna del documento

- `VerifyController` (**GET** `/verify/{code}`, **AUTHENTICATED**): dado `verification_code`,
  `VerificationService` **recomputa** la canónica de la versión referida y **confirma** que
  coincide con `content_hash`; muestra **quién/cuándo aprobó respetando ACL/entidad** (identidad
  mínima necesaria).
- Principio heredado de `companyqr`: **"el código identifica; GLPI autoriza"**. La verificación
  es **interna autenticada + ACL**, **no** anónima pública. (Exposición anónima, si el negocio la
  pidiera, sería un follow-up con su propio ADR.)

---

## 8. Separación estricta: aprobación electrónica interna vs. firma digital certificada

- **v1 = sólo evidencia de aprobación electrónica interna** (identidad + hash + timestamp +
  auditoría). Es lo que Fase 2 necesita.
- **Firma digital certificada** = **puerto** `CertifiedSignerInterface { sign(payload): SignatureResult; verify(sig): bool }` con `NullSigner` por defecto (no-op documentado). **Sin
  proveedor, sin PKI** en Fase 2; se integra cuando negocio/legal lo exijan (ADR de seguimiento).
- **Una imagen PNG de firma NO es evidencia** (a lo sumo, decorado visual del PDF). Regla dura.

---

## 9. ACL / multi-entidad

- Derecho propio `plugin_companysignature` con **bits** (p. ej. `VER`, `APROBAR/registrar
  evidencia`, `VERIFICAR`, `CONFIG`), asignados por `ProfileRight` (patrón `companyworkflow`).
- `entities_id` en `evidences` y `document_versions`; toda lectura/verificación pasa por
  `Session::haveAccessToEntity()`; multi-entidad **estricta** (evidencia de la entidad A no se
  muestra en la B).
- La **acción de aprobar** hereda la ACL del workflow (`companyworkflow` valida el actor);
  `companysignature` **registra** la evidencia de esa decisión, no crea una vía de aprobación
  paralela.

---

## 10. Eventos

- **Consume**: `companyworkflow:transitioned` (para registrar evidencia).
- **Emite** (para notificaciones/observabilidad, patrón `NotificationBridge`):
  - `companysignature:evidence_recorded` (se registró una evidencia).
  - `companysignature:approval_invalidated` (cambio sustantivo reabrió aprobaciones).
- Métricas/logs estructurados (ADR-0010) por cada evidencia y cada invalidación.

---

## 11. Estrategia de tests (Unit / Integration / E2E)

- **Unit (puro, sin GLPI):** canonicalización **determinista** (mismo contenido ⇒ mismo hash;
  orden de claves irrelevante); detección de cambio **sustantivo** vs. no sustantivo;
  verificación (hash coincide / no coincide).
- **Integration (en GLPI 11.0.8, fail-closed):** evidencia **append-only** (no se borra);
  edición sustantiva tras aprobación ⇒ etapas reabiertas (vía `companyworkflow`); PDF aprobado
  contiene **hash + QR**; verificación **sin** exponer datos fuera de ACL; multi-entidad.
- **E2E HTTP:** aprobar → generar PDF → `/verify/{code}` confirma validez; editar contenido
  sustantivo ⇒ verificación refleja la **invalidación**.
- Convención CI ya existente: `plugins:companysignature:selftest` (guardado en `ci.yml`, se
  omite si no existe). Unit runner: `plugins/companysignature/tests/unit/run.php`.

---

## 12. Decisiones abiertas para tu aprobación (antes de codificar)

**APROBADAS (2026-09-16).** Definiciones finales que rigen la implementación:

### D1 — PDF como `Document` nativo (inmutable, hashes separados) — APROBADO
- Cada versión documental es **inmutable**:
  `document_version → canonical_snapshot → content_sha256 → PDF Document`. **No** se sobrescriben
  PDFs anteriores.
- Se guardan **dos hashes separados**:
  - `content_sha256`: hash de la **representación canónica aprobada** (la prueba).
  - `pdf_sha256`: integridad del **artefacto PDF** generado (derivado).
- El PDF es un **artefacto derivado**. Si su generación/alta como `Document` **falla después** de
  registrar la evidencia, la aprobación **no desaparece ni se repite**: el artefacto se marca
  `pending/error` y se permite **regeneración idempotente** (mismo `document_version` ⇒ mismo PDF
  lógico; no duplica evidencia).

### D2 — Extender `companyworkflow` con `invalidateApprovals()` — APROBADO
- **No** reutilizar `WorkflowApi::transition()` simulando una devolución.
- Agregar a `companyworkflow` una operación **genérica y explícita**:
  `invalidateApprovals(instanceId, reason, context, expectedVersion)` que debe ser:
  **domain-agnostic · fail-closed · con control de concurrencia (`expectedVersion`/`lock_version`) ·
  idempotente**; invalida las aprobaciones según la **política del workflow**; **reabre** la
  etapa/checkpoint configurado; genera **auditoría append-only**; **emite**
  `companyworkflow:approval_invalidated`.
- `companysignature` **consume** ese evento y registra una **nueva evidencia de invalidación**.
  **Nunca** borra ni modifica silenciosamente una evidencia histórica aprobada.
- La extensión de `companyworkflow` se mantiene **pequeña, genérica y con Unit + Integration + E2E
  propios**. **Ninguna referencia a Compras.**

### D3 — QR: adapter propio `VerificationQrRenderer` — APROBADO
- `companysignature` **no depende funcionalmente de `companyqr`**.
- Adapter propio mínimo `VerificationQrRenderer` que reutiliza la **librería QR ya disponible en
  GLPI**; el QR apunta al **endpoint propio de verificación** de Firma.
- **No** extraer todavía una librería compartida entre plugins (sólo si aparece un 3.º consumidor real).

### D4 — Contenido sustantivo: contrato domain-agnostic (snapshot canónico) — APROBADO
- `companysignature` **no decide** campos de negocio. Recibe un **snapshot canónico** con contrato:
  ```
  { schema, subject_type, subject_id, entity_id, document_version, payload }
  ```
  El **dominio suministra `payload`**. Firma **canonicaliza determinísticamente** y calcula SHA-256.
- **Requisitos de canonicalización** (deterministas y demostrables):
  - UTF-8; **orden determinista de claves**; arrays **preservan orden semántico**;
  - **fechas** en representación **explícita**; valores **monetarios/decimales** como **representación
    exacta** (string/decimal), **nunca floats binarios**;
  - `null`, boolean y strings **normalizados**;
  - **`schema`/`version` incluidos** en lo que se hashea.
- Se **conserva el snapshot canónico exacto** (para demostrar después qué contenido produjo el hash).
- Los **campos sustantivos exactos** los definirá `companypurchasing` (Fase 2D); **no** se introducen
  ahora en `companysignature`.

### D5 — Domain-agnostic (estricto) — CONFIRMADO
Prohibido dentro de `companysignature`: estados de compras · suppliers · cotizaciones · ítems de
compra · presupuestos · montos específicos · referencias hardcodeadas a `companypurchasing`.

---

## 13. Evidencia v1 (append-only) — campos mínimos

```
evidence_id · verification_token · workflow_instance_id · workflow_history/event_id ·
subject_type · subject_id · entity_id · document_version_id · content_sha256 ·
actor_user_id · actor_role/context · decision · timestamp UTC · timezone(presentación) ·
comentario · event_type
```
- `verification_token`: **opaco, aleatorio, no secuencial y NO derivado del hash**.
- **Append-only**: una invalidación **produce otro registro** (no `UPDATE` destructivo):
  ```
  APPROVAL → INVALIDATION (references evidence_id) → nueva versión → nueva APPROVAL
  ```

### Idempotencia del listener (workflow → evidencia)
El listener de `companyworkflow:transitioned` y `:approval_invalidated` tolera **retry · evento
duplicado · restart · doble entrega**. Se añade una **UNIQUE/idempotency key** = identidad estable
del evento de workflow **+** versión documental **+** tipo de evidencia. Un **replay nunca** produce
dos evidencias para la misma decisión.

### Verificación v1 (sólo interna autenticada)
`GET /plugins/companysignature/verify/{opaque_token}` con: **login · ACL · multi-entidad · no
revelar datos de otra entidad · token no enumerable · auditoría de verificación**. **No** hay
verificador anónimo/público en v1.

### Firma digital certificada
Sólo `CertifiedSignerInterface` + `NullSigner` (vacío). **Sin proveedor.** Una **imagen/dibujo de
firma NUNCA** se clasifica como firma digital certificada.

---

## 14. Tests obligatorios (además del gate)

Unit/Integration/E2E deben cubrir, como mínimo:
- misma representación lógica → **mismo hash**; cambio sustantivo → **hash diferente**;
- **orden de claves** no cambia el hash; **schema/version** **sí** participa del hash;
- **evento duplicado → una sola evidencia** (idempotencia);
- **PDF falla tras aprobación → evidencia permanece** y el PDF **puede reintentarse**;
- **invalidación conserva** la evidencia anterior; **invalidación repetida es idempotente**;
- **entidad A no verifica** evidencia de entidad B;
- **token inválido/inexistente no filtra** información;
- **versión nueva no modifica** snapshot/versión anterior;
- `CertifiedSignerInterface` **no se confunde** con evidencia interna.

---

## 15. Proceso (aprobado)

1. **Este PR (#9, docs-only):** registra el gate + estas decisiones D1–D5 en el propio gate y en la
   **adenda de ADR-0014**; corrige el estado de fases en `README`. **No** mezcla implementación.
2. Revalidar CI verde → **Ready for review → Squash & Merge** de PR #9.
3. Desde el nuevo `main`: rama **`claude/companysignature-impl`**. Implementar allí
   (`companysignature` v1 + la extensión genérica `invalidateApprovals()` de `companyworkflow`),
   con **Unit + Integration + E2E**, siguiendo la **Definition of Done** (código · migración
   reversible · ACL · i18n ES/EN · auditoría append-only · métricas/logs · tests · documentación ·
   changelog · **core intacto**).
4. **PR Draft independiente** para el código. **Sin auto-merge.** **Sin `companypurchasing`.**
   Detenerse con **CI verde** para revisión humana.
