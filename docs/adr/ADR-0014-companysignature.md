# ADR-0014: `companysignature` — aprobación electrónica interna y evidencia

- **Estado:** Aceptado (diseño); implementación pendiente de aprobación humana.
- **Fecha:** 2026-09-14
- **Decisores:** Producto, legal/cumplimiento, seguridad, plataforma
- **Módulo/área:** `plugins/companysignature` (Fase 2)
- **Complementa:** ADR-0007 (firma electrónica vs. digital), ADR-0012, ADR-0013, ADR-0010

## Contexto
El circuito de compras exige **evidencia de aprobación** con valor interno. Debemos distinguir
con rigor:

- **Aprobación electrónica interna:** identidad autenticada + acción explícita + auditoría +
  **hash del contenido aprobado** + timestamp. Es lo que Fase 2 necesita.
- **Firma digital certificada** (criptográfica, con certificado/PKI): integración **separada**,
  cuando negocio/legal la exijan.

**Una imagen PNG de una firma NO es una firma digital** y no debe tratarse como tal.

## Decisión
Construir **`companysignature`** con dos capas claramente separadas:

### 1. Evidencia de aprobación electrónica interna (v1)
Registro **append-only** por cada aprobación/decisión que capture, como mínimo:
`identidad autenticada · users_id · rol/perfil · entidad · decisión · fecha/hora · timezone
(America/Asuncion) · versión del documento · hash del contenido aprobado (SHA-256) ·
comentario · workflow/instancia y transición relacionada · evidencia de auditoría`.

- El **hash** se calcula sobre una **representación canónica** del contenido aprobado (no sobre
  el PDF renderizado con metadatos volátiles), de modo que sea reproducible y verificable.
- Reutiliza la **identidad y sesión nativas** de GLPI (usuario autenticado) y `Log`.
- Se expone una **verificación**: dado un código/URL, confirmar que el hash coincide con el
  contenido y mostrar quién/cuándo aprobó (respetando ACL).

### 2. Adaptador de firma digital certificada (interfaz futura)
Sólo una **interfaz/puerto** (`CertifiedSignerInterface`) sin proveedor elegido: métodos para
`sign(document): SignatureResult` / `verify(...)`. **No** se implementa proveedor en Fase 2;
se documenta el punto de integración para cuando exista requerimiento legal.

## Versionado documental e invalidación de aprobaciones
- El documento de la solicitud es **versionado**. Cada aprobación referencia una **versión y su
  hash**.
- **Regla:** una modificación posterior a una aprobación que cambie el **contenido aprobado**
  (ítems, montos, proveedor seleccionado…) **invalida/reinicia** las aprobaciones afectadas
  (el motor `companyworkflow` retrocede las etapas que corresponda). Cambios no sustantivos
  (p. ej. una observación) se configuran como no invalidantes. Detalle en
  `../architecture/companysignature-technical-design.md`.

## Alternativas consideradas
- **Imagen de firma dibujada** → **rechazada** como evidencia legal; a lo sumo, adorno visual
  del PDF, nunca la prueba (la prueba es identidad+hash+timestamp+auditoría).
- **Firma digital certificada ya en v1** → aplazada: requiere proveedor/PKI y decisión legal;
  se deja el adaptador.
- **Confiar sólo en `Log` nativo** → insuficiente: `Log` no fija hash de contenido versionado
  ni el concepto de "aprobación que se invalida al cambiar el contenido".

## Consecuencias
- (+) Evidencia sólida y verificable sin sobre-prometer "firma digital".
- (+) Base lista para certificar en el futuro sin rediseño (puerto/adaptador).
- (−) Exige definir bien la **canonicalización** del contenido para hashes reproducibles.
- (−) La invalidación por cambios acopla firma ↔ workflow (se diseña explícitamente).

## Cumplimiento de la Regla 0
Sólo plugin + identidad/sesión/Log/TCPDF nativos. **Sin modificar el core.**

## Compatibilidad
`requirements.glpi` min `11.0`, max `12.0` (excluyente).

## Adenda (2026-09-16) — decisiones D1–D5 aprobadas
Tras el **gate native-first** (`../architecture/companysignature-native-first-gate.md`, §12–§15),
se aprueban estas definiciones que rigen la implementación (detalle completo en el gate):

- **D1 (PDF nativo, inmutable):** cada versión = `document_version → canonical_snapshot →
  content_sha256 → PDF Document`, sin sobrescribir PDFs previos. Se guardan **dos hashes**:
  `content_sha256` (representación canónica aprobada) y `pdf_sha256` (integridad del artefacto). El
  PDF es **derivado**: si falla su alta como `Document` tras registrar la evidencia, la aprobación
  **no desaparece ni se repite** (artefacto `pending/error`, **regeneración idempotente**).
- **D2 (invalidación explícita):** se **extiende `companyworkflow`** con una operación genérica
  `invalidateApprovals(instanceId, reason, context, expectedVersion)` — domain-agnostic, fail-closed,
  con control de concurrencia, idempotente; invalida según la política del workflow, reabre el
  checkpoint configurado, audita append-only y **emite `companyworkflow:approval_invalidated`**.
  `companysignature` **consume** el evento y registra una **nueva evidencia** (jamás borra/edita
  evidencia histórica). **Sin referencias a Compras** en la extensión.
- **D3 (QR):** adapter propio `VerificationQrRenderer` (reusa la lib QR de GLPI, apunta al endpoint
  de verificación de Firma); **sin dependencia funcional de `companyqr`**.
- **D4 (contenido sustantivo):** contrato **domain-agnostic** de snapshot canónico
  `{schema, subject_type, subject_id, entity_id, document_version, payload}`; el dominio provee
  `payload`; Firma **canonicaliza determinísticamente** (UTF-8, orden de claves determinista, arrays
  con orden semántico, fechas explícitas, decimales/monetarios exactos —no floats—, `schema/version`
  hasheados) y calcula SHA-256, **conservando el snapshot canónico exacto**. Los campos sustantivos
  concretos los define `companypurchasing` (Fase 2D).
- **D5 (domain-agnostic estricto):** prohibido en `companysignature` todo lo de compras (estados,
  suppliers, cotizaciones, ítems, presupuestos, montos, referencias a `companypurchasing`).

**Evidencia v1** append-only con `verification_token` **opaco/aleatorio/no secuencial/no derivado
del hash**; una invalidación **produce otro registro** (sin `UPDATE` destructivo). **Idempotencia**
por UNIQUE key = identidad estable del evento de workflow + versión documental + tipo de evidencia.
**Verificación v1** sólo **interna autenticada** (login+ACL+multi-entidad, token no enumerable).
**Firma digital certificada**: sólo `CertifiedSignerInterface`+`NullSigner` (una imagen de firma
**nunca** es firma digital certificada).
