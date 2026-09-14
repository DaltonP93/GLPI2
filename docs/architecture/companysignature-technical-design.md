# Diseño técnico — `companysignature`

Complementa `../adr/ADR-0014-companysignature.md`. **Diseño, sin implementación.** Reutiliza
identidad/sesión/`Log`/TCPDF nativos. **Sin tocar el core.**

## Estructura (PSR-4 `GlpiPlugin\Companysignature\`)
```
plugins/companysignature/
  setup.php  hook.php
  src/
    Model/       ApprovalEvidence · DocumentVersion
    Service/     EvidenceRecorder · Canonicalizer · Hasher · VerificationService · ApprovedPdfComposer · CertifiedSignerInterface(+NullSigner)
    Controller/  VerifyController (GET /verify/{code} — muestra validez respetando ACL)
  templates/     approved_document.html.twig · verify.html.twig
  locales/ tests/
```

## 1. Evidencia de aprobación electrónica interna
Tabla `glpi_plugin_companysignature_evidences` (**append-only**):

| Columna | Notas |
|---|---|
| `id` | PK |
| `itemtype`,`items_id` | objeto aprobado (p. ej. una solicitud de compra) |
| `document_version` | versión del contenido aprobado |
| `content_hash` | **SHA-256** de la representación **canónica** del contenido aprobado |
| `users_id` | identidad **autenticada** que aprueba |
| `profiles_id`,`role` | perfil/rol efectivo |
| `entities_id` | entidad (aislamiento) |
| `decision` | approved / rejected / returned |
| `comment` | comentario |
| `date` | fecha/hora |
| `timezone` | `America/Asuncion` |
| `workflow_instances_id`,`transition` | vínculo al circuito (`companyworkflow`) |
| `verification_code` | código opaco para verificación pública-interna |

- **Identidad = sesión nativa** (`Session::getLoginUserID()`); nunca una imagen de firma.
- Se escribe además una entrada en **`Log`** nativo para el historial del ítem.
- **Una imagen PNG de firma NO es evidencia**; a lo sumo, decorado del PDF.

## 2. Canonicalización y hash (reproducible)
`Canonicalizer` construye una **representación canónica** del *contenido aprobado* (no del PDF
renderizado, que lleva metadatos volátiles): p. ej. JSON ordenado y normalizado con
`{number, items[], amounts, currency, selected_supplier, approvers_hasta_aquí, version}`.
`Hasher` = `hash('sha256', canonical)`. Así el hash es **verificable** y estable: recomputar la
canónica del contenido debe dar el mismo `content_hash` registrado.

## 3. Versionado documental e invalidación de aprobaciones
- `glpi_plugin_companysignature_document_versions`: `id, itemtype, items_id, version,
  canonical_hash, date, users_id_author, is_substantive(bool)`.
- Cada **edición** del objeto crea (o marca) una **versión**. Los campos **sustantivos**
  (ítems, cantidades, montos, proveedor seleccionado, categoría) están declarados; cambiarlos
  ⇒ `is_substantive = 1`.
- **Regla de invalidación:** si tras una o más aprobaciones se produce una edición
  **sustantiva**, `companysignature` notifica a `companyworkflow` para **retroceder/reabrir**
  las etapas de aprobación afectadas (registrando `approval_invalidated` en el historial
  append-only; **no** se borra evidencia previa). Cambios **no sustantivos** (una observación)
  se configuran como **no invalidantes**.
- Regla resumida: *aprobás una versión; si cambia el contenido aprobado, esas aprobaciones se
  reinician.*

## 4. Documento aprobado (PDF versionado)
`ApprovedPdfComposer` (TCPDF, como `companyqr`) genera el PDF final con, al menos:
`número · solicitante · área/entidad · ítems · importes · **PYG** · cotizaciones seleccionadas ·
aprobadores (rol + fecha/hora) · resultado · fecha/hora · historial resumido · **hash del
contenido** · **código/URL de verificación** · **QR de verificación interno**`.
- El **QR** codifica la URL de verificación (`/plugins/companysignature/verify/{code}`),
  reutilizando el `QrRenderer` de la plataforma (patrón `companyqr`).
- El PDF lleva impresa la **versión** y su **hash**; si el contenido cambió, un PDF nuevo
  corresponde a una versión nueva.

## 5. Verificación
`VerificationService` + `VerifyController` (GET): dado `verification_code`, recomputa la
canónica de la versión referida y **confirma que coincide** con `content_hash`; muestra
quién/cuándo aprobó **respetando ACL/entidad** (identidad mínima necesaria). Principio heredado:
**el código identifica; GLPI autoriza** lo que se muestra.

## 6. Firma digital certificada (adaptador futuro)
`CertifiedSignerInterface { sign(payload): SignatureResult; verify(sig): bool }` con un
`NullSigner` por defecto (no-op documentado). **No** se elige proveedor ni se implementa PKI en
Fase 2; sólo queda el **puerto** para integrar cuando negocio/legal lo exijan (ADR de
seguimiento).

## Plan de tests (tras aprobación)
- **Unit (puro):** canonicalización determinista (mismo contenido ⇒ mismo hash; orden de claves
  irrelevante); detección de cambio **sustantivo** vs. no sustantivo; verificación (hash
  coincide / no coincide).
- **Integración (fail-closed):** evidencia append-only (no se borra); edición sustantiva tras
  aprobación ⇒ etapas reabiertas; PDF aprobado contiene hash+QR y **sin** datos fuera de ACL.
- **E2E HTTP:** aprobar → generar PDF → `/verify/{code}` confirma validez; editar contenido ⇒
  verificación refleja invalidación.
