# ADR-0011: `companyqr` — QR por activo, ficha segura y reporte de problema

- **Estado:** Aceptado (diseño); implementación pendiente de aprobación del diseño detallado.
- **Fecha:** 2026-09-11
- **Decisores:** Producto, seguridad, plataforma
- **Módulo/área:** `plugins/companyqr` (Fase 1)
- **Reemplaza/complementa:** ADR-0001 (arquitectura), ADR-0002 (core inmutable), ADR-0003 (estrategia de plugins), ADR-0010 (observabilidad/auditoría)

## Principio rector
> **El QR identifica; GLPI autoriza.**
> El QR (y su token) es un **identificador no enumerable**, **no** un secreto ni un
> mecanismo de autenticación/autorización. Poseer físicamente la etiqueta **no**
> concede acceso al activo. Toda información protegida depende **siempre** de
> **sesión + ACL de GLPI**. Este principio se fija desde el primer módulo porque la
> plataforma incorporará luego Compras, aprobaciones, firmas, inventario, tickets e IA.

## Contexto
Necesitamos un QR por activo que, al escanearse, identifique el activo y —según los
permisos del usuario— muestre su ficha y permita **reportar un problema** creando un
**ticket vinculado al activo**, con **etiqueta física** imprimible (70,75 × 24 mm).

Análisis native-first sobre **GLPI 11.0.8** (verificado en el código fuente):

| Capacidad | Nativo | Evidencia (glpi-project/glpi @ 11.x) | Decisión |
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
| **Precargar/lockear el activo escaneado en un Form** | ❌ sin mecanismo `prefill` | búsqueda `prefill` en `src/Glpi/Form` = 0 resultados | **Build** flujo propio (ver spike) |
| **Ficha pública segura (subset, sin datos técnicos)** | ❌ el QR nativo apunta a la URL **autenticada** del back-office | `BarcodeManager` codifica `getFormURLWithID` | **Build** (diferencial) |
| **Token/código público, ciclo de vida, auditoría/métricas, i18n** | ❌ | — | **Build** sobre APIs core |

**El gap real** es la **capa de identidad segura + privacidad + autoservicio**: el QR
nativo lleva a la URL autenticada del formulario admin (expone todo el activo a quien
tenga permisos y no sirve para autoservicio ni controla qué se muestra).

## Decisión
Construir el plugin **`companyqr`** que **reutiliza** toda la plomería nativa (QR, PDF,
rutas, hooks, tickets, Altcha, `otherserial`) y **construye** solo la capa diferencial,
con estos principios obligatorios:

1. **Autenticado por defecto.** Flujo normal:
   `QR → resolución del token → login si no hay sesión → ficha del activo según ACL`.
2. **Modo anónimo = opcional, apagado por defecto.** Si se habilita, muestra sólo un
   subset mínimo configurable (por defecto **código público + tipo + botón "Reportar
   problema"**). **Nunca** IP, MAC, hostname, VLAN, responsable, ubicación detallada ni
   datos técnicos.
3. **El token no es autenticación ni secreto**: sólo evita la enumeración trivial. La
   autorización real es **sesión + ACL de GLPI**.
4. **Código visible = número de inventario** (`otherserial`, p. ej. `PC-001245`), nunca
   el `items_id` interno. Con estrategia de fallback y validación de unicidad.
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
9. **Reporte de problema**: primero intentar **Forms nativo** (spike gate); si no permite
   vincular el activo escaneado de forma soportada, construir formulario mínimo propio.
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
- **No hay `prefill`** en `src/Glpi/Form`: no se evidencia una vía soportada para
  **precargar/lockear el activo escaneado** desde el contexto del QR (saldría de una
  pregunta que el usuario responde a mano).
- **Limitación de entorno:** sin Docker en esta sesión, no se pudo confirmar en runtime.
- **Conclusión provisional:** la evidencia inclina a **formulario mínimo propio** para
  garantizar el vínculo al activo exacto; se ejecutará un **test de integración gate** al
  inicio de la implementación para confirmar/derogar Forms, y se documentará el resultado
  aquí antes de construir el formulario. Si Forms resultara viable de forma limpia y
  soportada, se reutiliza.

## Consecuencias
- (+) Reutiliza el máximo de capacidades nativas; superficie propia acotada al diferencial.
- (+) Seguridad correcta desde el diseño (identidad ≠ autorización).
- (+) Adaptadores aíslan el core → menor costo ante GLPI 12.
- (−) Requiere sincronizar el estado del código con el ciclo de vida del activo (hooks).
- (−) La decisión final Forms vs. formulario propio queda sujeta a un test runtime inicial.

## Cumplimiento de la Regla 0
Sólo plugin + hooks/controladores/API soportados. **Sin modificar el core.** Verificado
por `tests/upgrade/verify-core-untouched.sh` y CI.

## Compatibilidad
`requirements.glpi` min `11.0`, max `12.0` (**excluyente**: `>=11.0` y `<12.0`; GLPI 12
no soportado hasta suite de regresión). Ver `../architecture/glpi-version-compatibility.md`.
