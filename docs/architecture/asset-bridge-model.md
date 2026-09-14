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

### Idempotencia (no duplicar activos al reintentar) — **por unidad física**
- **Cardinalidad:** una línea de compra con cantidad **N** produce **N activos físicos**. La
  idempotencia es **por unidad**, no por línea:
  `idempotency_key = "purchase:<requests_id>:item:<line_no>:unit:<n>"` (n = 1..N).
  Cada unidad tiene **su propio** serial, `snipe_asset_id`/`snipe_asset_tag`, activo GLPI y fila
  `asset_bridge`. Reintentar `purchase.received` con la misma clave por unidad **no** crea un
  segundo activo.
- **Alta/lectura desde Snipe (SI-1):** identidad = `snipe_asset_id` (+ `snipe_asset_tag`). Un
  `asset_tag` **no** puede mapear a dos activos (garantizado por los UNIQUE).
- Toda escritura contra Snipe/GLPI usa **buscar-o-crear** por la clave natural antes de crear.

## `..._receipt_units` (unidad física recibida — cardinalidad N por línea)
Modela cada **unidad física** recibida para una línea de compra. Es el ancla de idempotencia por
unidad y el origen de cada fila `asset_bridge`.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK | |
| `purchase_requests_id` | int | solicitud de compra (GLPI2) |
| `item_line_no` | int | línea de la solicitud |
| `unit_index` | int | n = 1..N dentro de la línea |
| `serial` | varchar null | serial de **esta** unidad (política de serial, ver ownership) |
| `snipe_asset_id`,`snipe_asset_tag` | int/varchar null | activo físico en Snipe (cuando se cree) |
| `glpi_itemtype`,`glpi_items_id` | varchar/int null | activo GLPI vinculado (resolver-o-crear) |
| `asset_bridge_id` | int null | fila `asset_bridge` de esta unidad |
| `idempotency_key` | varchar **unique** | `purchase:<req>:item:<line>:unit:<n>` |
| `status` | enum(`pending`,`snipe_created`,`glpi_linked`,`labeled`,`conflict`,`error`) | avance |
| `date_creation`,`date_mod` | datetime | |

- `UNIQUE (purchase_requests_id, item_line_no, unit_index)` y `UNIQUE (idempotency_key)`.

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
