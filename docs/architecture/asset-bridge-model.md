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

### Idempotencia (no duplicar activos al reintentar)
- **Alta desde Compras (SI-4):** `idempotency_key = "purchase:<requests_id>:item:<line_no>"`.
  Reintentar `purchase.received` con la misma clave **no** crea un segundo activo Snipe ni GLPI;
  la operación busca primero el bridge por esa clave.
- **Alta/lectura desde Snipe (SI-1):** identidad = `snipe_asset_id` (+ `snipe_asset_tag`). Un
  `asset_tag` **no** puede mapear a dos activos (garantizado por los UNIQUE).
- Toda escritura contra Snipe/GLPI usa **buscar-o-crear** por la clave natural antes de crear.

## Tablas de mapeo de catálogos (por **ID**, nunca por nombre)
`..._map_users`, `..._map_locations`, `..._map_departments`, `..._map_categories`,
`..._map_models`, `..._map_states`, `..._map_suppliers`, `..._map_manufacturers`.

Forma común: `id, snipe_id, snipe_name(cache), glpi_id, glpi_itemtype, entities_id,
is_approved(bool), notes`.
- El mapeo se **aprueba** explícitamente (`is_approved`) antes de usarse para crear/sincronizar
  (evita alta con catálogos mal alineados).
- `snipe_name` se guarda **sólo como cache/legibilidad**; la correlación real es por `*_id`.
- Estados: mapear `status label` de Snipe ↔ estado/uso en GLPI según política (tabla `..._map_states`).

## Ciclo de vida de una fila del bridge
```
Snipe crea/actualiza activo ─┐
Compras recibe (SI-4) ───────┼─► buscar-o-crear (idempotency_key / snipe_asset_id)
                             │        │
                             │        ├─ existe → actualizar reflejo (sync_status=mapped)
                             │        └─ no existe → crear activo GLPI + companyqr → insertar bridge
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
