# Funcional — `companyqr` (QR por activo, ficha segura, reporte)

Principio rector: **el QR identifica; GLPI autoriza.** Ver `../adr/ADR-0011-companyqr.md`.

## Política autenticado / anónimo

| Situación | Ruta | Comportamiento |
|---|---|---|
| **Estándar (la que codifica el QR)** | `GET /plugins/companyqr/scan/{token}` — **`AUTHENTICATED`** | El **firewall de GLPI** exige login y **preserva la URL de retorno**. Con sesión → ficha del activo **filtrada por ACL nativa** del usuario. **No** es una ruta pública. |
| **Modo anónimo (ruta separada, OFF por defecto)** | `GET /plugins/companyqr/public/{token}` — `NO_CHECK` | Sólo si un admin habilita `anonymous_enabled`. Sin sesión muestra **sólo** un subset mínimo configurable (**por defecto: código público + tipo + botón "Reportar problema"**). Con `anonymous_enabled=0` responde 404/redirect a login. |
| **Nunca en modo anónimo** | — | IP, MAC, hostname, VLAN, responsable/usuario, ubicación detallada, serie ni datos técnicos. |

- **No se usa `NO_CHECK` para la ficha estándar**: así nadie la vuelve pública por error.
- El token **no** autentica ni autoriza; sólo evita enumeración. La visibilidad de datos
  del activo es **siempre** la ACL nativa de GLPI (perfil + entidad + `canViewItem`).
- El modo anónimo se activa por configuración global (`anonymous_enabled=0` por defecto), y
  vive en su **propia ruta**; su subset de campos es configurable, con el default mínimo.
- **Compensación aceptada:** el QR estándar apunta a la ruta autenticada. Si se quisiera un
  set de etiquetas de acceso anónimo, se imprimirían apuntando a `/public/{token}`
  (decisión deliberada). Se prioriza claridad de seguridad sobre comodidad del toggle.

## Código visible del activo
- El plugin gestiona una columna **propia y única** `public_code` (restricción `UNIQUE` en
  `glpi_plugin_companyqr_codes`). **Nunca escribe en `otherserial`** (no modifica datos
  maestros del inventario).
- **Fuente primaria:** si el número de inventario nativo `otherserial` es válido (no vacío
  y sin conflicto de unicidad), `public_code` **copia** ese valor. Nomenclatura esperada:
  `NB-xxxxxx` = **notebook**, `PC-xxxxxx` = **computadora de escritorio** (desktop);
  `MON-` monitor, `IMP-` impresora, `NET-` equipo de red, etc.
- **Fallback** (si `otherserial` está vacío o duplicado): el plugin **genera y almacena**
  `<PREFIJO_POR_TIPO>-<secuencia>` en su propia tabla; la secuencia vive en el plugin
  (**no** es el `items_id`). El prefijo por `itemtype` es configurable (`code_prefix_map`).
- **Unicidad:** `public_code` es `UNIQUE`; si `otherserial` viniera duplicado, se marca el
  conflicto y se usa el fallback generado hasta resolverlo (a mano, en el inventario).
- El `items_id` interno **nunca** se muestra ni se codifica en la URL pública.

## Ficha al escanear
- **Autenticada:** se renderiza según la ACL del usuario; puede enlazar al formulario
  nativo del activo si tiene permiso. Acciones: "Reportar problema" (prellenado).
- **Anónima (si habilitada):** sólo el subset mínimo + "Reportar problema".
- La ficha se arma con `AssetResolver`, que expone **únicamente** un conjunto seguro de
  campos (whitelist), nunca campos técnicos sensibles.

## Reporte de problema → ticket vinculado al activo
- Crea un `Ticket` y lo **vincula al activo** con `Item_Ticket` (vía `TicketCreator`).
- **Invariante fail-closed:** *un ticket por QR sin activo vinculado = operación fallida*.
  Si `Item_Ticket::add()` falla, el ticket recién creado se **revierte** (compensación) y
  la operación devuelve error; nunca se reporta "éxito" con un ticket huérfano.
- Categoría, urgencia y entidad **por configuración** (sin hardcode); canal/origen = `QR`.
- **Autenticado:** solicitante = usuario en sesión (URL de acción **absoluta**, generada por
  el controlador; CSRF nativo).
- **Anónimo:** **experimental y OFF por defecto** (`anonymous_enabled=0`). El servidor exige
  **Altcha (verificación de instancia `AltchaManager::getInstance()->verifySolution()` +
  `removeChallenge()` anti-replay)** y **rate limiting por actor** (bucket
  `HMAC(ip|token)` sólo en cache, sin persistir IP). El **widget** Altcha en la ficha pública
  queda **diferido a un follow-up** (requiere el pipeline de assets de GLPI); hasta entonces
  el reporte se hace por el **flujo autenticado** y el modo anónimo se documenta como **no
  soportado en v1**.
- **Comportamiento sin backend de cache:** el rate limit hace *fail-open* (documentado); el
  anti-bot primario (Altcha) sigue siendo obligatorio.
- Motor del formulario: **Forms nativo si el gate runtime lo confirma**; si no, formulario
  mínimo propio (ver `../architecture/companyqr-forms-spike.md`).

## Ciclo de vida del token / código
- **Crear:** on-demand (acción admin o al imprimir la primera etiqueta) → token opaco +
  código visible.
- **Activo:** resuelve al activo.
- **Rotar:** genera nuevo token (el anterior deja de resolver de inmediato); se registra
  el evento; útil si la etiqueta se ve comprometida o se reimprime.
- **Revocar:** estado `revoked`; el token deja de resolver (respuesta "no disponible");
  **se conserva la fila y todo el historial**.
- **Sincronización con el activo (hooks):**
  | Evento del activo | Efecto en el código |
  |---|---|
  | Cambia de **entidad** / se mueve | Se actualiza el snapshot de entidad; el token sigue; la visibilidad pasa a seguir la nueva entidad. |
  | **Baja / retiro** (status) | Configurable: la ficha refleja "dado de baja"; el código puede autosuspenderse. |
  | **Borrado lógico** (papelera) | Código auto-suspendido; se reactiva si se restaura. |
  | **Purga** (definitivo) | Código auto-revocado (activo inexistente); **historial retenido**. |
- Regla: **el código siempre puede quedar revocado sin perder el historial**.

## Privacidad y retención
- Métricas mínimas por evento: **fecha/hora, código QR, activo, resultado, canal, y actor
  si está autenticado**. **No** se guardan hashes permanentes de IP/User-Agent.
- **Rate limiting anónimo:** almacenamiento **temporal** (cache de GLPI) con **retención
  corta** (TTL configurable, p. ej. 15 min); no persiste en base.
- **Retención de eventos de escaneo:** configurable (default a definir; p. ej. purga de
  detalle fino a los N meses vía tarea programada), documentada en operaciones.
- Auditoría de acciones sensibles (rotar/revocar/imprimir) vía `AuditService` + `Log` core.

## Etiqueta física
- **Default:** 70,75 × 24 mm, **horizontal**, **fondo amarillo** (estándar inicial, para
  continuidad visual con las etiquetas previas). **Contenido:** encabezado `TI • ACTIVOS`
  + QR + **código de inventario** (`NB-…`/`PC-…`) + **tipo**. Ubicación/organización
  **opcional** (no por defecto). Tamaño, color y campos **configurables**.
- **Prohibido en la etiqueta:** IP, MAC, hostname, VLAN, datos técnicos sensibles.
- **Impresión individual** en v1; diseño **preparado para lote** (no se agrega complejidad
  de lote si compromete la v1).
- Ver mock: `mocks/companyqr-mock.html`.

## i18n
- ES por defecto, EN secundario; todas las cadenas por `__()`; catálogos en
  `plugins/companyqr/locales/` (`es_ES`, `en_GB`). Nada hardcodeado (textos de ficha,
  categorías, prefijos de código, campos de etiqueta = configurables).

## Definition of Done (recordatorio)
código · migración reversible · ACL · i18n ES/EN · auditoría · métricas/logs · tests ·
documentación · changelog · verificación de core intacto.
