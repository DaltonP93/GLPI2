# Modelo de datos — `asset_bridge` y mapeos (integración Snipe-IT ↔ GLPI)

Vive en el plugin **`companyintegrations`** (prefijo `glpi_plugin_companyintegrations_*`).
Tablas **propias** (nunca DB de Snipe ni SQL directo a core). Migraciones reversibles.

## `..._asset_bridge` (mapeo 1:1 estable)
| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK | |
| `snipe_asset_id` | int | id interno del activo en Snipe |
| `snipe_asset_tag` | varchar | asset tag físico (Snipe = dueño) |
| `glpi_itemtype` | varchar(100) | clase del activo en GLPI (p. ej. `Computer`) |
| `glpi_items_id` | int | id del activo en GLPI |
| `glpi_entity_id` | int | entidad GLPI (aislamiento) |
| `companyqr_code_id` | int null | vínculo al código `companyqr` (Fase 1) |
| `sync_status` | enum(`mapped`,`pending`,`conflict`,`error`,`orphan_snipe`,`orphan_glpi`) | estado de sincronización |
| `source_version`/`etag` | varchar null | versión/etag de Snipe si está disponible (control de cambios) |
| `idempotency_key` | varchar **unique** | clave de idempotencia de la operación de alta (ver abajo) |
| `last_sync_at` | datetime null | última sincronización exitosa |
| `last_reconciled_at` | datetime null | última reconciliación |
| `last_error` | varchar(255) null | último error (sin secretos) |
| `date_creation`,`date_mod` | datetime | |

### Restricciones (identidad, no por nombre)
- `UNIQUE (snipe_asset_id)`
- `UNIQUE (snipe_asset_tag)`
- `UNIQUE (glpi_itemtype, glpi_items_id)`
- `UNIQUE (idempotency_key)`
- Índices por `sync_status`, `glpi_entity_id`.

### Identidad canónica e idempotencia — **por unidad física**
- **Identidad canónica = `receipt_unit_uuid`** (UUID interno inmutable), generado al **recibir
  físicamente** la unidad. Es la identidad **técnica** que atraviesa Snipe/GLPI/companyqr.
- `idempotency_key = "purchase:<req>:item:<line>:unit:<n>"` queda como **clave de correlación /
  debug legible por humanos**, **no** como única identidad técnica (una reimpresión o cambio de
  numeración de líneas no debe reasignar identidad; el UUID sí es estable).
- Cada unidad tiene **su propio** serial, `snipe_asset_id`/`snipe_asset_tag`, activo GLPI y fila
  `asset_bridge`. Reintentar el alta con el **mismo `receipt_unit_uuid`** **no** crea un segundo
  activo.
- **Idempotencia del request saliente a Snipe** (su API **no** tiene idempotency key nativa):
  antes de `POST /hardware`, el cliente hace **buscar-primero** (por `snipe_asset_id` ya
  persistido para ese `receipt_unit_uuid`, o por un marcador único que nosotros controlamos —
  serial); y **persiste `snipe_asset_id` inmediatamente** tras crear, de modo que un reintento
  tras `SNIPE_CREATED` **vincula**, no recrea.
- **Alta/lectura desde Snipe (SI-1):** identidad = `snipe_asset_id` (+ `snipe_asset_tag`). Un
  `asset_tag` **no** puede mapear a dos activos (garantizado por los UNIQUE).

## `..._receipt_units` (unidad física recibida — cardinalidad N, recepciones parciales, saga)
Modela cada **unidad física** recibida. Nace **al recibir físicamente** la unidad (no al aprobar):
una línea `qty=N` puede recibirse en **varios eventos/lotes** (recepción parcial). Es el ancla de
idempotencia y el origen de cada fila `asset_bridge`.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK | |
| `receipt_unit_uuid` | char(36) **unique** | **identidad canónica inmutable** (UUID) |
| `purchase_requests_id` | int | solicitud de compra (GLPI2) |
| `item_line_no` | int | línea de la solicitud |
| `unit_index` | int | n dentro de lo recibido de la línea |
| `receipt_batch_id` | int null | evento/lote de recepción (recepciones parciales) |
| `serial` | varchar null | serial de **esta** unidad (política de serial, ver ownership) |
| `unit_cost` | decimal(18,2) null | **costo atribuible a esta unidad** (derivado de la línea; ver técnico de compras) |
| `snipe_asset_id`,`snipe_asset_tag` | int/varchar null | activo físico en Snipe (cuando se cree) |
| `glpi_itemtype`,`glpi_items_id` | varchar/int null | activo GLPI vinculado (resolver-o-crear) |
| `asset_bridge_id` | int null | fila `asset_bridge` de esta unidad |
| `saga_state` | enum (ver saga) | `PENDING…LABEL_READY` + `RETRYABLE_ERROR/CONFLICT/MANUAL_REVIEW` |
| `last_confirmed_step` | varchar | último paso **confirmado** (reintento resume desde aquí) |
| `attempts` | int | intentos; para backoff/circuit breaker |
| `correlation_key` | varchar | `purchase:<req>:item:<line>:unit:<n>` (correlación/debug, no identidad) |
| `last_error` | varchar(255) null | último error (sin secretos) |
| `date_creation`,`date_mod` | datetime | |

- `UNIQUE (receipt_unit_uuid)`. La correlación humana **no** es UNIQUE por sí sola (permite
  re-numeración); la unicidad real la da el UUID.

## Saga de integración (estado persistente por unidad)
Snipe-IT, GLPI y `companyqr` **no comparten transacción**; el alta de una unidad es una **saga**
con estado persistente en `receipt_units.saga_state` y `last_confirmed_step`:
```
PENDING → SNIPE_CREATED → GLPI_RESOLVED_OR_CREATED → BRIDGED → QR_READY → LABEL_READY
   estados de excepción: RETRYABLE_ERROR · CONFLICT · MANUAL_REVIEW
```
- Cada paso, al confirmarse, **persiste** su resultado (p. ej. `snipe_asset_id` en `SNIPE_CREATED`)
  **antes** de avanzar. Un **reintento** continúa desde `last_confirmed_step` y **nunca** duplica
  (buscar-primero por `receipt_unit_uuid`/`snipe_asset_id`).
- `RETRYABLE_ERROR` → reintento con backoff (respeta circuit breaker). `CONFLICT` (p. ej. match
  ambiguo con GLPI Agent, serial en conflicto, compañía no mapeada) y `MANUAL_REVIEW` → **no**
  auto-avanzan; van al tablero de diferencias para decisión humana.
- La saga es **fail-closed**: si falla tras `SNIPE_CREATED` pero antes de `BRIDGED`, la unidad
  queda en ese estado con el `snipe_asset_id` guardado; al reintentar, **vincula** (no recrea).

## Estabilidad del QR ante cambio de `asset_tag` (una etiqueta impresa nunca se rompe)
- La **identidad estable** del activo puenteado es técnica (`asset_bridge.id` / `receipt_unit_uuid`),
  **no** el `asset_tag` (que Snipe podría renombrar).
- Se conserva un **alias histórico** de asset tags: tabla `..._asset_tag_aliases`
  (`id, asset_bridge_id, asset_tag, is_current(bool), valid_from, valid_to null`). El **gateway
  QR** resuelve `/asset/<asset_tag>` buscando **primero el actual y también los históricos** →
  una etiqueta física impresa con un tag viejo **sigue resolviendo** al mismo activo.
- **Política recomendada:** declarar el `asset_tag` **inmutable después de emitir la etiqueta**;
  si aun así se renombra en Snipe, el alias histórico garantiza que el QR impreso no quede roto.
- El QR de la etiqueta lo imprime Snipe con `plain_asset_tag`; por eso el gateway **debe** resolver
  también tags históricos (no se puede reimprimir todas las etiquetas físicas por un rename).

## Tablas de mapeo de catálogos (por **ID**, nunca por nombre)
`..._map_companies`, `..._map_users`, `..._map_locations`, `..._map_departments`,
`..._map_categories`, `..._map_models`, `..._map_states`, `..._map_suppliers`,
`..._map_manufacturers`.

Forma común: `id, snipe_id, snipe_name(cache), glpi_id, glpi_itemtype, entities_id,
is_approved(bool), notes`.
- El mapeo se **aprueba** explícitamente (`is_approved`) antes de usarse para crear/sincronizar
  (evita alta con catálogos mal alineados).
- `snipe_name` se guarda **sólo como cache/legibilidad**; la correlación real es por `*_id`.
- Estados: mapear `status label` de Snipe ↔ estado/uso en GLPI según política (tabla `..._map_states`).

### `..._map_companies` (compañía Snipe ↔ **entidad** GLPI) — obligatorio para multi-entidad
`snipe_company_id ↔ glpi_entity_id` (+ `is_approved`). **Regla dura:** un activo cuya
`company` de Snipe **no** esté mapeada a una entidad GLPI **no** se sincroniza a una entidad
**inferida**; queda en `conflict`/`pending`. Nunca se adivina la entidad. (Test multi-entidad
obligatorio; ver `snipeit-integration-test-plan.md`.)

### Identidad de usuarios (SoT = GLPI/IdP, **no** Snipe)
Snipe es Source of Truth de la **custodia** (checkout/checkin), **no** de la **identidad
corporativa**. El usuario corporativo lo define **GLPI / IdP**; `..._map_users` correlaciona
`snipe_user_id ↔ glpi_users_id` de forma **explícita y aprobada**. **Nunca** se correlacionan
usuarios automáticamente por nombre/email inferido.

## Ciclo de vida de una fila del bridge
```
Snipe crea/actualiza activo ─┐
Compras recibe (SI-4) ───────┼─► buscar-o-crear (idempotency_key POR UNIDAD / snipe_asset_id)
                             │        │
                             │        ├─ existe → actualizar reflejo (sync_status=mapped)
                             │        └─ no existe → RESOLVER-o-crear activo GLPI (dedup con GLPI
                             │             Agent por serial/UUID; ambiguo → conflict, sin auto-merge)
                             │             + companyqr → insertar bridge
Reconciliación (SI-1) ───────┘        │
                                      ├─ sólo en Snipe → orphan_snipe (reportar, no crear aún en read-only)
                                      ├─ sólo en GLPI  → orphan_glpi
                                      └─ divergencia de dueño → conflict (no auto-resolver)
Baja/purge del activo GLPI ──► marcar bridge (no borrar historial); companyqr se auto-revoca (Fase 1)
```

## Reglas
- **Nunca** usar nombres como clave permanente de correlación.
- **Nunca** borrar filas del bridge silenciosamente; los cambios quedan en auditoría (append-only).
- `companyqr_code_id` permite que el **gateway QR** resuelva `asset_tag → bridge → activo GLPI →
  ficha `companyqr``.
