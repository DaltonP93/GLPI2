# Funcional — `companyqr` (QR por activo, ficha segura, reporte)

Principio rector: **el QR identifica; GLPI autoriza.** Ver `../adr/ADR-0011-companyqr.md`.

## Política autenticado / anónimo

| Situación | Comportamiento |
|---|---|
| **Por defecto (autenticado)** | `QR → resolver token → si no hay sesión, login GLPI (con retorno) → ficha del activo **filtrada por ACL nativa** del usuario`. |
| **Modo anónimo (opcional, OFF por defecto)** | Si un admin lo habilita: sin sesión se muestra **sólo** un subset mínimo configurable (**por defecto: código público + tipo + botón "Reportar problema"**). |
| **Nunca en modo anónimo** | IP, MAC, hostname, VLAN, responsable/usuario, ubicación detallada, serie ni datos técnicos. |

- El token **no** autentica ni autoriza; sólo evita enumeración. La visibilidad de datos
  del activo es **siempre** la ACL nativa de GLPI (perfil + entidad + `canViewItem`).
- El modo anónimo se activa por configuración global (y opcionalmente por entidad); su
  subset de campos es configurable, con el default mínimo indicado.

## Código visible del activo
- **Fuente primaria:** número de inventario nativo `otherserial` (ej. `PC-001245`).
- **Fallback** (si `otherserial` está vacío): código generado y almacenado por el plugin
  `<PREFIJO_POR_TIPO>-<secuencia>` (prefijo configurable por itemtype: `PC`, `MON`, `IMP`,
  `NET`…); la secuencia vive en la tabla del plugin (**no** es el `items_id`).
- **Unicidad:** se valida la unicidad del código visible dentro del alcance configurado
  (por defecto, por entidad raíz). Si `otherserial` duplicado → se marca conflicto y se
  usa el fallback generado hasta resolver.
- El `items_id` interno **nunca** se muestra ni se codifica en la URL pública.

## Ficha al escanear
- **Autenticada:** se renderiza según la ACL del usuario; puede enlazar al formulario
  nativo del activo si tiene permiso. Acciones: "Reportar problema" (prellenado).
- **Anónima (si habilitada):** sólo el subset mínimo + "Reportar problema".
- La ficha se arma con `AssetResolver`, que expone **únicamente** un conjunto seguro de
  campos (whitelist), nunca campos técnicos sensibles.

## Reporte de problema → ticket vinculado al activo
- Crea un `Ticket` y lo **vincula al activo** con `Item_Ticket` (vía `TicketCreator`).
- Categoría, urgencia y entidad **por configuración** (sin hardcode); canal/origen = `QR`.
- **Autenticado:** solicitante = usuario en sesión.
- **Anónimo (si habilitado):** creación `callAsSystem` + **rate limiting + anti-bot Altcha
  (ambos, no uno u otro)**; datos de contacto opcionales.
- Motor del formulario: **Forms nativo si el spike gate lo confirma**; si no, formulario
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
- **Default:** 70,75 × 24 mm, **horizontal**. **Contenido:** QR + **código de inventario**
  + **tipo**. Configurable (tamaño y campos).
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
