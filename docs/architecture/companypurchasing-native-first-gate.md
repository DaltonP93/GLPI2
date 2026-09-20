# Gate native-first — `companypurchasing` (Fase 2D)

> **Estado:** GATE de decisiones — **diseño, SIN implementación.** Fija el alcance de
> `companypurchasing` v1 antes de escribir una sola línea de lógica. Se detiene aquí para
> **aprobación humana**.
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
`companypurchasing` debe llamarlas tal cual; no inventa métodos.

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
  transición y el motor sólo la transporta en el ledger. *(Contrato clave para la firma, ver §4.)*

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
- `composePdf` genera el PDF aprobado como **`Document` nativo** — **retryable e idempotente**; hoy se
  invoca **on-demand** (no hay Cron que recomponga PDFs `pending`).

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
| Cotizaciones / archivos | `Document` + `Document_Item` | **Integrate** — los adjuntos viven como Document nativo |
| Notificaciones | Notificaciones nativas (plantillas/targets) | **Configure** — no construir motor de correo |
| Auditoría técnica | `Log` nativo + eventos propios | **Integrate** + **Build** (auditoría de negocio append-only, §8) |
| Estados / aprobaciones / SLA | `companyworkflow` (motor) | **Integrate** — **no** segundo motor (§3) |
| Evidencia + hash + PDF | `companysignature` | **Integrate** — snapshot canónico (§4) |
| Entrada rica (ítems/quotes/montos) | GLPI **Forms** evaluado e **insuficiente** | **Build** — formulario propio delgado (ADR-0013 §Forms) |

**Regla:** no se recrea ningún modelo nativo. Sólo se crean tablas de dominio que lo nativo no modela
(solicitud, líneas, cotizaciones, recepción física, eventos de negocio). Ver §8.

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
  (p. ej. `approved` → registrar evidencia + `composePdf`; `receive` → recepción física + outbox §7).
- **Snapshot de estado:** `..._requests.current_state_code` es **denormalización para listados/métricas**;
  la **fuente de verdad del estado es la instancia del motor**, nunca el snapshot.
- **Regla dura:** ningún estado, umbral, SLA ni aprobador se codifica en `companypurchasing`. Todo vive en
  la **definición** del workflow (parametrizable por entidad).

---

## 4. Integración con `companysignature` (evidencia probatoria en cada aprobación relevante)

Flujo por aprobación relevante (firmas reales de §0):
```
purchasing arma el SNAPSHOT canónico de la solicitud (campos SUSTANTIVOS, montos como string exacto)
      ↓
SignatureApi::recordDocumentVersion($snapshot)  → DocumentVersion { id, content_sha256, document_version }
      ↓
purchasing deriva evidence_ref = { version_id, content_sha256, subject_type/subject_id, entity_id, document_version }
      ↓
WorkflowApi::transition($instance, $action, ['evidence_ref' => $evidenceRef, ...])   // opaca para el motor
      ↓
companysignature (listener/reconciliador) materializa la EVIDENCIA por aprobador (valida existencia ·
      mismo sujeto/entidad · versión y content_sha256 coinciden) y compone el PDF aprobado (QR verify)
```

### Snapshot canónico de Compras — **campos SUSTANTIVOS** *[RECONCILIACIÓN — el gate los fija]*
Cambiar **cualquiera** de estos tras una aprobación exige: **nueva `document_version`** +
`WorkflowApi::invalidateApprovals(instanceId, reason, ['idempotency_key' => …])` + **nueva aprobación**,
**sin borrar** la evidencia histórica (append-only).

- Identidad: `request_id`, `entity_id`, `requester (users_id)`, `department (groups_id)`.
- Líneas (cada una): `line_no`, `description`, `category`, `quantity`, `unit`, `is_inventoriable`.
- Comercial: `suppliers_id_selected`, `selected_quote_id`, **`final_unit_price` por línea**, `discount`,
  `tax`, `extra_expense` (flete/gastos atribuibles), `currency`, `final_line_total`, **`amount_final` (total)**.
- Imputación (si aplica): `budgets_id` / centro de costo.
- `observations` **sustantivas** (las que cambian la decisión).

**NO sustantivos** (no invalidan): notas cosméticas, `current_state_code` (snapshot), timestamps de
sistema, campos de UI. La distinción se implementa con el flag `isSubstantive` de `recordDocumentVersion`
y con `companysignature/Service/SubstantiveChange` (comparación por hash).

**Contrato:** `companypurchasing` **nunca** compone la evidencia por su cuenta ni escribe en tablas de
`companysignature`; sólo llama a `SignatureApi`. Montos **siempre como string exacto** (el canonicalizador
rechaza floats).

---

## 5. Recepción física (identidad canónica por unidad + recepciones parciales + costo)

Modelo de conteo por línea (ya en el diseño técnico): `ordered_qty`, `received_qty`,
`pending_qty (= ordered − received)`. Una línea `qty=N` se recibe en **uno o varios lotes**
(recepción parcial).

```
receipt_batch (evento/lote de recepción, fechado, con actor)
└── receipt_unit  (UNA fila por unidad física)
      ├── receipt_unit_uuid   ← IDENTIDAD CANÓNICA (UUID inmutable, generado AL RECIBIR)
      ├── serial              ← serial de ESTA unidad (política de serial)
      ├── unit_cost           ← costo atribuible a la unidad (derivado de la LÍNEA, §6)
      └── estado (negocio)    ← RECIBIDA · ENTREGADA · … (estado FÍSICO/negocio, no la saga de integración)
```
- Recibir `qty 10` como `lote 4 + lote 6` genera **exactamente 10** `receipt_unit` (10 UUID distintos),
  **nunca** duplicadas. La unicidad la garantiza `UNIQUE(receipt_unit_uuid)`.
- `correlation_key = "purchase:<req>:item:<line>:unit:<n>"` es **sólo correlación/debug legible**, **no**
  identidad (una re-numeración de líneas no debe reasignar identidad; el UUID sí es estable).

*[RECONCILIACIÓN — límite de ownership, ver §7]:* `companypurchasing` es dueño del **hecho de negocio**
(qué unidades físicas se recibieron, con qué serial y a qué costo). El **estado de la saga de integración**
(`saga_state`, `snipe_asset_id`, `asset_bridge`) es de **`companyintegrations` (SI-4)**, ligado por el
mismo `receipt_unit_uuid`. `asset-bridge-model.md` describe esa proyección de integración; el gate aclara
que la **identidad de negocio nace en Compras** y **cruza el límite** vía el outbox (§7).

---

## 6. Costos (atribuible por línea/unidad — **nunca** `total ÷ cantidad de activos`)

Reglas explícitas (ya en el diseño técnico; el gate las ratifica):
- `final_unit_price` — precio final por unidad de la **línea** (tras cotización seleccionada/negociación).
- `discount`, `tax`, `extra_expense` — ajustes **por línea**, con **política de asignación configurable**
  (incluidos o no en el costo del activo).
- `unit_cost` (derivado) = `final_unit_price` ± ajustes según política. **Prorrateo sólo cuando
  corresponda de verdad** (p. ej. un flete único a repartir), nunca como método por defecto.
- **PYG sin decimales** donde aplique (moneda por defecto); importes representados de forma exacta.
- El futuro `Infocom` del activo recibe el **`unit_cost` de la unidad** (de la línea), **no** el total
  general dividido. **Proveedor del `Infocom` = el de la compra GLPI2.**

---

## 7. Límite con Snipe-IT — **SI-1 (read-only) hoy; SI-4 (futuro) hará la escritura** *[RECONCILIACIÓN]*

Hecho verificado: `companyintegrations` es **SI-1 read-only** y **no** ejecuta la saga SI-4.

**Decisión del gate:** `companypurchasing` v1 **NO escribe a Snipe-IT** y **no** contiene APIs de Snipe.
En `receive`, produce un **handoff/outbox idempotente** de unidades recibidas:

```
companypurchasing v1                                   companyintegrations SI-4 (FUTURO, fuera de este gate)
────────────────────                                   ─────────────────────────────────────────────────
receive → por cada receipt_unit (uuid):                consume outbox (idempotente por receipt_unit_uuid):
  inserta 1 fila en ..._inventory_outbox               receipt_unit → Snipe create → asset_bridge
  (append-only, UNIQUE(receipt_unit_uuid),                        → resolver/crear activo GLPI (+Infocom)
   status = PENDING, payload de negocio)                          → companyqr → etiqueta
```
- **Ownership preservado:** las APIs de Snipe viven en `companyintegrations`, no en Compras. Compras sólo
  declara "recibí estas unidades a este costo" (fuente de verdad de negocio).
- **Idempotencia del límite:** `UNIQUE(receipt_unit_uuid)` en el outbox ⇒ reintentar `receive` **no**
  duplica el handoff; SI-4 será idempotente por el mismo UUID (buscar-primero antes de crear en Snipe).
- **Sin SI-4 aún:** hasta que exista, el outbox queda en `PENDING` (visible en un tablero) — el negocio de
  Compras funciona completo salvo el alta física del activo, que es responsabilidad de integración.
- Snipe `orders` **no** es el workflow (confirmado); el workflow es este módulo.

---

## 8. Tablas mínimas (propuesta — sólo las que lo nativo no modela)

Prefijo `glpi_plugin_companypurchasing_`. Todas con `entities_id`/`is_recursive`, migración **reversible**.

| Tabla | Propósito | Clave / UNIQUE / idempotencia | FK lógica | Naturaleza | Retención |
|---|---|---|---|---|---|
| `..._requests` | Cabecera de solicitud | PK `id`; **UNIQUE `number`** (por entidad/año) | `users_id`, `groups_id`, `suppliers_id_selected`→`Supplier`, `budgets_id`→`Budget`, `workflow_instances_id`→instancia motor | Mutable (campos de negocio) | Larga (histórico) |
| `..._items` | Líneas de la solicitud | PK `id`; UNIQUE `(requests_id, line_no)` | `requests_id` | Mutable + contadores de recepción | Con la solicitud |
| `..._quotes` | Cotizaciones | PK `id`; **≤1** `is_selected` por solicitud (regla) | `requests_id`, `suppliers_id`→`Supplier`, `documents_id`→`Document` | Mutable | Con la solicitud |
| `..._events` | Auditoría de **negocio** | PK `id`; índice `(requests_id, date)` | `requests_id` | **Append-only** (no se borra) | Permanente |
| `..._receipt_batches` | Evento/lote de recepción | PK `id`; índice `(requests_id)` | `requests_id`, actor | Append-only | Permanente |
| `..._receipt_units` | Unidad física recibida | PK `id`; **UNIQUE `receipt_unit_uuid`** | `requests_id`, `item_line_no`, `receipt_batch_id` | Append-only (identidad) + `estado` de negocio mutable | Permanente |
| `..._inventory_outbox` | Handoff idempotente a SI-4 | PK `id`; **UNIQUE `receipt_unit_uuid`** | `receipt_units` | Append-only + `status` | Hasta consumido + auditoría |
| `..._numbering` | Secuencia de `number` | PK `id`; UNIQUE `(scope, year)` | — | Mutable (contador) | Permanente |

- **Sin SQL directo a tablas core.** El estado del workflow **no** se duplica: sólo el snapshot
  `current_state_code`.
- **Borrado/retención:** las tablas append-only (`_events`, `_receipt_*`, `_outbox`) **no** se borran; se
  marca estado. Las mutables permiten edición pre-aprobación (una edición sustantiva post-aprobación pasa por
  §4). Uninstall = migración reversible (drop de tablas propias; core intacto).

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
| `purchasing:config` | administrar configuración (categorías, umbrales, definición) |
| `purchasing:metrics` | ver métricas/tableros |

- **Aislamiento multi-entidad (regla dura):** un usuario de la entidad A **nunca** ve/actúa solicitudes,
  evidencias ni recepciones de la entidad B sin autorización explícita (recursividad/entidad). Toda consulta
  y acción pasa por `Session::haveAccessToEntity()` + el derecho correspondiente. **Fail-closed.**
- Las aprobaciones se resuelven en `companyworkflow` (aprobador = **grupo/perfil**, con quórum/delegación),
  no con derechos de Compras.

---

## 10. Tests obligatorios (definidos desde el gate; se implementan tras aprobación)

**Unit:** numeración única (con reintento ante colisión); `amount_estimated` = suma de líneas;
**≤1** cotización `is_selected`; **`unit_cost`** derivado de `final_unit_price` ± ajustes (**no** del total
prorrateado); distinción **sustantivo vs no sustantivo** (por hash).

**Integración / E2E (fail-closed):**
1. Solicitud completa **aprobada** (BORRADOR→…→APROBADA) por el motor.
2. **Rechazo** (`reject` → RECHAZADA).
3. **Devolución** (`return` → DEVUELTA, editable).
4. **Cambio sustantivo tras aprobar** → nueva `document_version` + `invalidateApprovals` + **reaprobación**
   (evidencia histórica conservada).
5. **Quórum** multi-aprobador (evidencia **por aprobador**).
6. **Recepción parcial 4+6** de una línea de 10 → **10 `receipt_unit`** (10 UUID), sin duplicar.
7. **Reintentos idempotentes** del `receive`/outbox (mismo `receipt_unit_uuid` → sin duplicar handoff).
8. **Costo correcto por unidad** (`unit_cost` de la línea llega al outbox/Infocom, no el total ÷ cantidad).
9. **Dos unidades con seriales distintos** (misma línea, identidades separadas).
10. **Duplicado de `receipt_unit` rechazado** (violación de `UNIQUE(receipt_unit_uuid)`).
11. **Multi-entidad** (A no ve/actúa lo de B).
12. **ACL** (cada derecho gobierna su acción; fail-closed).
13. **PDF/evidencia** (aprobación → `recordDocumentVersion` + `composePdf`; verify refleja hash).
14. **Handoff generado pero SIN escribir a Snipe** (v1 sólo produce outbox `PENDING`; ninguna llamada a
    la API de Snipe).

---

## Cumplimiento (Definition of Done del gate)
- **No implementación:** este gate no añade lógica; `plugins/companypurchasing` sigue siendo esqueleto.
- **Regla 0:** sólo APIs/hooks nativos + los tres plugins propios ya mergeados; **core intacto**.
- **Reconciliado con código real** (§0): firmas de `WorkflowApi`/`SignatureApi` verificadas; límite SI-1
  read-only ratificado.
- **Sin hardcode** de personas/umbrales/SLA/departamentos.
- **Pendiente de aprobación humana** para pasar a implementación (Fase 2D → 2E).

## Próximo paso (tras aprobación)
Recién con este gate aprobado se abriría la implementación de `companypurchasing` v1 (modelo + servicios +
controladores thin + i18n ES/EN + ACL + auditoría + tests + changelog), en su propia rama y PR Draft, con
CI verde y **sin** tocar el core.
