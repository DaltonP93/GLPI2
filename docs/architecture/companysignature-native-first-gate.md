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

| # | Decisión | Recomendación |
|---|---|---|
| **D1** | ¿El PDF aprobado se almacena como **`Document` nativo** (+`Document_Item`) o como archivo del plugin? | **Document nativo** (reusa ACL/entidad/almacenamiento; más native-first). |
| **D2** | Contrato de **invalidación** con `companyworkflow`: ¿(a) reutilizar `transition()` con una transición de devolución ya definida, o (b) agregar `WorkflowApi::reopenApprovals()`? | Cerrar esto **primero**; probable **(b)** para una semántica "reabrir por cambio de contenido" independiente del actor. |
| **D3** | **QR**: ¿extraer un helper QR compartido (nueva lib común) o replicar el patrón mínimo en `companysignature`? | Replicar el patrón mínimo (evitar acoplar clases entre plugins); helper compartido = follow-up si se repite. |
| **D4** | **Lista exacta de campos sustantivos** que disparan invalidación. | Sign-off del dominio; congelar como spec versionada de canonicalización. |
| **D5** | En v1 **no existe `companypurchasing`** (Fase 2D, pendiente). ¿`companysignature` se valida contra un **itemtype neutro** en su selftest (como hizo `companyworkflow`) y `companypurchasing` será su primer integrador real después? | **Sí**: `companysignature` **domain-agnostic** en v1, validado con itemtype de prueba; integración real con compras = Fase 2D. |

---

## 13. Qué NO se hace en este PR

- **No** se implementa lógica de `companysignature` (ni tablas, ni servicios, ni controladores).
- **No** se implementa `companypurchasing` (Fase 2D).
- **No** se implementa proveedor de firma digital certificada (sólo el puerto, cuando se codifique v1).
- Este PR entrega **sólo** este gate + la corrección del estado de fases en `README.md`.

---

## 14. Siguiente paso

Aprobación humana de este gate (y de D1–D5). Recién entonces se implementa `companysignature` v1
siguiendo la **Definition of Done** (código · migración reversible · ACL · i18n ES/EN · auditoría
append-only · métricas/logs · tests Unit/Integration/E2E · documentación · changelog · **core
intacto**), en su rama, con PR Draft y CI verde para tu revisión.
