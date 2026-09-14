# Plan de pruebas — integración Snipe-IT (SI-1 y mandatorias)

**Diseño, sin implementación.** Mismo rigor de 3 niveles que Fase 1 (unit · integración
fail-closed · E2E). Las pruebas de integración usan un **Snipe-IT sandbox** o un **stub HTTP**
del cliente (nunca la DB de Snipe).

## Unit (puro, sin GLPI ni red)
- **Correlación/idempotencia:** `idempotency_key` bien formada; buscar-o-crear no duplica.
- **Cliente:** cálculo de backoff; el **circuit breaker** abre/cierra según umbral; redacción de
  **secretos** en logs (un token nunca aparece en el log serializado).
- **Mapeo:** resolución por **ID** (no por nombre); mapeo no aprobado no se usa para crear.
- **QR/gateway:** construcción de la URL del gateway a partir del `asset_tag`; el `asset_tag` no
  es autorización (sólo identifica).

## Integración (fail-closed; contra sandbox/stub)
- 🔒 **Mapping único:** un mismo `asset_tag` **no** puede mapear a dos activos (UNIQUE) ni un
  activo GLPI a dos Snipe.
- 🔒 **Idempotencia por unidad:** una línea con cantidad **N** produce **N** activos (uno por
  `receipt_unit`), cada uno con su serial/Snipe/GLPI/bridge; **reintentar** con la misma
  `purchase:<req>:item:<line>:unit:<n>` **no** crea un activo extra (ni en Snipe ni en GLPI).
- 🔒 **Compañía/entidad:** un activo Snipe cuya `company` **no** está mapeada a una entidad GLPI
  → queda `company_unmapped`/`pending` y **no** se sincroniza a una entidad inferida (obligatorio,
  multi-entidad).
- 🔒 **Dedup con GLPI Agent:** si ya existe un activo GLPI (por serial/UUID) → **vincular**, no
  duplicar; match **ambiguo** → `conflict`, **sin** auto-merge.
- 🔒 **Serial:** divergencia Snipe↔GLPI → `serial_conflict` reportado; **nunca** sobreescritura
  silenciosa.
- 🔒 **Identidad de usuarios:** **no** se correlacionan usuarios automáticamente por nombre/email;
  sólo por `map_users` aprobado.
- 🔒 **Sin DB directa:** la integración sólo usa endpoints HTTP (verificado por diseño/lint del
  cliente; ninguna conexión a la DB de Snipe).
- 🔒 **Sin secretos en logs:** ningún token/credencial aparece en logs/auditoría.
- 🔒 **Resiliencia:** si **Snipe no responde**, GLPI/tickets siguen operativos (circuit breaker);
  si **GLPI falla**, el evento queda **pendiente/reintentable** (dead-letter), sin pérdida.
- 🔒 **Multi-entidad:** un usuario/entidad A no recibe datos de activos de la entidad B.
- 🔒 **No fuga:** la integración **nunca** copia IP/MAC/hostname/VLAN a etiquetas ni al portal.
- **Reconciliación read-only (SI-1):** clasifica correctamente `mapped/orphan_snipe/orphan_glpi/
  conflict` y **no** modifica datos.

## E2E (SI-1)
- **Gateway QR:** `URL de etiqueta (asset_tag) → login/ACL GLPI → ficha segura companyqr →
  Reportar problema → ticket vinculado al activo GLPI` (reutiliza el E2E de Fase 1).
- **Etiqueta física:** generar por el motor de Snipe una etiqueta **70,75×24 mm amarilla** con
  **QR + asset tag + tipo** y **sin** datos técnicos; el QR apunta al gateway.

## Mandatorias (checklist del usuario)
- [ ] mapping único · [ ] idempotencia · [ ] no acceso DB directo · [ ] ningún secreto en logs ·
  [ ] falla Snipe no tumba GLPI · [ ] falla GLPI deja evento pendiente/reintentable ·
  [ ] multi-entidad respetada · [ ] QR exige GLPI ACL · [ ] no fuga IP/MAC/hostname/VLAN ·
  [ ] label física correcta · [ ] no duplicar activo al reintentar ·
  [ ] **idempotencia por unidad (N unidades → N activos)** · [ ] **compañía no mapeada → conflicto
  (no entidad inferida)** · [ ] **dedup GLPI Agent (resolver-o-crear; ambiguo → conflicto)** ·
  [ ] **serial: divergencia → conflicto, sin sobreescritura** · [ ] **usuarios no correlacionados
  por nombre**.
- **SI-4 (precisión final):** [ ] recepción parcial 4+6 de una línea de 10 · [ ] reintento tras
  `SNIPE_CREATED` sin duplicar · [ ] cambio de `asset_tag` sin romper QR existente · [ ] dos
  unidades con costos correctos por línea · [ ] fallo tras crear Snipe antes de vincular GLPI ·
  [ ] `receipt_unit_uuid` estable aunque cambie un dato visible del documento.

## Tests de diseño obligatorios (SI-4 — precisión final; a implementar en su fase)
Estos seis casos **quedan comprometidos por diseño** y son requisito de la fase de recepción
(SI-4). No se implementan ahora; se documentan como contrato de prueba:

1. 🔒 **Recepción parcial 4 + 6 de una línea de 10.** Una línea `qty=10` recibida en dos lotes
   (4 y luego 6) crea **exactamente 10** `receipt_unit` (10 activos), con `received_qty` 4→10 y
   `pending_qty` 6→0; **ningún** duplicado; reenviar un lote no crea unidades extra.
2. 🔒 **Reintento tras `SNIPE_CREATED` sin duplicar.** Si el proceso falla después de crear el
   activo en Snipe pero antes de `BRIDGED`, el reintento **resume** desde `last_confirmed_step` y
   **vincula** el `snipe_asset_id` ya persistido — **no** crea un segundo activo Snipe.
3. 🔒 **Cambio de `asset_tag` sin romper el QR existente.** Tras renombrar el `asset_tag` en Snipe,
   una etiqueta física impresa con el tag **viejo** **sigue resolviendo** al mismo activo (alias
   histórico); el gateway resuelve tag actual **e** histórico.
4. 🔒 **Dos unidades con costos correctos por línea.** Dos unidades de líneas distintas reciben
   cada una su `unit_cost` **derivado de su propia línea** (no el total general prorrateado);
   `Infocom` de cada activo refleja el costo atribuible correcto.
5. 🔒 **Fallo tras crear en Snipe pero antes de vincular GLPI.** La unidad queda en
   `SNIPE_CREATED`/`RETRYABLE_ERROR` (fail-closed) con el `snipe_asset_id` guardado; al reintentar,
   completa `GLPI_RESOLVED_OR_CREATED`→`BRIDGED` **sin** recrear en Snipe ni en GLPI.
6. 🔒 **`receipt_unit_uuid` estable aunque cambie un dato visible del documento.** Cambiar un dato
   visible de la solicitud/línea (p. ej. renumeración de líneas, reimpresión) **no** altera el
   `receipt_unit_uuid` ni reasigna identidad; la correlación humana puede cambiar, el UUID no.

## Fuera de alcance de SI-1 (fases posteriores)
checkout/checkin sync (SI-2) · labels masivas completas (SI-3) · compras→activo (SI-4) ·
aceptación/firma física (SI-5) · consumibles/licencias · IA · WhatsApp.
