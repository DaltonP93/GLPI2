# ADR-0024: companyqr — impresión masiva de etiquetas QR (Acción masiva nativa)

- **Estado:** Propuesto (implementado en companyqr 0.4.0; pendiente de revisión humana)
- **Fecha:** 2026-10-06
- **Decisores:** Producto, TI/activos, seguridad, plataforma
- **Módulo/área:** `plugins/companyqr` (0.3.0 → 0.4.0)
- **Complementa:** ADR-0011 (companyqr: "el QR identifica; GLPI autoriza"), ADR-0022 (API pública, "Pendiente: impresión
  masiva").

## Contexto
Hoy cada etiqueta se imprime de a una, desde el formulario del activo (`GET /label/{code_id}`). Para etiquetar un lote
(un inventario inicial, una sucursal nueva, una compra grande) hay que abrir activo por activo. ADR-0022 dejó la
impresión masiva como pendiente.

### Hechos verificados (código del repositorio y GLPI **11.0.8**)
| # | Hecho | Evidencia |
|---|---|---|
| 1 | GLPI ofrece **Acciones masivas** sobre cualquier listado de búsqueda. Un plugin agrega las suyas con `$PLUGIN_HOOKS['use_massive_action']` + la función `plugin_<p>_MassiveActions($itemtype)` | `src/MassiveAction.php::getAllMassiveActions()`, `src/Glpi/Plugin/Hooks.php` (`USE_MASSIVE_ACTION`, `AUTO_MASSIVE_ACTIONS`) |
| 2 | La clave de la acción es `Clase:accion`; GLPI llama a `Clase::showMassiveActionsSubForm()` y a `Clase::processMassiveActionsForOneItemtype()` | `MassiveAction.php` (`CLASS_ACTION_SEPARATOR`, `showSubForm`, `processForSeveralItemtypes`) |
| 3 | Al terminar, `front/massiveaction.php` redirige a `$ma->setRedirect(url)` (por defecto, la página anterior) y muestra el resumen ok/ko/sin permiso | `front/massiveaction.php`, `MassiveAction::setRedirect()` |
| 4 | Si el proceso tarda más de 5 s, `itemDone()` **recarga la página** y los ítems no marcados como hechos se vuelven a procesar. `$ma->POST` se conserva entre recargas | `MassiveAction::itemDone()` |
| 5 | `LabelRenderer` usa TCPDF (nativo de GLPI); `LabelComposer::spec()` arma el contenido sin datos técnicos ni token visible | `companyqr/src/Service/LabelRenderer.php`, `LabelComposer.php` |
| 6 | Ningún listado nativo de GLPI 11 imprime etiquetas QR con nuestra URL de escaneo autenticada | `docs/architecture/glpi11-capability-matrix.md` |

## Decisión (Configure → Existing → **Extend** → Integrate → Build)
**Extender companyqr con una Acción masiva nativa "Imprimir etiquetas QR"**, que reutiliza el renderer y las reglas de
ACL existentes.

1. **Dónde aparece.** En el listado de cualquier tipo de `$CFG_GLPI['asset_types']`, sólo si la sesión tiene
   `plugin_companyqr` → `print`. No se crea pantalla nueva.
2. **Formulario de la acción.** Un único botón. Si la sesión además tiene `generate`, se ofrece la casilla
   "Generar los códigos que falten" (apagada por defecto).
3. **Proceso por activo (fail-closed):**
   - activo inexistente o no visible (`canViewItem`) ⇒ **sin permiso**;
   - sin código ⇒ se genera sólo si se marcó la casilla y hay `generate` (`CodeManager::getOrCreateForItem`); si no,
     **falla** con mensaje;
   - código no ACTIVO (suspendido/revocado) ⇒ **falla**; nunca se reactiva ni se rota;
   - pasado el tope del lote ⇒ **falla** con mensaje (no se trunca en silencio).
   La decisión es una función pura (`BulkLabelService::decide`) con tests unitarios.
4. **Lote en sesión, no en BD.** Los `code_id` aceptados se guardan en `$_SESSION` bajo una clave aleatoria de 128 bits
   (`LabelBatch`), atada al usuario, con vencimiento de 15 min, máximo 5 lotes vivos por sesión y sin duplicados.
   La clave del lote viaja en `$ma->POST`, así la recarga de GLPI (hecho 4) agrega al mismo lote en vez de crear otro.
5. **PDF.** `GET /plugins/companyqr/labels/{batch}` (AUTHENTICATED + `print`) **revalida** cada código al imprimir
   (existe, ACTIVO, activo visible) y devuelve **un PDF de varias páginas, una etiqueta por página**, del mismo tamaño
   que la etiqueta individual (apto para impresora de etiquetas). `LabelRenderer::pdf()` pasa a ser
   `pdfMany([una])`: la etiqueta individual no cambia.
6. **Tope configurable.** `label_batch_max` (default 200, se acota a 1–500). install() sólo siembra la clave si falta.
7. **Auditoría.** Una fila por etiqueta impresa en `glpi_plugin_companyqr_scans` (`result = label_printed`,
   `channel = batch`, actor de la sesión, sin IP/User-Agent). **Log** de una línea por lote en `files/_log/companyqr.log`
   (cantidades y usuario; nunca tokens).

## Alternativas consideradas
- **Plugin existente "Barcode" (comunidad).** Imprime QR/códigos de barras desde Acciones masivas, pero con su propio
  contenido (no nuestra URL `/scan/{token}`), sin el ciclo de vida del código (rotar/revocar) ni la ACL de companyqr.
  Sería un segundo sistema de QR. Rechazado.
- **Hoja A4 con varias etiquetas por página.** Útil para impresoras comunes, pero exige configurar grilla, márgenes y
  hojas de etiquetas. Se deja para un ADR posterior; v1 mantiene el mismo formato físico que hoy.
- **Lote en tabla propia.** Daría lotes compartibles y reimprimibles días después, a costa de esquema, purga y
  migración. Un lote es efímero y personal: la sesión alcanza.
- **Descargar el PDF directo desde el proceso masivo.** El proceso de GLPI emite HTML de progreso antes de procesar;
  no puede devolver un PDF. Por eso se redirige a la ruta del lote.

## Consecuencias
- (+) Etiquetar N activos es seleccionar en el listado nativo y una acción. Mismo PDF, misma ACL, mismo contenido.
- (+) Sin cambio de esquema: upgrade 0.3.0 → 0.4.0 sólo agrega una clave de configuración si falta.
- (−) El lote vence a los 15 min y muere con la sesión: para reimprimir se repite la acción.
- (−) Una página por etiqueta: para impresoras A4 hace falta el formato en grilla (pendiente).
- i18n ES/EN para toda cadena nueva.

## Cumplimiento de la Regla 0
Sólo hooks y API soportados (`use_massive_action`, `MassiveAction`, `CommonDBTM`, `Session`, `Config`, rutas de
plugin de GLPI 11, TCPDF nativo). Sin cambios en el core ni en `vendor/`, sin SQL contra tablas del core. Verificado por
`tests/upgrade/verify-core-untouched.sh`.
