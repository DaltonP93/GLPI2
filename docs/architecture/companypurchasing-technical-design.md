# Diseño técnico — `companypurchasing`

Complementa `../adr/ADR-0013-companypurchasing.md`. **Diseño, sin implementación.** Consume
`companyworkflow` (no duplica motor) y reutiliza nativo (Supplier/Budget/Infocom/Document/
Entity/Group/Notif/Log/TCPDF/API/Webhooks). **Sin tocar el core.**

## Estructura (PSR-4 `GlpiPlugin\Companypurchasing\`)
```
plugins/companypurchasing/
  setup.php  hook.php
  src/
    Model/       Request · RequestItem · Quote · RequestEvent
    Service/     RequestManager · NumberingService · WorkflowBinding · InventoryHandoff · MetricsService · PdfComposer(bridge companysignature)
    Controller/  RequestController · InboxController · AdminController (thin, AUTHENTICATED, CSRF nativo)
  templates/     request_new · request_view · inbox · compras_view · finance_view · history · (PDF via companysignature)
  locales/ tests/
```

## Modelo de datos (tablas propias, migración reversible)
Prefijo `glpi_plugin_companypurchasing_`.

### `..._requests`
| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK | |
| `number` | varchar **unique** | nº de solicitud legible (`NumberingService`, por entidad/año) |
| `users_id_requester` | int | solicitante |
| `groups_id_department` | int | departamento/área (Group nativo) |
| `entities_id`,`is_recursive` | int/tinyint | **aislamiento multi-entidad** |
| `date_request` | datetime | |
| `reason` | text | motivo/justificación |
| `category` | int (dropdown propio o `ITILCategory`) | categoría |
| `destination` | varchar | destino/uso |
| `suggested_supplier` | varchar | lugar/proveedor sugerido (texto libre) |
| `suppliers_id_selected` | int null | **proveedor seleccionado** (`Supplier` nativo) |
| `budgets_id` | int null | imputación (`Budget` nativo) |
| `currency` | char(3) default `PYG` | moneda |
| `amount_estimated` | decimal(18,2) | precio estimado (suma de ítems) |
| `amount_approved` | decimal(18,2) null | monto aprobado |
| `amount_final` | decimal(18,2) null | monto final de compra |
| `observations` | text | |
| `workflow_instances_id` | int | **instancia de `companyworkflow`** (estado vive allí) |
| `current_state_code` | varchar | **snapshot** del estado (denormalizado para listas/métricas) |
| `date_creation`,`date_mod` | datetime | |

### `..._items` (uno o varios por solicitud)
`id, requests_id, line_no, description, quantity, unit, category, estimated_unit_price,
estimated_line_total, is_inventoriable(bool), notes`.

**Cantidades de recepción (recepción parcial):** una línea `qty=N` puede recibirse en varios
eventos/lotes; el modelo lleva el conteo:
- `ordered_qty` — cantidad pedida (normalmente = `quantity`).
- `received_qty` — acumulado recibido (suma de `receipt_unit` de la línea).
- `pending_qty` — derivable (`ordered_qty − received_qty`); una línea puede quedar **parcialmente
  recibida** hasta completar `N`.

**Costos por línea (NO prorratear el total general):** el costo del activo **no** se calcula
dividiendo el total general de la solicitud/cotización entre los activos. Se registra:
- `final_unit_price` — **precio final por unidad de la línea** (tras negociación/cotización
  seleccionada).
- `final_line_total` — total final de la línea.
- **Ajustes opcionales por línea:** `discount`, `tax`, `extra_expense` (flete/instalación/otros),
  con política de asignación configurable (incluidos o no en el costo del activo).
- `unit_cost` (derivado) — costo atribuible a **cada unidad** de esa línea (= `final_unit_price`
  ± ajustes según política). Es el valor que viaja a `receipt_unit.unit_cost` y a `Infocom`.

Al recibir, los ítems `is_inventoriable` disparan el handoff a inventario/Snipe/`companyqr`; cada
unidad hereda su `unit_cost` **de la línea**, no del total general.

### `..._quotes` (cotizaciones)
`id, requests_id, suppliers_id(null)/supplier_text, documents_id(Document nativo), amount,
currency, valid_until, is_selected(bool), notes`. Los archivos viven como `Document` +
`Document_Item` (nativo).

### `..._events` (auditoría de negocio, append-only)
Espejo semántico de compras; ver `../security/phase2-acl-audit.md`. No se borra.

## Estados
Los estados y el circuito **son los de `companyworkflow`** (definición por defecto de compras,
ver `companyworkflow-technical-design.md` §Máquina de estados / §Matriz). `companypurchasing`
**no** define su propio motor; sólo:
- fija la **definición por defecto** (`BORRADOR … CERRADA/CANCELADA`) al instalar (parametrizable);
- mantiene `current_state_code` como **snapshot** para listados/tableros (fuente de verdad = la
  instancia del motor);
- reacciona a `companyworkflow:transitioned` (p. ej. `receive` → `InventoryHandoff`;
  `approved` → generar PDF aprobado).

## Numeración
`NumberingService`: `<PREFIJO_ENTIDAD>-<AÑO>-<secuencia>` único por alcance configurable;
la secuencia vive en tabla propia (nunca el `items_id`), con reintento ante colisión UNIQUE
(patrón validado en `companyqr`).

## Integración inventario + Snipe-IT + companyqr (resumen; detalle en el doc de integración)
`RECIBIDA` + ítem `is_inventoriable` → **una unidad física (`receipt_unit`) por unidad recibida**,
admitiendo **recepciones parciales** (una línea `qty=N` se puede recibir en varios lotes). Cada
`receipt_unit` tiene **identidad canónica `receipt_unit_uuid`** (UUID interno inmutable); el
`InventoryHandoff` es **idempotente por ese UUID** (la clave `purchase:<req>:item:<line>:unit:<n>`
es sólo **correlación/debug**). El alta de cada unidad es una **saga** con estado persistente
(reintento resume desde el último paso confirmado, nunca duplica). Por cada unidad: **1)** resolver
**entidad** por company/entity mapping (no mapeada → conflicto) → **2)** crear activo en **Snipe-IT**
(API; dueño del asset tag/físico; **idempotencia saliente por buscar-primero**) + serial de la
unidad → **3)** `asset_bridge` → **4)** **RESOLVER-o-crear** el **activo GLPI** (dedup con GLPI
Agent por serial/UUID; ambiguo → conflicto, sin auto-merge) + poblar **`Infocom`** con el **costo
atribuible a esa unidad** (`unit_cost` derivado de la **línea**, **no** del total general
prorrateado; **proveedor = el de la compra GLPI2**) → **5)** **generar `companyqr`** → **6)**
etiqueta con el motor de Snipe (QR → gateway GLPI2). Ver ADR-0015 y
`snipeit-integration-architecture.md` §Flujo D. **No** se usa `orders` de Snipe como workflow (no
lo es). Sujeto a ACL de entidad + cuenta de servicio Snipe con rol restringido (RBAC por usuario;
sin scopes por endpoint).

## Métricas (preparadas desde el modelo)
Derivables de `..._requests`/`..._items`/`..._events`/instancia de workflow, por entidad:
- **Tiempos:** total solicitud→cierre; **por etapa** (de `..._history` del motor); tiempo
  promedio de Compras; cumplimiento de **SLA** por etapa; **cuellos de botella** (etapa con más
  tiempo/acumulación).
- **Volumen:** solicitudes por área; pendientes por etapa; aprobaciones/rechazos/devoluciones.
- **Montos (PYG):** solicitado / aprobado / comprado; por área; por categoría; por proveedor;
  **ahorro estimado** (estimado − final).
- Todas segmentables por entidad y rango de fechas; expuestas por API/tablero (Fase futura
  `companydashboard`), no por acceso directo a tablas core.

## Plan de tests (tras aprobación)
- **Unit:** numeración (unicidad/reintento); cálculo de `amount_estimated` (suma de líneas);
  selección de cotización (una sola `is_selected`); **costo por línea** (`unit_cost` derivado de
  `final_unit_price` ± descuento/impuesto/gasto, **no** del total general prorrateado).
- **Integración (fail-closed):** 🔒 multi-entidad (no ver/actuar solicitudes de otra entidad);
  ciclo completo por el motor; snapshot de estado consistente con la instancia; handoff a
  inventario **idempotente por `receipt_unit_uuid`** (incluye **recepción parcial**: 4+6 de una
  línea de 10 sin duplicar); `Infocom` recibe el `unit_cost` de la línea; auditoría de cambios de
  monto/ítems/proveedor.
- **E2E HTTP:** nueva solicitud → envío → aprobación jefe → compras → gerencia → PDF aprobado.
