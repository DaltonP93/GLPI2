# ADR-0022: SI-4 (incremento SI4-3) — código companyqr, etiqueta, ack del outbox y cierre de la saga

- **Estado:** Aceptado — SI4-3 implementado y mergeado con aprobación humana: PR #19 (`9382ef2`). El worker SI-4 sigue **deshabilitado** (`si4_enabled = 0`): ver `docs/operations/si4-readiness.md`.
  *(Estado original: "Propuesto (SI4-3 implementado; pendiente de revisión humana)".)*
- **Fecha:** 2026-10-01
- **Decisores:** Producto, TI/activos, seguridad, plataforma
- **Módulo/área:** `plugins/companyintegrations` (0.4.0 → 0.5.0) y `plugins/companyqr` (0.2.0 → 0.3.0, API pública)
- **Complementa:** ADR-0011 (companyqr: "el QR identifica; GLPI autoriza"), ADR-0019 (outbox y ack de Compras),
  ADR-0020 (saga SI4-1, fencing), ADR-0021 (SI4-2: activo GLPI, Infocom, `asset_bridge`).

## Contexto
SI4-2 deja cada unidad recibida en `BRIDGED`: activo en Snipe-IT, activo GLPI con su Infocom y `asset_bridge` 1:1,
todo verificado. El outbox de Compras sigue abierto (sin `acknowledgeProcessed()`). SI4-3 completa SI-4:

- código companyqr ACTIVO del activo;
- etiqueta imprimible;
- confirmación del outbox como ÚLTIMO efecto externo;
- cierre de la saga (`COMPLETED`) a prueba de caídas.

Riesgos a cerrar:
- dos códigos para un activo (carrera o crash entre crear y registrar);
- usar un código revocado, suspendido o de otro activo;
- filtrar el token opaco del QR fuera de companyqr;
- confirmar el outbox antes de tener todo listo;
- quedar en `QR_READY` para siempre si el proceso cae justo después del ack.

### Hechos verificados (código del repositorio y GLPI **11.0.8**)
| # | Hecho | Evidencia |
|---|---|---|
| 1 | `CodeManager::getOrCreateForItem(CommonDBTM)` busca por (itemtype, items_id) y, si no hay, crea un código ACTIVO. `public_code` = `otherserial` si está libre; si no, genera `<PREFIJO>-<n>`. Nunca escribe `otherserial` | `companyqr/src/Service/CodeManager.php` |
| 2 | La tabla de códigos tiene `UNIQUE(token)`, `UNIQUE(public_code)` y `UNIQUE(itemtype, items_id)`: a lo sumo un código por activo | `companyqr/hook.php` |
| 3 | En GLPI 11 una clave duplicada **lanza** (`DBmysql::doQuery` → `RuntimeException`) y `CommonDBTM::add()` no la atrapa. Antes de SI4-3, el bucle de reintentos de `createForItem` no se recuperaba de una carrera por el mismo activo | `src/DBmysql.php:416`, `src/CommonDBTM.php` (`addToDB`) |
| 4 | `LabelRenderer::pdf(array)` (TCPDF nativo) genera la etiqueta 70,75 × 24 mm. El QR codifica la URL autenticada `/plugins/companyqr/scan/{token}`; prohibido en la etiqueta: IP, MAC, hostname, VLAN, datos técnicos | `companyqr/src/Service/LabelRenderer.php`, `LabelController.php`, ADR-0011 |
| 5 | Derechos `plugin_companyqr`: `RIGHT_GENERATE` (2) y `RIGHT_PRINT` (4); estados `active` / `suspended` / `revoked`; los hooks del activo suspenden (papelera), reactivan (restaurar), revocan (purga) y sincronizan la entidad | `companyqr/src/Model/Code.php`, `hook.php` |
| 6 | `install()` de companyqr 0.2.0 re-agregaba el derecho y re-sembraba la configuración en cada llamada. GLPI vuelve a llamar `install()` al actualizar: con un cambio de versión, el upgrade abortaba (`Duplicate entry … unicity`) | `companyqr/hook.php` (0.2.0), mismo patrón que issue #14 |
| 7 | `acknowledgeProcessed()` exige `status = LEASED` + token vigente + `leased_until >= NOW()` (reloj de la BD). Tras el éxito es idempotente con el mismo token. Si falla, la fila sigue reclamable al vencer el lease. `getHandoff()` devuelve el `status` (`DONE` tras el ack) | `companypurchasing/src/Api/PurchasingIntegrationApi.php`, ADR-0019 §6 |
| 8 | `Session::haveRightsOr()` y `Plugin::isPluginActive()` son API soportada | `src/Session.php:1527`, `src/Plugin.php:2714` |

## Decisión (Configure / Extend / **Integrate** / Build acotado)
Nada nativo cubre "código + etiqueta por unidad recibida". companyqr ya resuelve QR, etiqueta y ACL, así que **no se
crea otro sistema de QR**:
- companyqr se **extiende** con una API pública mínima;
- companyintegrations la **integra** detrás de un puerto (`QrGateway`).

1. **API pública de companyqr — `GlpiPlugin\Companyqr\Api\CompanyQrApi` (agnóstica del dominio).**
   - **Operaciones:**
     - `ensureForItem(itemtype, itemsId)`: get-or-create; **reutiliza `CodeManager::getOrCreateForItem()`**;
     - `findForItem(itemtype, itemsId)`;
     - `getCode(codeId)`;
     - `renderLabelPdf(codeId)`: **reutiliza `LabelRenderer::pdf()`** con la configuración existente.
   - **Metadatos devueltos (no sensibles, whitelist `META_KEYS`):** `code_id`, `public_code`, `status`, `itemtype`,
     `items_id`, `entities_id`. En `ensureForItem` se suma `outcome` (created | existing). **Nunca el token.**
   - **ACL** con la sesión real:
     - `RIGHT_GENERATE` para `ensureForItem`;
     - `RIGHT_PRINT` para `renderLabelPdf`;
     - READ, generate o print para las lecturas;
     - en todos los casos, el activo debe ser **visible por `canViewItem()`** (perfil + entidad), como en
       `AdminController`/`LabelController`.
   - **Errores tipados:** `CompanyQrException` (`acl`, `not_found`, `inactive`, `render`, `invalid`).
   - **Nunca rota, revoca ni reactiva.** Un código no ACTIVO se informa tal cual y no se imprime (`inactive`).
   - **La URL con el token se arma sólo dentro de companyqr:**
     - `ScanUrl::forToken()` arma la URL;
     - `LabelComposer::spec()` arma el contenido de la etiqueta y lo comparten la API y `LabelController` (la misma
       etiqueta venga de donde venga).
   - **Carrera por el mismo activo:** `createForItem` atrapa la clave duplicada y devuelve el código que creó el otro
     proceso. Nunca hay dos.
   - **Upgrade de companyqr (0.3.0, sin cambio de esquema):** `install()` es seguro en upgrade (hecho 6):
     - el derecho se agrega sólo si falta, y en ese primer alta recibe todos los bits el Super-Admin;
     - un upgrade no toca los derechos ajustados por un administrador;
     - la configuración sólo siembra claves ausentes.
2. **Estados (continúan la saga).** `BRIDGED → QR_READY → COMPLETED`.
   - `BLOCKED_CONFIG` admite `resume_state = BRIDGED`, que significa "reanudar desde la etapa QR".
   - Tabla propia nueva `si4_runtime`: estado operativo del worker (el cursor del finalizador, §6).
   - Columnas nuevas en `si4_sagas`: `qr_code_id` (UNIQUE), `qr_public_code`, `qr_outcome`, `label_ready_at`,
     `completed_at`.
   - **No hay columna para el token ni para el PDF.** El store sólo acepta columnas en lista blanca y `label_ready_at`
     sólo con el reloj de la BD.
3. **Etapa QR (`Si4QrStage`), desde `BRIDGED`:**
   - **(0) ACL:** `RIGHT_GENERATE` + `RIGHT_PRINT` de companyqr **antes de cualquier escritura**. Si falta ⇒
     `BLOCKED_CONFIG` (`qr_acl`), sin bypass.
   - **(1) Vínculos vigentes, sin volver a Snipe** (el puente es la fuente de verdad de lo verificado en SI4-2):
     - `asset_bridge` EXACTO (uuid, Snipe id/tag, activo GLPI, entidad) con el id registrado;
     - activo GLPI vivo en la entidad de la unidad;
     - si no ⇒ `MANUAL_REVIEW`.
   - **(2) `ensureForItem` con presupuesto de lease** (`holds` ≥ 30 s, reloj de la BD). Get-or-create por activo: un
     crash entre crear el código y registrarlo encuentra el **mismo** código en el retry (`qr_outcome = existing`).
     `QrCodeRules` exige:
     - sólo claves permitidas (si llega el token ⇒ `qr_meta_unexpected`, fail-closed);
     - el mismo código ya registrado, si lo hay;
     - el mismo activo;
     - estado **ACTIVE** (revocado/suspendido/otro ⇒ `MANUAL_REVIEW`; nunca rotar ni reactivar);
     - la entidad de la unidad;
     - **`public_code` = número de inventario de la unidad** (= `asset_tag` de Snipe = `otherserial` GLPI). Un
       código previo con otro código visible (p. ej. generado antes de que SI4-2 reclamara el número de
       inventario) ⇒ `MANUAL_REVIEW`.
   - **(3)** Se registran `qr_code_id`, `qr_public_code` y `qr_outcome` (una sola vez).
   - **(4) Etiqueta renderizable:** `renderLabelPdf` debe devolver un PDF completo (`%PDF-` … `%%EOF`); se valida y
     **se descarta**. Si no se puede renderizar ⇒ `BLOCKED_CONFIG` (`qr_label`); el retry reutiliza el mismo código.
   - **(5)** Se pasa a `QR_READY` y se registra `label_ready_at`. **LABEL_READY = código ACTIVO + etiqueta renderizable**:
     es una condición registrada, no un estado aparte.
4. **`public_code` es identificación visible, no autorización.** El QR codifica la URL autenticada de companyqr con
   el token opaco; nunca se arma un QR con sólo el código visible. La ficha sigue exigiendo sesión + ACL de GLPI.
5. **Ack = ÚLTIMO efecto externo; revalidación previa.** Antes del ack se revalida, sólo con lecturas:
   - saga en `QR_READY` y dueño del lease;
   - puente y activo GLPI vigentes;
   - el código registrado sigue ACTIVO, del mismo activo y con el mismo `public_code`.
   Cualquier divergencia ⇒ `MANUAL_REVIEW` sin ack (outbox `ERROR` por `markError`). Recién entonces el worker llama
   `PurchasingIntegrationApi::acknowledgeProcessed($uuid, $leaseToken)` — **el único punto que lo hace**.
   - **Ack fallido** (lease vencido, re-tomado o error de la BD): la saga **queda en `QR_READY`**, `last_error_class
     = ack`, `markRetry` con backoff si todavía se puede, y el outbox no queda DONE.
   - **El reintento desde `QR_READY` no rehace nada**: ni Snipe, ni GLPI, ni código, ni etiqueta. Sólo revalida y
     confirma con su propio lease.
6. **Finalizador durable (`Si4Finalizer`): recorrido round-robin ACOTADO y DURABLE, con cursor persistente y
   wrap-around.**
   - **Cada corrida** inspecciona a lo sumo `batch` sagas (por defecto 200): `state = QR_READY AND id > cursor ORDER BY
     id LIMIT batch`.
   - **El cursor** (`FinalizerCursor`) vive en la tabla PROPIA `si4_runtime` (fila `si4_finalizer_cursor`), no en
     memoria: cada corrida CLI termina y la siguiente sigue donde quedó.
   - **El cursor es la ÚLTIMA saga INSPECCIONADA, no la última completada.** Avanza aunque la saga no se complete
     (outbox ≠ DONE, handoff ilegible o no visible, hash distinto). **Una saga pendiente no bloquea a las
     posteriores**: con lote 2 y `A, B` pendientes, `C` DONE, la corrida 1 ve `A, B` y la corrida 2 ve `C`.
   - **Wrap-around:** un lote incompleto (o nada después del cursor) es el fin de la ronda y la siguiente empieza en 0.
     Las sagas antiguas (RETRY/LEASED) se reconsideran en cada ronda hasta que su outbox pase a DONE; nunca se dan
     por abandonadas.
   - **Concurrencia:** el cursor avanza por compare-and-set sobre el valor leído; un avance obsoleto no pisa al
     concurrente ni lo hace retroceder. Dos corridas pueden inspeccionar la misma saga: inspección **al menos una
     vez, eventual** + transición **exactamente una vez** por la guarda SQL de `complete()`.
   - **Por cada saga** consulta `getHandoff()` (nunca las tablas de Compras). Sólo con el outbox **DONE** (y el mismo
     `payload_sha256`) pasa la saga a `COMPLETED` con `SagaStore::complete()`:
     - `UPDATE … WHERE state = 'QR_READY'`, **sin depender del lease viejo** (el outbox ya cerró);
     - `completed_at` con el reloj de la BD;
     - evento en la bitácora;
     - idempotente.
   **Nunca `COMPLETED` con el outbox ≠ DONE.** `transition()` rechaza `COMPLETED`: la única vía es `complete()`.
   El worker ejecuta una pasada al inicio de cada corrida (antes de Snipe y de reclamar) y `finalizeOne()` justo
   después de cada ack. Así un crash tras el ack **siempre** se cierra en una ronda posterior, por más sagas
   pendientes que haya antes.
7. **ACL del usuario técnico (mínimo privilegio).** Necesita:
   - `RIGHT_SI4` (companyintegrations);
   - `RIGHT_INTEGRATION` (Compras);
   - `RIGHT_GENERATE` + `RIGHT_PRINT` (companyqr);
   - los derechos nativos de SI4-2.
   `WorkerSession::assertRights()` los exige **antes de reclamar** (la corrida no empieza). La etapa los vuelve a
   verificar por unidad (⇒ `BLOCKED_CONFIG`). Los bits se toman de los contratos públicos de cada plugin.
8. **Presupuesto del lease.** `Si4Config::minLeaseSeconds` suma 30 s para la etapa QR (código + render + ack, todo
   local). Una configuración existente por debajo del nuevo mínimo queda fail-closed: el worker no reclama.
9. **Operación.** `si4_enabled = 0` por defecto, **sin Acción automática**. `si4-run` ejecuta el finalizador y las
   etapas SI4-1/2/3 en una corrida. Activarlo y programarlo es una decisión operativa posterior (staging primero).
10. **Upgrade 0.4.0 → 0.5.0** con el mismo `install()` idempotente: columnas, índice y tabla `si4_runtime` sólo si
    faltan (el cursor arranca en 0). Las sagas
    `BRIDGED` (y anteriores), los mapeos, `asset_bridge` y los códigos companyqr existentes quedan intactos. Una saga
    `BRIDGED` de SI4-2 continúa con el worker SI4-3 hasta `COMPLETED`.

### Crash points (todos convergen: 1 activo Snipe, 1 activo GLPI, 1 Infocom, 1 puente, 1 código y DONE una vez)
| Punto | Estado al caer | Recuperación |
|---|---|---|
| antes de companyqr | `BRIDGED`, sin código | el siguiente dueño crea el código |
| tras crear el código, antes de `qr_code_id` | `BRIDGED`, código existente sin registrar | `ensureForItem` devuelve el MISMO (`existing`) |
| tras registrar los metadatos | `BRIDGED` + `qr_code_id` | mismo código (debe coincidir con el registrado) |
| tras renderizar, antes de `QR_READY` | `BRIDGED` | se re-renderiza (no se guarda nada) y pasa a `QR_READY` |
| tras `QR_READY`, antes del ack | `QR_READY`, outbox LEASED | lease vence ⇒ nuevo dueño revalida y confirma |
| ack falla | `QR_READY`, outbox RETRY/LEASED | reintento: revalida y confirma, sin rehacer nada |
| ack OK, crash antes de `COMPLETED` | `QR_READY`, outbox **DONE** | el finalizador ⇒ `COMPLETED` (sin lease) |
| tras `COMPLETED` | `COMPLETED`, outbox DONE | nada que hacer (no reclamable) |

Verificado en tests unitarios (dobles con la misma semántica) y en el selftest sobre GLPI 11.0.8 real:
- 8 crash points;
- dos workers;
- ack fallido;
- revalidación;
- ACL y multi-entidad;
- recorrido del finalizador con cursor durable (`[SI4Q-FINALIZER-CURSOR]`):
  - lote 2;
  - finalizador reconstruido en cada corrida;
  - wrap-around;
  - propiedad con más sagas que el lote;
  - carrera sobre el cursor;
- upgrade simulado y upgrade real 0.4.0/0.2.0 → 0.5.0/0.3.0.

## Alternativas descartadas
- **Otro sistema de QR o de PDF en companyintegrations:** duplica lógica y seguridad de companyqr (ADR-0011).
- **Copiar el token o el PDF a la saga:** el token sólo identifica y vive en companyqr; el PDF se regenera cuando haga
  falta. Guardarlos amplía la superficie sin beneficio.
- **SQL directo a las tablas de companyqr o de Compras:** viola los contratos públicos; todo pasa por `CompanyQrApi`
  y `PurchasingIntegrationApi`.
- **`asset_bridge.companyqr_code_id`** (previsto en `asset-bridge-model.md`): duplicaría el vínculo. El código se
  resuelve por activo (`findForItem`) y la saga guarda `qr_code_id` como evidencia del procesamiento.
- **Etiqueta con el motor de Snipe-IT para SI-4:** SI4-3 usa el renderer existente de companyqr (QR → ruta autenticada
  de GLPI2). El motor de Snipe queda para SI-3 (impresión masiva), sin cambios.
- **Rotar o reactivar automáticamente un código revocado/suspendido:** decisión humana (puede haber una etiqueta física
  en uso o un activo dado de baja). Va a `MANUAL_REVIEW`.
- **Ack antes de `QR_READY`, o cerrar la saga sólo con la respuesta del ack:** un crash entre ambos dejaría el outbox
  DONE sin código, o la saga abierta para siempre. El finalizador por `getHandoff()` cierra ese hueco sin depender del
  lease.
- **Finalizador con lease:** tras el ack la fila DONE ya no es reclamable; exigir lease dejaría la saga atascada.
- **Finalizador con `LIMIT` fijo sin cursor:** si las primeras N sagas `QR_READY` quedan pendientes, las posteriores
  con el outbox DONE quedarían fuera del lote para siempre (y ya no son reclamables). Por eso el recorrido es
  round-robin con cursor durable.
- **Cursor en memoria o en la configuración:** cada corrida CLI termina (perdería la posición). Además, el cursor es
  estado operativo, no configuración. Va en una tabla propia.

## Consecuencias
- (+) SI-4 completo por unidad, idempotente y a prueba de caídas en cada paso; el outbox sólo llega a DONE con todo
  listo y verificado.
- (+) companyqr gana una API pública reutilizable (otros módulos podrán pedir códigos y etiquetas sin conocer el token).
- (+) El upgrade de companyqr ya no aborta por el derecho duplicado.
- (−) El usuario técnico necesita dos bits más (companyqr generate/print).
- (−) Un código previo con otro código visible requiere decisión humana.
- (−) La etiqueta física de SI-4 sale de companyqr (no del motor de Snipe). Si se quisiera unificar con SI-3, hará
  falta un ADR posterior.
- **Pendiente (fuera de SI4-3):**
  - P2D-4 (entrega/UI);
  - activar el worker y programarlo tras staging;
  - impresión masiva (SI-3).

## Cumplimiento de la Regla 0
Sólo plugins propios y API soportada:
- GLPI: `CommonDBTM`, `Session`, `Plugin`, `Config`, `ProfileRight`;
- `CompanyQrApi`;
- `PurchasingIntegrationApi`.

Sin cambios en el core, sin SQL contra tablas del core ni contra tablas privadas de otros plugins. Verificado por
`tests/upgrade/verify-core-untouched.sh`.
