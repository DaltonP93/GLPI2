# ADR-0021: SI-4 (incremento SI4-2) — resolver-o-crear el activo GLPI, Infocom y AssetBridge en la misma saga

- **Estado:** Aceptado — SI4-2 implementado y mergeado con aprobación humana: PR #18 (`ff61c17`). El worker SI-4 sigue **deshabilitado** (`si4_enabled = 0`): ver `docs/operations/si4-readiness.md`.
  *(Estado original: "Propuesto (SI4-2 implementado; pendiente de revisión humana)".)*
- **Fecha:** 2026-09-30
- **Decisores:** Producto, TI/activos, finanzas, seguridad, plataforma
- **Módulo/área:** `plugins/companyintegrations` (0.3.0 → 0.4.0)
- **Complementa:** ADR-0015 (§10 saga, §14 costo por unidad), ADR-0019 (handoff v1), ADR-0020 (saga SI4-1, fencing).

## Contexto
SI4-1 deja cada unidad recibida en `SNIPE_CREATED` (activo físico en Snipe-IT verificado, `snipe_asset_id` persistido,
outbox de Compras abierto). SI4-2 continúa **la misma saga** hasta el activo técnico/CMDB en GLPI, su `Infocom` y el
puente estable `asset_bridge`. Riesgos a cerrar: duplicar activos que el **GLPI Agent** ya inventarió, crear un
segundo activo si el proceso cae después de `add()` y antes de guardar el id, redondear el costo en silencio y
reasignar puentes. Todo sin tocar el core ni hacer SQL sobre tablas del core.

### Hechos verificados en GLPI **11.0.8** (tag `11.0.8`, commit `1056d00`; el tarball de release usado en CI es idéntico en los archivos citados)
| # | Hecho | Evidencia |
|---|---|---|
| 1 | `Computer`, `Monitor`, `NetworkEquipment`, `Peripheral`, `Phone`, `Printer` tienen `serial`, `otherserial`, `entities_id`, `is_recursive`, FK de modelo, `is_deleted`, `is_template`, `is_dynamic` | `install/mysql/glpi-empty.sql` |
| 2 | `serial`/`otherserial` sólo tienen **KEY** (no UNIQUE): la BD no impide duplicados | idem |
| 3 | `otherserial` es el **"Número de inventario"** nativo (UI y búsqueda, opción 6) | `CommonDBTM.php:3518`, `Search/SearchOption.php:230` |
| 4 | El inventario (GLPI Agent) escribe `otherserial` desde el `assettag` del BIOS/equipo | `Inventory/MainAsset/MainAsset.php:384`, `NetworkEquipment.php:91` |
| 5 | Un `update()` NO-inventario de un activo dinámico crea **Lockedfield** de los campos tocados (el agente no los pisa) | `CommonDBTM::manageLocks()`, `Lockedfield::isHandled()` |
| 6 | API soportada: `CommonDBTM::find($condition)` (consulta sin restricción de entidad), `getFromDB`, `add($input, $options)` (opción `disable_infocom_creation`), `update`, `can($id, $right, $input)` (derecho + entidad), `getModelClass()`/`getModelForeignKeyField()` | `CommonDBTM.php` 610, 1288, 1642, 2954, 6080-6114 |
| 7 | `add()` ejecuta hooks `pre_item_add`, reglas de negocio `RuleAsset::ONADD` y `FieldUnicity`: puede **alterar o rechazar** la entrada | `CommonDBTM::add()` |
| 8 | `glpi_infocoms`: `UNIQUE(itemtype, items_id)`; `value` `decimal(20,4)`; `buy_date` (compra), `order_date`, `delivery_date`, `use_date`, `warranty_date`; `suppliers_id`; `order_number`; **sin columna de moneda** | `glpi-empty.sql` |
| 9 | `Infocom::getFromDBforDevice($itemtype, $id)`; `Infocom::prepareInputForAdd()` rechaza un segundo Infocom del mismo activo; derechos `infocom` CREATE/UPDATE | `Infocom.php` 266, 432, 2204-2227 |
| 10 | `auto_create_infocoms` (por defecto `0`) crea un Infocom vacío tras `add()` salvo `disable_infocom_creation` | `CommonDBTM::add()`, `install/empty_data.php` |
| 11 | `Supplier` es un `CommonDBTM` con papelera (`is_deleted`), `entities_id` e `is_recursive`; `update()` puede cambiarle la entidad | `Supplier.php`, `CommonDBTM::update()` |
| 12 | `getAncestorsOf()`/`getSonsOf()` leen `ancestors_cache`/`sons_cache` y `$GLPI_CACHE`, que pueden quedar desactualizados al mover una entidad; `Entity::getFromDB()` lee el `entities_id` vigente | `src/DbUtils.php`, `Entity.php` |

Snipe-IT: SI4-2 sólo **relee** con `GET /api/v1/hardware/bytag/{tag}?deleted=true`, contrato ya verificado en v8.7.2 (ADR-0020, hechos 2, 6 y 10). No hay escrituras nuevas en Snipe.

## Decisión (Configure / Extend / Integrate / **Build** acotado)
Configuración nativa no alcanza (GLPI no consume el handoff de Compras); ningún plugin existente lo hace; se **extiende**
el plugin propio con API soportada de GLPI (`CommonDBTM`, `Infocom`, `Supplier`, `Session`).

1. **Estados (continúan la saga SI4-1).** `SNIPE_CREATED → GLPI_RESOLVED_OR_CREATED → INFOCOM_READY → BRIDGED`, más
   `BLOCKED_CONFIG` (con `resume_state`: la etapa desde la que se reanuda) y `MANUAL_REVIEW`. `BRIDGED` es el final de
   SI4-2: la unidad queda **estacionada**, sin `acknowledgeProcessed()`; el outbox sigue abierto para SI4-3.
2. **Mapeo aprobado y versionable** — tabla propia `map_glpi_assettypes` (`category_key` UNIQUE → `glpi_itemtype`,
   `glpi_model_id` opcional, `is_approved`). Es separada de `map_models` (Snipe) para no mezclar responsabilidades.
   Clave = código de categoría de la línea de compra (el mismo que usa `map_models`), comparación exacta.
   - mapeo ausente/no aprobado ⇒ `BLOCKED_CONFIG`;
   - `itemtype` fuera de los soportados ⇒ `BLOCKED_CONFIG`;
   - modelo inexistente o de otra clase ⇒ `BLOCKED_CONFIG`.

   **Pinning por saga.** El primer uso válido del mapeo fija el destino en la saga: `glpi_mapping_id`,
   `glpi_itemtype`, `glpi_model_id` y `glpi_mapping_hash` (sha256 de esos valores y la categoría). Las cuatro columnas
   se escriben juntas, una sola vez (el store exige `glpi_mapping_hash IS NULL`), y quedan como snapshot histórico.
   Desde entonces la saga **nunca relee el mapeo vivo**: si el administrador cambia `NOTEBOOK → Computer + modelo 10`
   por `modelo 20`, el retry de la misma saga sigue con el 10 y sólo las unidades nuevas usan el 20.
   - pin con huella distinta ⇒ `MANUAL_REVIEW` (`glpi_pin_corrupt`);
   - destino pinneado que dejó de ser válido (p. ej. modelo eliminado) ⇒ `MANUAL_REVIEW` (`glpi_pin_invalid`); nunca
     se cambia de modelo en silencio;
   - saga con itemtype o activo pero sin pin ⇒ `MANUAL_REVIEW` (`glpi_pin_missing`).
   Soportados en SI4-2 (idempotencia demostrada por la tabla del hecho 1): `Computer`, `Monitor`, `NetworkEquipment`,
   `Peripheral`, `Phone`, `Printer`. Los activos personalizados (`Glpi\Asset\Asset`) quedan fuera hasta probarlos.
3. **Identidad determinista observable en GLPI = `otherserial` (número de inventario) = `asset_tag` de la saga.** Es el
   identificador físico que ya rotula la unidad en Snipe (derivado de `receipt_unit_uuid`, ADR-0020) y se conoce **antes**
   de `add()`. Buscar primero con `find()` por `otherserial` = tag **y** por `serial` (si la unidad lo trae), en **todas**
   las entidades (para detectar candidatos ajenos y no adoptarlos), excluyendo plantillas e incluyendo la papelera.
4. **Clasificación fail-closed (`GlpiCandidateMatcher`, puro)** sobre la unión de candidatos:

   | Situación | Resultado |
   |---|---|
   | ningún candidato | **crear** |
   | exactamente 1, en la entidad de la unidad, serial coincidente (o la unidad sin serial), por tag | **vincular** |
   | exactamente 1 por serial (p. ej. del GLPI Agent) con `otherserial` vacío | **vincular** y **reclamar** `otherserial` = tag |
   | > 1 candidato (dos por serial, uno por tag y otro por serial…) | `MANUAL_REVIEW` (`ambiguous`), no se modifica ninguno |
   | candidato en otra entidad | `MANUAL_REVIEW` (`entity_mismatch`), no se adopta ni se crea otro |
   | serial distinto (incluye diferencias de mayúsculas) | `MANUAL_REVIEW` (`serial_conflict`) |
   | `otherserial` no vacío y distinto del tag | `MANUAL_REVIEW` (`otherserial_conflict`): nunca se pisa un número de inventario humano |
   | candidato en la papelera | `MANUAL_REVIEW` (`deleted`) |

   El modelo **no** es criterio de identidad (el agente asigna su propio modelo); no se modifica en activos existentes.
5. **Crear sólo con la API nativa**: intención persistida (`glpi_create_calls`) con lease restante ≥ presupuesto de
   escritura; `add()` con `entities_id`, `is_recursive=0`, `serial`, `otherserial`, FK de modelo (si hay) y `name` =
   descripción de la línea, con `disable_infocom_creation` (el Infocom lo escribe la etapa siguiente, un solo
   escritor). El id se guarda **sin verificar** y se verifica con la misma búsqueda: debe resultar exactamente ese
   activo, en la entidad correcta, con serial y tag intactos (las reglas o una carrera con el agente ⇒
   `MANUAL_REVIEW`). Crash después de `add()` ⇒ el retry lo encuentra por `otherserial` ⇒ **mismo activo**.
   Si la saga ya registró un id y la búsqueda no lo devuelve ⇒ `MANUAL_REVIEW`, nunca un segundo `add()`.
6. **Vincular un activo existente** modifica sólo `otherserial` y sólo si estaba vacío (si es dinámico, GLPI lo bloquea
   frente al agente, hecho 5). Nada más del activo existente se toca. **Post-verificación del reclamo:** después de
   `setInventoryNumber()` se repite la búsqueda completa (`findCandidates` + `GlpiCandidateMatcher`) y se exige
   exactamente ese activo, ya identificado por su tag (`ONE`, mismo id, `claim` = false). Si apareció otro candidato
   (mismo serial o mismo tag; carrera con el agente o un humano) ⇒ `MANUAL_REVIEW` (`glpi_claim_verify`) sin registrar
   `glpi_items_id`, sin Infocom y sin puente.
7. **Infocom — política monetaria explícita (antes de escribir nada en GLPI):**
   - `value` = `unit_cost` del handoff (fuente canónica de P2D-3; nunca total ÷ cantidad) convertido **exactamente**
     a `decimal(20,4)`: escala ≤ 4 se completa con ceros; escala 5–6 sólo si los dígitos sobrantes son 0; más de 16
     dígitos enteros ⇒ no representable. No representable ⇒ `MANUAL_REVIEW` (`infocom_scale`), **jamás** se redondea.
     PYG (escala 0) siempre es exacto.
   - GLPI no guarda moneda por Infocom (hecho 8): la moneda del handoff debe ser igual a `si4_glpi_infocom_currency`
     (config, por defecto `PYG`); si no ⇒ `MANUAL_REVIEW` (`infocom_currency`). No se convierte moneda.
   - `suppliers_id` = proveedor de la compra. Debe existir, no estar en la papelera y seguir siendo **aplicable a la
     entidad de la unidad**: misma entidad o ancestro actual con `is_recursive`. Se usa la misma regla autoritativa
     que Compras (`ReferenceValidator`): recorre la cadena viva de `entities_id` con `Entity::getFromDB()` y nunca la
     caché del árbol (hecho 12); ante la duda, fail-closed. Se comprueba en cada pasada, antes de escribir el Infocom.
     Si no aplica ⇒ `MANUAL_REVIEW` (`infocom_supplier`), sin Infocom ni puente;
     `order_number` = número de solicitud; `delivery_date` = fecha local de `received_at` (recepción física).
   - `buy_date`/`order_date` **no** se escriben: el handoff v1 no trae la fecha de compra e inventarla sería falso
     (queda para un handoff v2 de Compras).
   - Idempotente: un solo Infocom por activo (hecho 8). Si existe, se **completan** sólo los campos propios vacíos;
     iguales ⇒ nada; distintos y no vacíos ⇒ `MANUAL_REVIEW` (`infocom_conflict`). Se verifica releyendo.
8. **AssetBridge 1:1** — `asset_bridge` gana `receipt_unit_uuid CHAR(36) NULL UNIQUE` (NULL para puentes SI-1). Se
   buscan filas por uuid, `snipe_asset_id`, `snipe_asset_tag` y `(glpi_itemtype, glpi_items_id)`:
   ninguna ⇒ insertar (puente + alias vigente, en una transacción de tablas propias); una fila idéntica ⇒ idempotente;
   una fila SI-1 idéntica sin uuid ⇒ adoptarla (completar uuid); cualquier coincidencia parcial, otra unidad o más de
   una fila ⇒ `MANUAL_REVIEW` (`bridge_conflict`). Nunca se reasigna en silencio. UNIQUE de la saga
   `(glpi_itemtype, glpi_items_id)`: un activo GLPI nunca queda en dos unidades.
9. **Snipe no se toca**: antes de la primera escritura GLPI de cada pasada (salvo que la misma pasada acabe de
   verificarlo) se relee el activo por tag y debe seguir cumpliendo `RemoteAssetMatcher` con compañía/modelo
   **registrados** en la saga y el mismo `snipe_asset_id`; si diverge ⇒ `MANUAL_REVIEW`; si Snipe no responde ⇒ RETRY.
10. **Fencing**: cada escritura GLPI va precedida de una comprobación de dueño con lease restante ≥ 30 s
    (`SagaStore::holds()`, reloj de la BD); la creación además persiste su intención. Infocom y puente son
    idempotentes por UNIQUE aunque un worker viejo llegue tarde.
11. **ACL real**: el usuario técnico necesita CREATE del itemtype en la entidad de la unidad (`can(-1, CREATE)`),
    UPDATE para reclamar `otherserial` e `infocom` CREATE/UPDATE. Falta de derecho ⇒ `BLOCKED_CONFIG` (`acl`), sin
    escribir. Además de `RIGHT_SI4` y `RIGHT_INTEGRATION` (SI4-1).
12. **Sin** `acknowledgeProcessed()`, companyqr, etiqueta ni P2D-4. Worker deshabilitado por defecto y sin Acción
    automática.
13. **Upgrade 0.3.0 → 0.4.0 seguro**: columnas nuevas sólo si faltan, tabla nueva IF NOT EXISTS, derechos y
    configuración existentes preservados (sólo claves nuevas), sagas SI4-1 y puentes SI-1 intactos.

### Crash points (todos convergen: 1 activo Snipe, 1 activo GLPI, 1 Infocom, 1 puente)
| Punto | Qué encuentra el retry |
|---|---|
| antes de buscar en GLPI | nada hecho ⇒ busca y crea/vincula |
| tras encontrar un activo existente, antes de guardar la saga | el mismo candidato (por serial o por el tag ya reclamado) |
| tras reclamar el tag, antes de la post-verificación | el mismo activo por su tag ⇒ vincula |
| tras fijar el pin, antes de buscar | el destino pinneado, aunque el mapeo haya cambiado |
| tras `add()`, antes de guardar `glpi_items_id` | el activo propio por `otherserial` ⇒ vincula (outcome `created`) |
| tras guardar `glpi_items_id`, antes de Infocom | `GLPI_RESOLVED_OR_CREATED` ⇒ revalida y sigue |
| tras escribir Infocom, antes de `INFOCOM_READY` | Infocom único con los mismos valores ⇒ `unchanged` |
| tras escribir el puente, antes de `BRIDGED` | fila idéntica ⇒ idempotente |

## Alternativas descartadas
- **Identificar por serial solamente:** opcional y sin UNIQUE; no cubre unidades sin serial.
- **Marca en `comment`:** texto libre editable, no indexado; `otherserial` es nativo, indexado y visible.
- **SQL directo a `glpi_computers`/`glpi_infocoms`:** salta reglas, hooks, historial y ACL (Regla 0).
- **Redondear a 4 decimales o convertir moneda:** alteraría el dato canónico de P2D-3 sin decisión humana.
- **Reusar `map_models` para el itemtype:** mezcla el catálogo de Snipe con el de GLPI.
- **Releer el mapeo vivo en cada retry:** un cambio administrativo cambiaría el destino de una unidad a mitad de camino.
- **`getAncestorsOf()` para el proveedor:** su caché puede quedar desactualizada tras mover una entidad (fail-open).

## Consecuencias
- (+) Reintentos idempotentes en cada paso, dedup con el agente, costo exacto y auditable, puente estable.
- (−) Una unidad sin serial que el agente ya inventarió se crea de nuevo (no hay evidencia para vincularla); queda
  visible para la reconciliación SI-1.
- (−) Compras en una moneda distinta de la del Infocom quedan en `MANUAL_REVIEW` hasta decidir una política.
- (−) El worker sigue sin producción: SI4-3 (companyqr/etiqueta) y el ack final están pendientes.
- (−) Una saga cuyo modelo pinneado se elimina queda en `MANUAL_REVIEW`: decidir el nuevo destino es humano.
- (−) Si la post-verificación del reclamo detecta otro candidato, el activo reclamado conserva el tag; la revisión
  manual decide cuál es la unidad.
