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
- 🔒 **Idempotencia:** reintentar el mismo evento (misma `idempotency_key`) **no** crea dos
  activos ni dos checkouts.
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
  [ ] label física correcta · [ ] no duplicar activo al reintentar.

## Fuera de alcance de SI-1 (fases posteriores)
checkout/checkin sync (SI-2) · labels masivas completas (SI-3) · compras→activo (SI-4) ·
aceptación/firma física (SI-5) · consumibles/licencias · IA · WhatsApp.
