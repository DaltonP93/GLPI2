# Gate native-first — `companypurchasing` (Fase 2D)

> **Estado:** GATE de decisiones — **diseño, SIN implementación.** Fija el alcance de
> `companypurchasing` v1 antes de escribir una sola línea de lógica. Se detiene aquí para
> **aprobación humana final**.
> **Fecha:** 2026-09-20 · **Regla 0:** sólo hooks/API/servicios nativos; el core no se toca.
> **Complementa (no reemplaza):** `../adr/ADR-0013-companypurchasing.md`,
> `companypurchasing-technical-design.md`, `purchasing-workflow-signature-integration.md`,
> `asset-bridge-model.md`, `../adr/ADR-0006-purchasing-workflow.md`.

Este documento **reconcilia** el diseño previo con el **código YA MERGEADO** de
`companyworkflow`, `companysignature` y `companyintegrations` (SI-1). Donde el diseño previo dejaba
una decisión abierta o ambigua, el gate la **cierra** y lo marca como *[RECONCILIACIÓN]*.

---

## 0. Contratos REALES ya mergeados (fuente de verdad del código, no supuestos)

El gate se apoya en estas firmas **verificadas en `main`**. Cualquier diseño futuro de
`companypurchasing` debe llamarlas tal cual; no inventa métodos ni aliases.

### `companyworkflow` — `GlpiPlugin\Companyworkflow\Api\WorkflowApi` (motor genérico, domain-agnostic)
```php
startInstance(WorkflowDef $def, string $itemtype, int $items_id, int $entities_id, int $is_recursive = 0): ?Instance
availableActions(Instance $instance): array
transition(Instance $instance, string $action, array $ctx = []): TransitionResult
invalidateApprovals(int $instanceId, string $reason, array $context = [], ?int $expectedVersion = null): TransitionResult
history(array $filter = []): array          // filtro: events[], since_id, instances_id, limit — orden id ASC (causal)
historyById(int $id): ?array
builder(): DefinitionBuilder
```
- El motor **valida entidad + ACL** en `startInstance` y **no** conoce el dominio (recibe `itemtype`/`items_id`).
- `invalidateApprovals` es **idempotente** por `context['idempotency_key']` (obligatoria), soporta
  concurrencia con `expectedVersion` (optimistic lock) y reabre al checkpoint (`context['reopen_to_code']`
  o estado inicial). Emite `companyworkflow:approval_invalidated`.
- El **ledger** append-only expone cada hecho con un **`workflow_history_id`** durable; eventos:
  `companyworkflow:decision_recorded` (por aprobador), `:transitioned`, `:approval_invalidated`.
- La **`evidence_ref`** es **opaca para el motor**: el dominio (Compras) la coloca en el `ctx`/meta de la
  transición y el motor sólo la transporta en el ledger. *(Contrato exacto en §4.)*

### `companysignature` — `GlpiPlugin\Companysignature\Api\SignatureApi` (evidencia + hash + PDF, domain-agnostic)
```php
recordDocumentVersion(array $snapshot, bool $isSubstantive = true): DocumentVersion
composePdf(int $versionId): DocumentVersion
verify(string $token): array   // { status, evidence }
versions(): VersionStore
```
- `snapshot` = `{ schema, subject_type, subject_id, entity_id, document_version, payload }`
  (canonicalización determinista; **floats rechazados** → montos como **string exacto**).
- `recordDocumentVersion` valida **ACL** (`ApprovalEvidence::RIGHT_RECORD`) + **acceso a la entidad** del
  snapshot; devuelve una `DocumentVersion` **inmutable** con `content_sha256`. Es **idempotente**.
- **`evidence_ref` — contrato REAL que valida `Materializer`:** exactamente
  **`{ document_versions_id, document_version, content_sha256 }`** (verificado en
  `companysignature/Service/Materializer::resolveRef()`). El dominio construye **esas tres claves**;
  no se infiere por fecha; sin `evidence_ref` válida ⇒ pendiente (fail-closed).
- **PDF (responsabilidad real):** el listener/reconciliador de Firma **materializa la EVIDENCIA durable**
  pero **NO compone automáticamente el PDF**. `composePdf($versionId)` es **explícito/on-demand**; hoy
  **no hay Cron** que recomponga PDFs `pending`. Es **retryable e idempotente**, y su fallo **no** revierte
  la aprobación ni la evidencia.

### `companyintegrations` — SI-1 (ADR-0015): **READ-ONLY**
- Hace: `SnipeItClient` (sólo GET), `asset_bridge`, mapeos, reconciliación con detección de conflictos,
  gateway `GET /asset/{asset_tag}`.
- **NO hace (verificado en su README):** *no crea/edita activos en Snipe · no recepción desde Compras ·
  **no ejecuta la saga SI-4** · no crea/modifica activos core de GLPI*.
- **Consecuencia para el gate:** `companypurchasing` **no puede** delegar hoy la escritura a Snipe en
  companyintegrations, porque SI-4 aún no existe. Ver §7.

---

## 1. Alcance de `companypurchasing` v1 (QUÉ se implementará ahora)

Circuito de negocio (los **estados viven en `companyworkflow`**, ver §3):
```
solicitud → líneas → envío → aprobación jefe → compras → gerencia financiera
          → aprobada / rechazada / devuelta → compra → recepción parcial/total
          → entrega → cierre
```

**Todo configurable, nada hardcodeado a personas reales:** categorías, formularios, reglas,
aprobadores (por **grupo/perfil**, nunca por nombre), montos/umbrales, departamentos, estados
habilitados, SLA. La definición por defecto (`BORRADOR … CERRADA/CANCELADA`) se instala como
**definición de workflow parametrizable**, no como código de estados propio.

**Fuera de v1 (explícito):** escritura a Snipe-IT (es **SI-4**, §7); firma digital **certificada** real
(sólo el puerto `NullSigner` ya existente); IA/WhatsApp (sólo puntos de integración documentados, sin
implementar).

---

## 2. Reutilización de GLPI 11 (native-first — no duplicar lo nativo)

| Capacidad | Nativo reutilizado | Decisión (Configure/Extend/Integrate/Build) |
|---|---|---|
| Aislamiento y roles | `Entity` / `Group` / `Profile` / `ProfileRight` | **Configure** — ACL propia por `ProfileRight` (§9) |
| Proveedor | `Supplier` | **Integrate** — `suppliers_id_selected` referencia el `Supplier` nativo |
| Presupuesto / imputación | `Budget` | **Integrate** — `budgets_id` opcional |
| Costo del activo inventariado | `Infocom` | **Integrate** — se puebla al inventariar (vía handoff/SI-4), con el `unit_cost` de la línea |
| Cotizaciones / archivos | `Document` + `Document_Item` | **Integrate** — los adjuntos viven como Document nativo (N por cotización) |
| Notificaciones | Notificaciones nativas (plantillas/targets) | **Configure** — no construir motor de correo |
| Auditoría técnica | `Log` nativo + eventos propios | **Integrate** + **Build** (auditoría de negocio append-only, §8) |
| Estados / aprobaciones / SLA | `companyworkflow` (motor) | **Integrate** — **no** segundo motor (§3) |
| Evidencia + hash + PDF | `companysignature` | **Integrate** — snapshot canónico por scope (§4) |
| Entrada rica (ítems/quotes/montos) | GLPI **Forms** evaluado e **insuficiente** | **Build** — formulario propio delgado (ADR-0013 §Forms) |

**Regla:** no se recrea ningún modelo nativo. Sólo se crean tablas de dominio que lo nativo no modela
(solicitud, líneas, cotizaciones, recepción física, eventos de negocio, outbox). Ver §8.

---

## 3. Integración con `companyworkflow` (Compras **no** construye un segundo motor)

`companypurchasing` define el **proceso de negocio**; `companyworkflow` sigue siendo el único dueño de:
**estados/transiciones, aprobadores, quórum, delegación, SLA e invalidaciones.**

Contrato de uso (con las firmas reales de §0):
- **Iniciar:** al enviar una solicitud → `WorkflowApi::startInstance($def, 'PluginCompanypurchasingRequest', $requestId, $entityId)`.
  El `itemtype` es el **modelo de dominio** de la solicitud (un `CommonDBTM` propio), no un tipo core.
- **Avanzar:** cada acción de negocio → `WorkflowApi::transition($instance, $action, $ctx)`; las acciones
  disponibles se leen con `availableActions($instance)` (la UI nunca inventa botones).
- **Reaccionar:** `companypurchasing` **escucha** `companyworkflow:transitioned` para efectos de dominio
  (p. ej. checkpoint alcanzado → `composePdf` explícito §4; `receive` → recepción física + outbox §6/§7).
- **Snapshot de estado:** `..._requests.current_state_code` es **denormalización para listados/métricas**;
  la **fuente de verdad del estado es la instancia del motor**, nunca el snapshot.
- **Regla dura:** ningún estado, umbral, SLA ni aprobador se codifica en `companypurchasing`. Todo vive en
  la **definición** del workflow (parametrizable por entidad).

---

## 4. Integración con `companysignature` — **APPROVAL SCOPES / CHECKPOINT SNAPSHOTS** *[RECONCILIACIÓN]*

**No** se usa una única lista global de "campos sustantivos" que invalide **todas** las aprobaciones ante
cualquier cambio. Se definen **scopes de aprobación configurables**: cada etapa de aprobación protege un
**conjunto de campos** (su *scope*), y un cambio invalida **desde el checkpoint afectado**, no todo el
historial.

### Flujo por etapa de aprobación (firmas reales de §0)
```
antes de la etapa de aprobación X:
  build snapshot del SCOPE de X            (sólo los campos de ese scope; montos como string exacto §8)
      ↓
  SignatureApi::recordDocumentVersion($snapshot)  → DocumentVersion (id = document_versions_id,
                                                     document_version, content_sha256)
      ↓
  evidence_ref = { document_versions_id, document_version, content_sha256 }   // contrato REAL §0
      ↓
  WorkflowApi::transition($instance, $action, ['evidence_ref' => $evidenceRef, 'document_version' => …, …])
      ↓
  companysignature (listener/reconciliador) MATERIALIZA la EVIDENCIA por aprobador (valida existencia ·
      mismo sujeto/entidad · versión y content_sha256 coinciden).  El PDF NO se compone aquí.
      ↓
  si la etapa requiere PDF → companypurchasing llama EXPLÍCITAMENTE SignatureApi::composePdf($versionId)
      (on-demand, retryable/idempotente; su fallo no revierte la aprobación ni la evidencia — §0)
```

### Scopes baseline (configurables; se pueden ajustar por entidad sin tocar código)
```
REQUEST_SCOPE            (protege la aprobación del jefe de área)
  - solicitante
  - departamento
  - justificación / observación sustantiva
  - líneas
  - cantidades
  - categoría
  - destino
  - presupuesto / imputación (cuando corresponda)

COMMERCIAL_FINANCIAL_SCOPE  (protege la aprobación de Compras/Gerencia Financiera)
  - todo lo protegido de REQUEST_SCOPE que corresponda (superconjunto)
  - proveedor seleccionado
  - cotización seleccionada
  - final_unit_price por línea
  - descuentos
  - impuestos
  - flete / gastos
  - moneda
  - total final
```

### Invalidación por checkpoint (cómo se determina `reopen_to_code`)
- Cada scope tiene asociado el **checkpoint** (código de estado del workflow) que reabre si un campo de ese
  scope cambia tras haber sido aprobado. Al detectar el cambio, `companypurchasing`:
  1. arma el snapshot del **scope afectado** → `recordDocumentVersion` (nueva `document_version`);
  2. llama `WorkflowApi::invalidateApprovals($instanceId, $reason, ['idempotency_key' => …,
     'reopen_to_code' => <checkpoint del scope afectado>, 'evidence_ref' => …], $expectedVersion)`.
- `reopen_to_code` se **deriva del scope afectado** (mapa `scope → checkpoint`, configurable), no de una
  regla global. Se reabre **desde ese checkpoint**, conservando la evidencia histórica (append-only).

### Ejemplos (comportamiento esperado)
- Seleccionar **proveedor/cotización durante Compras** (campos de `COMMERCIAL_FINANCIAL_SCOPE`, **no** de
  `REQUEST_SCOPE`) **NO** invalida la aprobación previa del **jefe de área**.
- Cambiar la **cantidad de una línea** después de que el jefe la aprobó (campo de `REQUEST_SCOPE`) **SÍ**
  reabre desde el checkpoint del jefe.
- Cambiar el **`final_unit_price`** después de la aprobación financiera (campo de
  `COMMERCIAL_FINANCIAL_SCOPE`) invalida el **checkpoint financiero**.

**Contrato:** `companypurchasing` **nunca** compone la evidencia por su cuenta ni escribe en tablas de
`companysignature`; sólo llama a `SignatureApi`. Montos **siempre como string exacto** (§8). La distinción
por scope se implementa comparando el snapshot del scope (hash) — reutilizando
`companysignature/Service/SubstantiveChange` — no una lista global.

---

## 5. Recepción física (identidad canónica por unidad + recepciones parciales)

Modelo de conteo por línea: `ordered_qty`, `received_qty`, `pending_qty (= ordered − received)`.
Una línea `qty=N` se recibe en **uno o varios lotes** (recepción parcial).

```
receipt_batch (evento/lote de recepción, fechado, con actor)
└── receipt_unit  (UNA fila por unidad física)
      ├── receipt_unit_uuid   ← IDENTIDAD CANÓNICA (UUID inmutable, generado AL RECIBIR)
      ├── items_id            ← FK LÓGICA a ..._items.id (la línea real; NO por line_no) [RECONCILIACIÓN §4-tablas]
      ├── serial              ← serial de ESTA unidad (política de serial)
      ├── unit_cost           ← costo atribuible a la unidad (derivado de la LÍNEA, §6)
      └── estado (negocio)    ← RECIBIDA · ENTREGADA · … (estado FÍSICO/negocio, no la saga de integración)
```
- Recibir `qty 10` como `lote 4 + lote 6` genera **exactamente 10** `receipt_unit` (10 UUID distintos),
  **nunca** duplicadas. La unicidad la garantiza `UNIQUE(receipt_unit_uuid)`.
- `line_no`/`correlation_key = "purchase:<req>:item:<line>:unit:<n>"` son **sólo snapshot/display/
  correlación**, **no** identidad (una re-numeración de líneas no reasigna identidad; el UUID y el
  `items_id` son estables).

### Recepción + outbox: **atómico y concurrency-safe** *[RECONCILIACIÓN — el gate lo fija]*
La creación de las unidades y del handoff a SI-4 ocurre en **una sola transacción**, con la línea
**bloqueada** para evitar sobre-recepción concurrente:
```
BEGIN
  SELECT ... FROM ..._items WHERE id = :items_id FOR UPDATE          -- lock de la línea
  validar: received_qty + nueva_qty <= ordered_qty                   -- si no, ABORTAR (controlado)
  crear ..._receipt_batch
  crear N ..._receipt_units (UUID por unidad)
  crear N ..._inventory_outbox (sólo si la línea es is_inventoriable) -- §7
  actualizar received_qty / pending_qty de la línea
COMMIT
```
- **Atomicidad dura:** si falla la creación de **cualquier** `inventory_outbox`, se hace **ROLLBACK**
  completo. **Nunca** "`receipt_unit` confirmado pero handoff perdido".
- **Línea NO inventariable** → **no** se genera outbox SI-4 (no hay activo físico que crear).
- **Idempotencia de la operación de recepción:** además del `receipt_unit_uuid`, cada operación de
  recepción lleva su propia **`idempotency_key`** (p. ej. del request HTTP): reintentar la **misma**
  recepción no crea un segundo lote/unidades.
- **Concurrencia (`FOR UPDATE`):** dos workers que intenten recibir sobre la misma línea se serializan; el
  segundo revalida contra `received_qty` actualizado y **falla/recalcula de forma controlada** (nunca
  sobre-recibe). Ver test obligatorio en §10.

*[RECONCILIACIÓN — límite de ownership, ver §7]:* `companypurchasing` es dueño del **hecho de negocio**
(qué unidades físicas se recibieron, con qué serial y a qué costo). El **estado de la saga de integración**
(`saga_state`, `snipe_asset_id`, `asset_bridge`) es de **`companyintegrations` (SI-4)**, ligado por el
mismo `receipt_unit_uuid`. `asset-bridge-model.md` describe esa proyección; el gate aclara que la
**identidad de negocio nace en Compras** y **cruza el límite** vía el outbox (§7).

---

## 6. Costos y **precisión monetaria** (nunca float; nunca `total ÷ cantidad de activos`)

Costo **atribuible por línea/unidad**:
- `final_unit_price` — precio final por unidad de la **línea** (tras cotización seleccionada/negociación).
- `discount`, `tax`, `extra_expense` — ajustes **por línea**, con **política de asignación configurable**
  (incluidos o no en el costo del activo).
- `unit_cost` (derivado) = `final_unit_price` ± ajustes según política. **Prorrateo sólo cuando corresponda
  de verdad** (p. ej. un flete único a repartir), nunca como método por defecto.
- El futuro `Infocom` del activo recibe el **`unit_cost` de la unidad** (de la línea), **no** el total
  general dividido. **Proveedor del `Infocom` = el de la compra GLPI2.**

**Precisión monetaria (fijada ahora):**
- **Nunca `float`** en BD ni en servicios. Almacenamiento **decimal exacto** (`DECIMAL`) **o** *minor
  units* (enteros) con **política explícita** por moneda.
- **`scale` por moneda:** **PYG → `scale = 0`** (sin decimales); otras monedas admiten su `scale`
  configurado. La escala es dato de configuración, no un literal disperso.
- **Serialización probatoria:** el snapshot/API **siempre** serializa importes como **string exacto** para
  `companysignature` (el canonicalizador **rechaza floats**).

---

## 7. Límite con Snipe-IT y **contrato del outbox entre plugins** *[RECONCILIACIÓN]*

Hecho verificado: `companyintegrations` es **SI-1 read-only** y **no** ejecuta la saga SI-4.

**Decisión del gate:** `companypurchasing` v1 **NO escribe a Snipe-IT** y **no** contiene APIs de Snipe.
En `receive`, produce un **handoff/outbox idempotente** de unidades recibidas (creado atómicamente con la
recepción, §5).

### El outbox es de Compras, pero SI-4 lo consume por **API**, no por SQL directo
`..._inventory_outbox` **pertenece a `companypurchasing`**. `companyintegrations` **SI-4 NO** debe leer/
escribir por SQL contra tablas privadas de Compras. Se define desde el gate una **API pública
domain-agnostic** (contrato conceptual):
```
PurchasingIntegrationApi
  listPendingHandoffs(filtros, límite)           // PENDING elegibles (respeta ACL/entidad)
  claimHandoffs(worker, límite)  → LEASED        // toma un lote para procesar (lease/visibility)
  getHandoff(receipt_unit_uuid)                  // lectura por identidad canónica
  acknowledgeProcessed(receipt_unit_uuid, result)  → DONE
  markRetry(receipt_unit_uuid, reason, next_retry_at)  → RETRY
  markError(receipt_unit_uuid, reason)           → ERROR
```
- **Payload del handoff = INMUTABLE/versionado** (el hecho de negocio recibido: unidad, línea, costo,
  entidad, serial…). Sólo son **mutables** los campos de **entrega**: `status`, `attempts`, `last_error`
  (sin secretos), `next_retry_at`, `leased_by`/`leased_until`.
- **Estados del outbox (mínimo):** `PENDING · PROCESSING/LEASED · DONE · RETRY · ERROR`.
- **Idempotencia del límite:** `UNIQUE(receipt_unit_uuid)`; el futuro **SI-4 consume por API** y usa
  **`receipt_unit_uuid` como identidad de idempotencia** (buscar-primero antes de crear en Snipe).

### Flujo (resumen)
```
companypurchasing v1                                   companyintegrations SI-4 (FUTURO, fuera de este gate)
────────────────────                                   ─────────────────────────────────────────────────
receive (atómico §5): por cada receipt_unit (uuid):    claim/list por PurchasingIntegrationApi:
  inserta 1 fila ..._inventory_outbox                    getHandoff(uuid) → Snipe create → asset_bridge
  (PENDING, payload inmutable, UNIQUE(uuid))                       → resolver/crear activo GLPI (+Infocom)
                                                                   → companyqr → etiqueta
                                                         acknowledgeProcessed(uuid) → DONE  (o markRetry/markError)
```
- **Sin SI-4 aún:** el outbox queda en `PENDING` (visible en un tablero); el negocio de Compras funciona
  completo salvo el alta física del activo (responsabilidad de integración).
- Snipe `orders` **no** es el workflow (confirmado); el workflow es este módulo.

---

## 8. Tablas mínimas (propuesta — sólo las que lo nativo no modela)

Prefijo `glpi_plugin_companypurchasing_`. Todas con `entities_id`/`is_recursive`, migración **reversible**.
Importes en `DECIMAL` exacto o *minor units* (§6); **nunca float**.

| Tabla | Propósito | Clave / UNIQUE / idempotencia | FK lógica | Naturaleza | Retención |
|---|---|---|---|---|---|
| `..._requests` | Cabecera de solicitud | PK `id`; **UNIQUE `number`** (por entidad/año, §numbering) | `users_id`, `groups_id`, `suppliers_id_selected`→`Supplier`, `budgets_id`→`Budget`, **`quotes_id_selected`→`..._quotes`** (única referencia de cotización elegida), `workflow_instances_id`→instancia motor | Mutable (campos de negocio) | Larga (histórico) |
| `..._items` | Líneas de la solicitud | PK `id`; UNIQUE `(requests_id, line_no)` | `requests_id` | Mutable + contadores de recepción (`ordered/received/pending_qty`) | Con la solicitud |
| `..._quotes` | Cotizaciones | PK `id`; índice `(requests_id)` | `requests_id`, `suppliers_id`→`Supplier`; **adjuntos vía `Document`+`Document_Item` (N por cotización)** | Mutable | Con la solicitud |
| `..._events` | Auditoría de **negocio** | PK `id`; índice `(requests_id, date)` | `requests_id` | **Append-only** (no se borra) | Permanente |
| `..._receipt_batches` | Evento/lote de recepción | PK `id`; índice `(requests_id)`; `idempotency_key` de la operación | `requests_id`, actor | Append-only | Permanente |
| `..._receipt_units` | Unidad física recibida | PK `id`; **UNIQUE `receipt_unit_uuid`** | **`items_id`→`..._items.id`** (identidad de línea; `line_no` sólo snapshot), `receipt_batch_id` | Append-only (identidad) + `estado` de negocio mutable | Permanente |
| `..._inventory_outbox` | Handoff a SI-4 (§7) | PK `id`; **UNIQUE `receipt_unit_uuid`** | `receipt_units` | **payload inmutable/versionado** + `status`/`attempts`/`last_error`/`next_retry_at` mutables | Hasta consumido + auditoría |
| `..._numbering` | Secuencia de `number` | PK `id`; **UNIQUE `(entities_id, scope, year)`** | — | Mutable (`next_number`) | Permanente |

### Selección de cotización — sin boolean concurrente *[RECONCILIACIÓN]*
- La cotización elegida se referencia **una sola vez** en **`..._requests.quotes_id_selected`**. La tabla
  `..._quotes` **no** necesita un boolean `is_selected` como fuente de verdad (evita mantener "≤1
  seleccionada" sólo con lógica concurrente).
- Cambiar la selección = actualizar ese FK (con lock de la fila `requests` / `expectedVersion`), auditado
  en `..._events`. Ver test de selección concurrente en §10.
- Archivos de cada cotización = `Document` + `Document_Item` nativos (**N adjuntos** por cotización); no se
  depende de un único `documents_id`.

### Numeración — semántica explícita *[RECONCILIACIÓN]*
- **Por entidad/año (elegido):** columnas `entities_id, scope, year, next_number` con
  **`UNIQUE(entities_id, scope, year)`**; reintento ante colisión UNIQUE (patrón validado en `companyqr`).
- Si en el futuro se decidiera **numeración global**, se documentará **explícitamente** y se cambiará la
  clave; **no** se mezclan ambas semánticas.

- **Sin SQL directo a tablas core.** El estado del workflow **no** se duplica: sólo el snapshot
  `current_state_code`.
- **Borrado/retención:** las tablas append-only (`_events`, `_receipt_*`, `_outbox`) **no** se borran; se
  marca estado. Las mutables permiten edición pre-aprobación (edición sustantiva post-aprobación → §4).
  Uninstall = migración reversible (drop de tablas propias; core intacto).

---

## 9. ACL y multi-entidad (permisos separados, aislamiento estricto)

Derechos propios por `ProfileRight` (bitmask), **separados por acción** — ninguno hardcodea personas:

| Derecho | Permite |
|---|---|
| `purchasing:request_create` | crear una solicitud (BORRADOR) |
| `purchasing:request_view_own` | ver **las propias** |
| `purchasing:request_view_entity` | ver todas las de **su entidad** |
| `purchasing:manage` | gestionar compras (cotizar, seleccionar proveedor) |
| `purchasing:receive` | registrar recepción física |
| `purchasing:deliver` | registrar entrega |
| `purchasing:config` | administrar configuración (categorías, umbrales, definición, scopes §4) |
| `purchasing:metrics` | ver métricas/tableros |

- **Aislamiento multi-entidad (regla dura):** un usuario de la entidad A **nunca** ve/actúa solicitudes,
  evidencias ni recepciones de la entidad B sin autorización explícita (recursividad/entidad). Toda consulta
  y acción pasa por `Session::haveAccessToEntity()` + el derecho correspondiente. **Fail-closed.**
- Las aprobaciones se resuelven en `companyworkflow` (aprobador = **grupo/perfil**, con quórum/delegación),
  no con derechos de Compras.

---

## 10. Tests obligatorios (definidos desde el gate; se implementan tras aprobación)

**Unit:** numeración única (con reintento ante colisión); `amount_estimated` = suma de líneas;
**`unit_cost`** derivado de `final_unit_price` ± ajustes (**no** del total prorrateado); **precisión
monetaria** (sin float; PYG `scale=0`; serialización como string exacto); mapeo **scope → campos** y
**scope → `reopen_to_code`**.

**Integración / E2E (fail-closed):**
1. Solicitud completa **aprobada** (BORRADOR→…→APROBADA) por el motor.
2. **Rechazo** (`reject` → RECHAZADA).
3. **Devolución** (`return` → DEVUELTA, editable).
4. **Quórum** multi-aprobador (evidencia **por aprobador**).
5. **Scopes/checkpoints:**
   - jefe aprueba `REQUEST_SCOPE` → Compras agrega/selecciona cotización → **la aprobación del jefe sigue
     válida** (esos campos no son de `REQUEST_SCOPE`);
   - cambiar **quantity** de una línea tras la aprobación del jefe → **invalida/reabre** desde el checkpoint
     del jefe (`reopen_to_code` del scope de request);
   - cambiar **`final_price`** tras la aprobación financiera → **invalida el checkpoint financiero**.
   - En todos: nueva `document_version` + `invalidateApprovals(['idempotency_key','reopen_to_code'])`,
     evidencia histórica **conservada**.
6. **Recepción parcial 4+6** de una línea de 10 → **10 `receipt_unit`** (10 UUID), sin duplicar.
7. **Recepción concurrente:** `ordered_qty=10`, worker A recibe 6 y worker B recibe 6 → **máximo recibido =
   10, nunca 12**; la segunda operación **falla/recalcula** de forma controlada (`FOR UPDATE` §5).
8. **Atomicidad receipt+outbox:** si falla la creación de un `inventory_outbox` → **ROLLBACK**; nunca
   `receipt_unit` confirmado sin su handoff.
9. **Outbox duplicado/retry:** reintentar `receive` con el mismo `receipt_unit_uuid`/`idempotency_key` →
   **sin duplicar** handoff; transiciones `PENDING→LEASED→DONE`/`RETRY`/`ERROR` idempotentes por UUID.
10. **Selección concurrente de cotización:** dos selecciones simultáneas → una sola
    `requests.quotes_id_selected` consistente (sin dos "seleccionadas").
11. **Numeración concurrente por entidad/año:** N solicitudes simultáneas → `number` únicos, sin huecos por
    duplicado (reintento ante colisión `UNIQUE(entities_id, scope, year)`).
12. **Costo correcto por unidad** (`unit_cost` de la línea llega al outbox/Infocom, no el total ÷ cantidad).
13. **Dos unidades con seriales distintos** (misma línea, identidades separadas).
14. **Duplicado de `receipt_unit` rechazado** (violación de `UNIQUE(receipt_unit_uuid)`).
15. **Multi-entidad** (A no ve/actúa lo de B).
16. **ACL** (cada derecho gobierna su acción; fail-closed).
17. **PDF/evidencia:** checkpoint → `recordDocumentVersion` + **`composePdf` explícito**; el **fallo de PDF
    no revierte** la aprobación/evidencia; `verify` refleja el hash.
18. **Handoff generado pero SIN escribir a Snipe** (v1 sólo produce outbox `PENDING`; ninguna llamada a la
    API de Snipe; SI-4 consumiría por `PurchasingIntegrationApi`).

---

## Cumplimiento (Definition of Done del gate)
- **No implementación:** este gate no añade lógica; `plugins/companypurchasing` sigue siendo esqueleto.
- **Regla 0:** sólo APIs/hooks nativos + los tres plugins propios ya mergeados; **core intacto**.
- **Reconciliado con código real** (§0): `evidence_ref = {document_versions_id, document_version,
  content_sha256}`; PDF on-demand vía `composePdf` (sin Cron); límite SI-1 read-only ratificado.
- **Sin hardcode** de personas/umbrales/SLA/departamentos.
- **Pendiente de aprobación humana FINAL** para pasar a implementación (Fase 2D → 2E).

## Próximo paso (tras aprobación)
Recién con este gate aprobado se abriría la implementación de `companypurchasing` v1 (modelo + servicios +
controladores thin + i18n ES/EN + ACL + auditoría + tests + changelog), en su propia rama y PR Draft, con
CI verde y **sin** tocar el core.
