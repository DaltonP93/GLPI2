# ADR-0023: `companypurchasing` P2D-4 — entrega física, cierre, UI, bandejas, notificaciones nativas y métricas

- **Estado:** Propuesto (implementado en P2D-4; pendiente de revisión humana)
- **Fecha:** 2026-10-01
- **Decisores:** Producto, Compras, finanzas, seguridad, plataforma
- **Módulo/área:** `plugins/companypurchasing` (0.4.0 → 0.5.0) y `plugins/companyworkflow` (0.5.0 → 0.6.0, sólo
  API de LECTURA)
- **Complementa:** ADR-0013, ADR-0018, ADR-0019 y el gate `../architecture/companypurchasing-native-first-gate.md`
  (§1 alcance v1, §2 reutilización nativa, §3 motor único, §5 recepción, §9 ACL).

## Contexto
P2D-4 cierra `companypurchasing` v1: entrega física, cierre administrativo, UI completa, bandejas,
notificaciones y métricas. Fuera de alcance: IA, WhatsApp, firma certificada, activar SI-4 en producción,
SI-3 (impresión masiva) y cambios de core.

Contratos verificados en el código de GLPI **11.0.8** (no inventados):

| Necesidad | API real de GLPI 11.0.8 | Uso |
|---|---|---|
| Páginas | `Glpi\Controller\AbstractController::render()` + `#[Route]` + `#[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]` (rutas del plugin bajo `/plugins/companypurchasing/…`) | controladores delgados |
| Layout | `templates/layout/page_without_tabs.html.twig` (`title`, `menu`) → `Html::header()/footer()` | mismo look & feel que el core |
| CSRF | `Glpi\Kernel\Listener\ControllerListener\CheckCsrfListener`: valida `_glpi_csrf_token` en **todo** POST no-AJAX; Twig `csrf_token()` | no se revalida (consumiría el token) |
| Menú | `$PLUGIN_HOOKS['menu_toadd']` → `Html::generateMenuSession()` llama `getMenuContent()` del tipo | entrada "Compras" en *Gestión* |
| Notificaciones | `NotificationEvent::raiseEvent()`; `NotificationTarget::getInstanceClass()` resuelve `Namespace\NotificationTarget<Clase>`; `addAdditionalTargets()` / `addSpecificTargets()` / `addToRecipientsList()` / `addTagToList()` / `addDataForTemplate()`; plantillas `NotificationTemplate` + `NotificationTemplateTranslation`; envío por la cola nativa `QueuedNotification` + Acción automática `queuednotification` | sin mailer ni cola propios |
| Adjuntos | `Document::add()` con `_filename`/`_prefix_filename` (archivo en `GLPI_TMP_DIR`; valida extensión contra `DocumentType`) + `Document_Item` | N documentos por cotización |
| Entidades | `getEntitiesRestrictCriteria()`, `Session::haveAccessToEntity()`, `Profile_User::getUserEntities()` | ACL multi-entidad |

## Decisión

### 1. Entrega y cierre en el MISMO workflow (nueva versión de la definición)
`RECEIVED →deliver_complete→ DELIVERED →close→ CLOSED`. `RECEIVED` y `DELIVERED` son **intermedios**, `CLOSED`
es **final** (instancia cerrada por el motor). Ambas transiciones son *bound*: sin paso de aprobación, con la
condición `delivery_bound = 1` / `close_bound = 1` que sólo aporta Compras tras confirmar el hecho local (la
superficie HTTP genérica del motor no pasa `fields`, así que no puede dispararlas). **No existe
`PARTIALLY_DELIVERED`**: la entrega parcial vive en `receipt_units.physical_state`; la solicitud sigue `RECEIVED`
hasta que **todas** las unidades estén `DELIVERED`. `domain_state` sigue siendo proyección.

Publicar crea una **versión nueva** (`DefinitionBuilder::createVersion`); las instancias existentes conservan la
suya. Una instancia P2D-3 (sin fase de entrega) **no se muta**: la entrega falla cerrada y `reconcile` la reporta
(`legacy` / `legacy_blocked`), igual que P2D-3 con la fase de compra.

### 2. Modelo de entrega física
- Tabla nueva APPEND-ONLY `glpi_plugin_companypurchasing_delivery_batches` (`idempotency_key` UNIQUE,
  `input_sha256`, actor, destinatario, `delivered_at`, notas, `units_count`, `correlation_id`).
- `receipt_units` agrega `delivery_batches_id`, `delivered_at`, `delivered_to_users_id` (NULL = no entregada).
  `physical_state ∈ {RECEIVED, DELIVERED}`. Una unidad se entrega **una sola vez**. Identidad canónica:
  `receipt_unit_uuid` (nunca serial, `line_no` ni código QR).
- `requests` agrega `delivery_seq` / `delivery_synced_seq` (mismo patrón durable que `receiving_seq`).

### 3. `DeliveryService::deliver()` — atómico e idempotente
Exige `RIGHT_DELIVER`, acceso a la entidad, motor en `RECEIVED` con la fase de entrega en su versión, unidades
de la solicitud en `RECEIVED`, destinatario activo con perfil en la entidad (`Profile_User::getUserEntities`) y
**gate de inventario** (§4). Transacción:

```
BEGIN
  SELECT … receipt_units WHERE requests_id = ? AND uuid IN (…) ORDER BY id FOR UPDATE   (lock en orden estable)
  SELECT … delivery_batches WHERE idempotency_key = ? FOR UPDATE   (replay exacto ⇒ mismo lote; otra entrada ⇒ conflicto)
  validar (estado físico, pertenencia, gate de inventario) contra lo LEÍDO BAJO LOCK
  INSERT delivery_batch
  UPDATE receipt_units (condicionado a physical_state = RECEIVED y delivery_batches_id IS NULL; affectedRows exacto)
  delivery_seq += 1 ; auditoría append-only
COMMIT            — cualquier fallo ⇒ ROLLBACK completo (nunca una entrega parcial accidental)
```
El lock es el mecanismo: una segunda entrega concurrente de la misma unidad espera el COMMIT de la primera y
la **ve entregada en su validación** (error controlado `unit_not_deliverable`); el UPDATE condicionado es sólo
defensa en profundidad (si se disparara, sería `concurrency_conflict`, y el test lo distingue).

**Orden de locks (corrección durante la implementación):** primero las unidades y DESPUÉS la clave. Con el orden
inverso, InnoDB toma un *gap lock* sobre el índice UNIQUE de `idempotency_key` (clave aún inexistente) antes de
las filas de unidades, y dos entregas concurrentes de la misma unidad con claves distintas producían un
**deadlock** (1213) en lugar del error funcional. Con las unidades primero, la segunda transacción espera en la
fila de la unidad y la ve entregada. Además hay **un** reintento acotado ante 1213/1205 (la transacción completa
se rehace; la idempotencia hace seguro el reintento).

### 4. Gate de inventario (tabla PROPIA de Compras)
Unidad `is_inventoriable = 1` ⇒ su fila de `inventory_outbox` debe estar **DONE** (lectura bajo el mismo lock;
el outbox es de Compras). `PENDING`/`RETRY` ⇒ "inventario pendiente", `LEASED` ⇒ "integración en curso",
`ERROR` ⇒ "error de integración", fila ausente ⇒ "inventario pendiente". Nunca se muestra `last_error`, tokens
ni datos técnicos. Unidad no inventariable ⇒ no hay outbox ⇒ entregable. No se consultan tablas de
`companyintegrations`.

### 5. Sincronización entrega → motor
Igual que la recepción: **COMMIT local primero**, transición del motor **después** (nunca dentro de la
transacción). `ReceivingSync` pasa a ser la saga **física** (recepción + entrega): el objetivo se deriva de los
contadores (`receivingTarget`) y de las unidades entregadas (`physicalTarget`); `syncPath()` agrega
`RECEIVED →deliver_complete→ DELIVERED`. Si la entrega confirma y el proceso cae, `delivery_seq > synced` deja
la sincronización pendiente y la Acción automática nativa `reconcileprojection` converge. Jamás se deshace una
entrega física porque el motor falló.

### 6. Cierre `closeRequest()`
`RIGHT_MANAGE_PURCHASING` + entidad. Requisitos: motor en `DELIVERED`; todas las unidades `DELIVERED`; ninguna
recepción pendiente (`received_qty = ordered_qty` en todas las líneas); inventariables con outbox `DONE`;
integridad de aprobación limpia. Transición `close` con `close_bound = 1` y `expected_lock_version`. Idempotente:
motor ya `CLOSED` ⇒ `already_closed`, sin segunda transición ni eventos duplicados (`recordOnce`).

### 7. Bandejas derivadas del MOTOR (API de LECTURA nueva en `companyworkflow` 0.6.0)
`WorkflowApi::actionsForCurrentUser(Instance)` y `WorkflowApi::pendingDecisionsForCurrentUser(itemtype, limit)`
reutilizan la MISMA lógica del motor (`required_right`, entidad, aprobadores efectivos por grupo/perfil +
delegaciones, voto ya emitido) sin mutar nada; `WorkflowApi::currentApprovers(Instance)` alimenta las
notificaciones; `history()` acepta `instances_ids` (métricas por etapa). La UI **nunca** inventa un botón a
partir de `domain_state`: decisiones = `actionsForCurrentUser()` ∩ derecho de dominio; operaciones de Compras
(compra, recepción, entrega, cierre) = `availableActions()` de la instancia ∩ derecho de dominio.

### 8. UI propia delgada (el gate ya decidió que GLPI Forms no alcanza)
Controladores Symfony del plugin + plantillas Twig que extienden el layout nativo; sin SPA ni framework
frontend. Páginas: Mis solicitudes · Bandeja (para mí / Compras / recepciones / entregas) · Gestión de compras ·
Recepción · Entrega · Métricas · Configuración (sólo `MANAGE_CONFIG`) + detalle y formulario. Toda mutación es
**POST + CSRF nativo + ACL y entidad del lado servidor + PRG** (POST → redirect → GET); los controladores sólo
traducen HTTP ↔ servicios de dominio (no duplican validaciones). Twig escapa por defecto. `idempotency_key` de
recepción/entrega se genera por render de formulario y viaja oculta (un reintento del mismo submit = replay).

**Método HTTP (hallazgo del E2E HTTP):** en GLPI 11.0.8 el router de plugins (`PluginsRouterListener`) sólo captura
`ResourceNotFoundException`; un GET sobre una ruta POST-only deja escapar `MethodNotAllowedException` y GLPI
responde **500** con un log CRITICAL. El controlador **no** se ejecuta (no hay mutación), pero la respuesta es
incorrecta. Sin tocar el core, `MethodGuardController` declara una ruta GET/HEAD sobre exactamente las rutas de
`ActionController` (`ActionController::POST_ONLY_PATHS`) que responde el **405** nativo de Symfony. Un test
estático exige que cubra todas las acciones y ninguna página. El mismo comportamiento del core afecta a las rutas
POST de otros plugins (p. ej. `companyqr`, `companyworkflow`); queda como seguimiento fuera de P2D-4.

### 9. Notificaciones NATIVAS
`NotificationTargetRequest` (namespace del modelo) + plantillas/notificaciones sembradas en `install()` sólo si
faltan (idempotente; nunca pisa lo que el administrador ajustó). Eventos: `request_submitted`,
`approval_pending`, `request_approved`, `request_rejected`, `request_returned`, `purchase_started`,
`receipt_completed`, `ready_for_delivery`, `request_delivered`, `request_closed`. Destinatarios: solicitante,
aprobadores efectivos de la etapa (motor), actor, destinatario de la entrega + los destinos nativos
(grupos/perfiles/administradores) que configure el administrador. **Consecuencia de hechos durables**: el
disparador es el ledger del motor (`workflow_history_id`); en vivo vía `companyworkflow:transitioned` y, si el
listener se pierde, la Acción automática recorre el ledger con cursor. Deduplicación por
`notify:<history_id>:<evento>` en la auditoría append-only (como mucho una vez por hecho). Un fallo de correo
**no revierte** nada (best-effort, registrado como `notification.failed`).

### 10. Métricas (sin data warehouse)
`MetricsService` (`RIGHT_VIEW_METRICS`, fail-closed) calcula desde tablas propias + ledger del motor vía API.
**Filtro de entidad obligatorio**: el alcance son las entidades ACTIVAS de la sesión con acceso
(`Session::haveAccessToEntity`), aplicado como `entities_id IN (…)` en cada consulta; un filtro de entidad pedido
por el navegador sólo puede REDUCIR ese alcance (entidad ajena ⇒ resultado vacío). El mismo alcance
(`MetricsService::scopeEntities`) lo usan el listado y las bandejas. Dinero: aritmética decimal exacta por
moneda (`MetricsMath` sobre `Decimal`: acumuladores en micro-unidades como string), **nunca float**, **totales separados por
`currency_code`** (jamás se suman monedas), PYG escala 0. Promedios con `intdiv` (horas enteras).

## Alternativas consideradas
- **Estado `PARTIALLY_DELIVERED` en el motor** — rechazada: duplicaría el hecho físico en la máquina de estados
  y obligaría a transicionar en cada lote; el hecho vive en las unidades.
- **GLPI Forms / Service Catalog para la UI** — rechazada por el gate (entrada rica con N líneas, cotizaciones,
  recepción por unidad). Se reutiliza el layout, CSRF y menú nativos.
- **SPA / framework frontend** — rechazada (superficie de ataque, build propio, contra "no framework nuevo").
- **Mailer/cola propios** — prohibido; se usa `NotificationEvent` + `QueuedNotification` nativos.
- **Bandeja por `domain_state` hardcodeado** — rechazada; se deriva del motor (aprobadores efectivos, voto,
  acciones disponibles).
- **SQL de Compras sobre tablas de `companyworkflow` para la bandeja** — rechazada; API de lectura en el motor.
- **Leer tablas de `companyintegrations` para la UI** — rechazado. El gate de entrega usa sólo el outbox
  PROPIO; para mostrar en el detalle de la unidad el activo GLPI vinculado y su código visible se agregó una API
  pública **READ-ONLY** en `companyintegrations` 0.6.0 (`Api\InventoryLinkApi::forUnits(uuids)`: fase SI-4,
  `itemtype`/`items_id` y `public_code` sólo si la sesión puede ver ese activo, entidad verificada, máx. 500
  unidades, nunca tokens ni errores técnicos). Compras la consume por `IntegrationLinkGateway` (si el plugin no
  está, la columna queda vacía).

## Consecuencias
- (+) v1 completo y auditable de punta a punta; una sola máquina de estados; ninguna lógica de dominio en la UI.
- (+) Upgrade 0.4.0 → 0.5.0 no destructivo (tablas/columnas/claves/notificaciones sólo si faltan).
- (−) La bandeja "para mí" recorre instancias abiertas del tipo con tope configurable (v1); se documenta.
- (−) Notificaciones "como mucho una vez" por hecho (se prefiere no duplicar correos ante caídas).
- i18n ES/EN para toda cadena nueva; textos de negocio (estados, etiquetas) nunca hardcodeados en plantillas.

## Cumplimiento de la Regla 0
No se modifica el core de GLPI ni `vendor/`. Sin SQL sobre tablas core (todo vía `CommonDBTM`/APIs nativas);
sin SQL de Compras sobre tablas de otros plugins (motor y SI-4 sólo por sus APIs públicas). Sin segundo workflow
ni segundo motor de notificaciones.
