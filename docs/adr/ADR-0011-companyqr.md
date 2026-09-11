# ADR-0011: `companyqr` — QR por activo, ficha segura y reporte de problema

- **Estado:** Aceptado (diseño); implementación pendiente de aprobación del diseño detallado.
- **Fecha:** 2026-09-11
- **Decisores:** Producto, seguridad, plataforma
- **Módulo/área:** `plugins/companyqr` (Fase 1)
- **Reemplaza/complementa:** ADR-0001 (arquitectura), ADR-0002 (core inmutable), ADR-0003 (estrategia de plugins), ADR-0010 (observabilidad/auditoría)

## Principio rector (regla formal de la plataforma)
> **El QR identifica; GLPI autoriza.**
> El QR (y su token) es un **identificador no enumerable**, **no** un secreto ni un
> mecanismo de autenticación/autorización. Poseer físicamente la etiqueta **no**
> concede acceso al activo. Toda información protegida depende **siempre** de
> **sesión + ACL de GLPI**. Este principio se fija desde el primer módulo porque la
> plataforma incorporará luego Compras, aprobaciones, firmas, inventario, tickets e IA.

Este enunciado deja de ser exclusivo de `companyqr` y pasa a ser una **regla
transversal** de toda la plataforma (identificador ≠ autorización), formalizada en
`../security/security-baseline.md` § "Identidad vs. autorización". Aplica a cualquier
módulo futuro que use tokens, enlaces o códigos.

> **El core de GLPI es una dependencia upstream, no vive en este repositorio.** Toda
> referencia a rutas `src/...`, `templates/...` o `composer.json` de este ADR apunta al
> repositorio oficial **`glpi-project/glpi`** en el tag **`11.0.8`**
> (`https://github.com/glpi-project/glpi/tree/11.0.8`), nunca a código de
> `DaltonP93/GLPI2`.

## Contexto
Necesitamos un QR por activo que, al escanearse, identifique el activo y —según los
permisos del usuario— muestre su ficha y permita **reportar un problema** creando un
**ticket vinculado al activo**, con **etiqueta física** imprimible (70,75 × 24 mm).

Análisis native-first sobre **GLPI 11.0.8** (verificado en el código fuente):

| Capacidad | Nativo | Evidencia (`glpi-project/glpi` @ `11.0.8`) | Decisión |
|---|---|---|---|
| Librería QR | ✅ `tecnickcom/tc-lib-barcode` + `BarcodeManager` (`generateQRCode`/`renderQRCode`) | `composer.json`, `src/BarcodeManager.php`, `templates/components/form/pictures.html.twig` | **Reuse** (vía `QrRenderer`) |
| Librería PDF | ✅ `tecnickcom/tcpdf` | `composer.json` | **Reuse** (vía `LabelRenderer`) |
| Página pública sin login | ✅ `#[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]` | varios controllers (`Form/RendererController`, `StatusController`…) | **Extend** (política en controlador) |
| Rutas de plugin (Symfony) | ✅ autoregistro por atributos, prefijo `/plugins/{key}` automático | `src/Glpi/Routing/PluginRoutesLoader.php` | **Build** controladores propios |
| Botón en formulario de activo | ✅ hook `post_item_form` | `Glpi\Plugin\Hooks::POST_ITEM_FORM`, `templates/components/form/buttons.html.twig` | **Extend** |
| Anti-bot | ✅ Altcha nativo | `src/Glpi/Controller/Altcha/ChallengeController.php` | **Reuse** (anónimo) |
| Ticket + vínculo a activo | ✅ `Ticket` + `Item_Ticket` | core estándar | **Reuse** (vía `TicketCreator`) |
| Nº de inventario visible | ✅ campo `otherserial` ("Inventory number") en todos los activos | `src/Monitor.php`, `src/Phone.php`, `src/Rack.php`, … | **Reuse** para el código visible |
| Forms → Ticket → activo asociado | ✅ `FormDestinationTicket` + `AssociatedItemsField`/`QuestionTypeItem` | `src/Glpi/Form/Destination/*` | ver spike (§ decisión) |
| **Precargar/lockear el activo escaneado en un Form** | ⚠️ no identificado en análisis estático | búsqueda `prefill` en `src/Glpi/Form` = 0 resultados (indicio, no prueba) | **Gate runtime en CI** decide Forms vs. propio (ver spike) |
| **Ficha pública segura (subset, sin datos técnicos)** | ❌ el QR nativo apunta a la URL **autenticada** del back-office | `BarcodeManager` codifica `getFormURLWithID` | **Build** (diferencial) |
| **Token/código público, ciclo de vida, auditoría/métricas, i18n** | ❌ | — | **Build** sobre APIs core |

**El gap real** es la **capa de identidad segura + privacidad + autoservicio**: el QR
nativo lleva a la URL autenticada del formulario admin (expone todo el activo a quien
tenga permisos y no sirve para autoservicio ni controla qué se muestra).

## Decisión
Construir el plugin **`companyqr`** que **reutiliza** toda la plomería nativa (QR, PDF,
rutas, hooks, tickets, Altcha, `otherserial`) y **construye** solo la capa diferencial,
con estos principios obligatorios:

1. **Ruta estándar autenticada (sin `NO_CHECK`).** El QR apunta por defecto a la ruta
   `GET /plugins/companyqr/scan/{token}` protegida con `SecurityStrategy(AUTHENTICATED)`:
   es el **firewall de GLPI** quien exige login y **preserva la URL de retorno** hacia la
   ficha. No se usa `NO_CHECK` para la ficha estándar (así nadie la vuelve pública por
   accidente). Flujo: `QR → /scan/{token} → login GLPI (con retorno) → ficha del activo
   según ACL`.
2. **Modo anónimo = ruta separada, apagada por defecto** (`anonymous_enabled = 0`). El
   acceso sin sesión vive **sólo** en `GET /plugins/companyqr/public/{token}`
   (`NO_CHECK`), y **únicamente** si un admin lo habilita. Muestra sólo un subset mínimo
   configurable (por defecto **código público + tipo + botón "Reportar problema"**).
   **Nunca** IP, MAC, hostname, VLAN, responsable, ubicación detallada ni datos técnicos.
3. **El token no es autenticación ni secreto**: sólo evita la enumeración trivial. La
   autorización real es **siempre sesión + ACL de GLPI**. Toda consulta protegida pasa por
   ACL nativa (perfil + entidad + `canViewItem`).
4. **Código visible propio y único.** El plugin gestiona su **propia** columna
   `public_code` (**`UNIQUE`**). Usa el número de inventario nativo `otherserial` (p. ej.
   `NB-001245` para notebooks, `PC-001245` para computadoras de escritorio) **cuando es un
   valor válido**; si no existe, **genera y almacena** un `public_code` propio. **Nunca
   escribe silenciosamente en `otherserial`** (no toca datos maestros del inventario). El
   `items_id` interno nunca se muestra ni se codifica en la URL pública.
5. **Token permanente + revocable** (sin expiración automática porque está impreso);
   soporta rotación/revocación manual y registra el estado del código.
6. **Privacidad/auditoría mínima**: sin hashes permanentes de IP/User-Agent por defecto.
   Rate limiting anónimo con almacenamiento temporal (cache, retención corta).
7. **Adaptadores propios** (`QrRenderer`, `LabelRenderer`, `AssetResolver`,
   `TicketCreator`, `AuditService`, `AccessPolicyService`) encapsulan las llamadas
   soportadas de GLPI; **controladores delgados**. Punto único de adaptación ante GLPI 12.
8. **Rutas modernas**: controladores en `plugins/companyqr/src/Controller/` (PSR-4) con
   rutas Symfony bajo `/plugins/companyqr/...`; **sin** `front/*.php` legacy salvo bloqueo
   documentado.
9. **Reporte de problema**: el **gate de Forms** corre como **test de integración en CI**
   (no pudo correr en la sesión de diseño por falta de Docker). v1 implementa un
   **formulario mínimo propio** vía `TicketCreator` (conservador, 100 % bajo control, no
   bloquea la entrega); si el gate runtime demuestra una vía **soportada y limpia** para
   previncular/lockear el activo en Forms nativo, se registra la evidencia y se abre un ADR
   de seguimiento para migrar el motor. Ver `../architecture/companyqr-forms-spike.md`.
10. **Etiqueta** default 70,75 × 24 mm horizontal (QR + código de inventario + tipo),
    configurable; impresión individual y diseño preparado para lote.

## Alternativas consideradas
- **Usar el QR nativo tal cual** → rechazado: URL autenticada del back-office, sin
  privacidad, sin autoservicio, sin control de datos mostrados.
- **Acceso anónimo por defecto** (que técnicamente GLPI permite) → **rechazado**: viola
  el principio "GLPI autoriza"; el anónimo queda como opción explícita y acotada.
- **Formulario propio desde el inicio, sin evaluar Forms** → rechazado: viola native-first.
  Se hace spike de Forms primero (ver `../architecture/companyqr-forms-spike.md`).
- **Librería QR/PDF propia** → rechazado: ya son nativas.

## Spike de Forms (resumen; detalle en `companyqr-forms-spike.md`)
- Nativo soporta `Form → FormDestinationTicket` y **asociar el ticket a un activo** vía
  `AssociatedItemsField` + `QuestionTypeItem`/`QuestionTypeUserDevice`.
- **No se identificó en el análisis estático** una API soportada/documentada para
  **precargar y bloquear el activo escaneado** desde el contexto del QR (la ausencia de
  `prefill` en `src/Glpi/Form` es un indicio, no una prueba). El **gate runtime** es el
  criterio definitivo.
- **Limitación de entorno:** sin Docker en la sesión de diseño, el gate corre como **test
  de integración de CI**.
- **Decisión v1:** **formulario mínimo propio** vía `TicketCreator` (conservador y bajo
  control); el gate de Forms queda en CI y, si demuestra una vía limpia y soportada, se
  documenta y se abre ADR de seguimiento para migrar el motor.

## Consecuencias
- (+) Reutiliza el máximo de capacidades nativas; superficie propia acotada al diferencial.
- (+) Seguridad correcta desde el diseño (identidad ≠ autorización).
- (+) Adaptadores aíslan el core → menor costo ante GLPI 12.
- (−) Requiere sincronizar el estado del código con el ciclo de vida del activo (hooks).
- (−) La decisión final Forms vs. formulario propio queda sujeta a un test runtime inicial.

## Hardening (revisión de PR #3, antes del merge)
Ajustes de seguridad/funcionales aplicados en la misma rama tras la revisión (todo dentro
del plugin, Regla 0 intacta):
1. **URL del formulario absoluta** (generada por el controlador), no relativa en Twig
   (evita `/scan/scan/...`). Cubierto por E2E HTTP.
2. **Altcha correcto**: `AltchaManager::getInstance()->verifySolution()` (instancia) +
   `removeChallenge()` anti-replay. Aislado en `AltchaVerifier` (testeable). Modo anónimo
   **experimental/OFF**; widget diferido → documentado **no soportado en v1**.
3. **ACL en mutaciones**: `rotate`/`revoke` exigen la **ACL nativa del activo**
   (`AccessPolicyService::canMutateCode()`), no sólo el bit `generate`. Un técnico de la
   entidad A no puede mutar un código de la entidad B conociendo `code_id` → 403.
4. **Ticket atómico/fail-closed**: si falla `Item_Ticket::add()` se revierte el ticket.
5. **Rate limit por actor**: bucket `HMAC(ip|token)` sólo en cache (no persiste IP);
   *fail-open* documentado si no hay cache (Altcha sigue obligatorio).
6. **`public_code` concurrente**: `createForItem` reintenta ante colisión UNIQUE y nunca
   devuelve un `Code` inválido.
7. **E2E HTTP real** en CI (`tests/e2e/companyqr-http.sh`): login → `/scan/{token}` →
   verifica URL del form → POST report → ticket vinculado (`Item_Ticket`).

## Cumplimiento de la Regla 0
Sólo plugin + hooks/controladores/API soportados. **Sin modificar el core.** Verificado
por `tests/upgrade/verify-core-untouched.sh` y CI.

## Compatibilidad
`requirements.glpi` min `11.0`, max `12.0` (**excluyente**: `>=11.0` y `<12.0`; GLPI 12
no soportado hasta suite de regresión). Ver `../architecture/glpi-version-compatibility.md`.
