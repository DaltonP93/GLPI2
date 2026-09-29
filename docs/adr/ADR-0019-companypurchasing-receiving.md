# ADR-0019: `companypurchasing` P2D-3 — recepción física, costo por unidad y handoff a SI-4

- **Estado:** Propuesto (implementado en P2D-3; pendiente de revisión humana)
- **Fecha:** 2026-09-25
- **Decisores:** Producto, Compras, finanzas, seguridad, plataforma
- **Módulo/área:** `plugins/companypurchasing` (0.3.0 → 0.4.0)
- **Complementa:** ADR-0013, ADR-0018 y el gate `../architecture/companypurchasing-native-first-gate.md`
  §5 (recepción + outbox atómicos), §6 (costo exacto), §7 (contrato del outbox), §9 (ACL), §10 (tests).

## Contexto
El gate ya fija lo esencial de P2D-3 y **no se repite aquí**: identidad canónica `receipt_unit_uuid`,
recepción parcial por lotes, `SELECT … FOR UPDATE` de la línea, unidades + outbox en una sola transacción,
`idempotency_key` de la operación, payload inmutable/versionado, API `PurchasingIntegrationApi` (SI-4 no lee
por SQL), dinero exacto (PYG escala 0), sin Snipe-IT/activos/Infocom/companyqr. Este ADR registra **sólo las
decisiones nuevas** que la implementación tuvo que tomar.

## Decisión
1. **La fase de compra es una VERSIÓN nueva de la misma definición** (sin segunda máquina de estados):
   `APPROVED →start_purchase→ IN_PURCHASE →receive_partial→ PARTIALLY_RECEIVED →receive_complete→ RECEIVED`
   (+ `IN_PURCHASE →receive_complete→ RECEIVED`). `RECEIVED` es **intermedio** (la entrega es P2D-4). Estas
   transiciones no tienen paso de aprobación ni derecho extra del motor (READ); las protege una **condición**
   (`purchase_bound` / `receipt_bound`) que sólo aporta el código de Compras después de confirmar el hecho
   local — la superficie HTTP genérica del motor no pasa `fields`, así que no puede forzarlas. En el orden del
   circuito se ubican **después** de APPROVED: entrar en ellas no reinicia aprobaciones y la integridad
   post-compra sigue verificándose contra el ledger (no es vacua).
2. **Sincronización receipt → motor como saga con marcador durable.** `requests.receiving_seq` se incrementa en
   la MISMA transacción que el inicio de compra y que cada lote; `requests.receiving_synced_seq` registra el
   último seq ya reflejado por el motor (monotónico; se lee seq **antes** que los contadores). `seq > synced`
   ⇒ pendiente. Converge el propio flujo en vivo o la **Acción automática nativa** `reconcileprojection`, que
   corre con el contexto de sistema de la CronTask de GLPI; desde la CLI sin sesión sólo se **reporta**.
   Motor "adelantado" o fuera de la fase ⇒ **anomalía reportada**, jamás se retrocede el motor ni se deshacen
   unidades físicas.
3. **Congelamiento en `startPurchase()`** (más estricto que "desde la primera recepción"): exige integridad
   limpia + APPROVED y, en una transacción, fija `ordered_qty` (= cantidad aprobada), el precio final y el
   costo de cada línea, el proveedor/cotización de la compra y la política de costo. Desde ahí se rechazan
   cotizar, seleccionar, cambiar precios o enmendar cantidades. Una **deriva de integridad tras iniciar la
   compra** (p. ej. el Supplier cambia de rama) **no reabre el circuito** (hay una orden en curso y quizá
   unidades recibidas): `enforceIntegrity()` la reporta y `receive()` falla cerrado hasta que se resuelva.
4. **Costo atribuible** (`CostPolicy` pinneada por solicitud en `..._cost_policies`, JSON canónico + hash):
   la política pinnea `currency_code`, `currency_scale` (escala EFECTIVA de esa moneda al iniciar la compra;
   **PYG ⇒ 0 siempre**, aunque se intente un override), `include_discounts/taxes/freight`, `allocation` y
   `unit_split`. Iniciada la compra, `receive()`, `CostAllocator::allocate()/unitCost()` y el handoff usan
   **sólo** la escala pinneada; también la integridad de la aprobación formatea los importes de esa solicitud con
   ella (`CostPolicyStore::scaleOverridesFor()`), así que un cambio posterior de `currency_scale_overrides` no es
   "deriva" y **sólo afecta compras nuevas**. `final_unit_price` siempre; `discounts`/`taxes`/`freight` de
   cabecera sólo si la configuración los incluye (por defecto **ninguno**: el prorrateo nunca es el método por
   defecto). Método de asignación
   `line_value_largest_remainder`: cada ajuste se reparte por valor de línea en unidades menores; el sobrante
   (< nº de líneas) va a los mayores restos, empate por orden de línea; si todas las líneas valen 0, el peso es
   la cantidad. Reparto por unidad `floor_remainder_to_first_units`: `base = ⌊C/Q⌋`; las primeras `C mod Q`
   unidades (por orden de recepción) llevan +1 unidad menor. Σ unidades = costo de línea y Σ líneas = total de
   la cotización, **exactos**, sin importar cómo se partan los lotes. Aritmética de strings (sin float/bcmath).
5. **Derecho dedicado de integración** `RIGHT_INTEGRATION` (bit 1024 de `plugin_companypurchasing`): un
   perfil técnico con **sólo** ese bit (+ entidades) consume el outbox; no requiere Super-Admin ni ver
   solicitudes, y ningún otro bit de Compras lo habilita. `RIGHT_RECEIVE` queda activo para la recepción.
6. **Protocolo de lease del outbox:** `claimPending()` toma elegibles (PENDING; RETRY con `next_retry_at`
   vencido; LEASED con `leased_until` vencido) con `FOR UPDATE SKIP LOCKED` y el **reloj único de la BD**;
   cada toma genera un `lease_token` nuevo (CSPRNG) e incrementa `attempts`. `acknowledgeProcessed` /
   `markRetry` / `markError` son UPDATE condicionados a `status = LEASED`, al token vigente **y** a
   `leased_until IS NOT NULL AND leased_until >= NOW()` (mismo reloj): un lease **vencido** no confirma aunque
   nadie lo haya re-tomado todavía (la fila sigue reclamable) y un worker re-tomado pierde el derecho a confirmar;
   tras una confirmación exitosa, repetirla con el mismo token es idempotente. Agotar `outbox_max_attempts` en un
   `markRetry` ⇒ ERROR (final). `last_error` se sanea (sin credenciales) y se acota.
   **Validación única del handoff** (`HandoffPayload::validatedPayload()`), idéntica en `claimPending()` y
   `getHandoff()`: `payload_version` de la fila = `SCHEMA_VERSION`, JSON canónico, hash correcto, y la identidad
   redundante coincide con la fila (`receipt_unit_uuid`, `request_id`, `entity_id`) y con la unidad que referencia
   (línea, moneda, costo exacto, serial). Recalcular el hash tras editar el JSON no alcanza. Inconsistente ⇒
   ERROR visible en el claim y excepción en `getHandoff()`: nunca se entrega.
   **Payload v1 autosuficiente respecto del dinero:** `currency` + `currency_scale` (la pinneada) + `unit_cost`
   como string con exactamente esa escala; se valida contra la escala declarada (regla dura PYG ⇒ 0), sin
   consultar la configuración vigente de Compras. v1 se fijó así antes del primer merge/despliegue (sin payloads
   productivos que migrar).
7. **Instancias iniciadas bajo una versión anterior** (sin fase de compra) conservan su versión (regla del
   motor): `startPurchase()` falla cerrado y la reconciliación las **reporta** (`legacy`; `legacy_blocked`
   si ya están en APPROVED, que hace fallar el comando/Acción automática para que no pase inadvertido). No se
   migran automáticamente.
8. **Validaciones de entrada:** serial opcional, único **por línea** (`UNIQUE(items_id, serial)`), tantos
   como unidades; tope configurable de unidades por lote (`receipt_max_units_per_batch`, defensa ante
   entradas desmesuradas); remito opcional como `Document` nativo de la entidad.

## Garantías demostradas (selftest obligatorio + unit)
| Afirmación | Prueba |
|---|---|
| Upgrade 0.3.0 → 0.4.0 con datos: tablas/columnas nuevas, datos P2D-2 intactos (huella), config/derechos/Acción automática preservados | `[UPGRADE-P2D3]` |
| 4 + 6 = 10 unidades, 10 UUID, seriales, costos 1424×4 + 1423×6, Σ = total cotización; no inventariable sin outbox | `[RECEIVE-PARTIAL]`, unit `CostAllocator` |
| Misma clave ⇒ mismo lote; otra entrada ⇒ conflicto | `[RECEIVE-IDEMPOTENT]`, `[RECEIVE-CONC]` |
| Falla un outbox ⇒ rollback total | `[RECEIVE-ATOMIC]` |
| COMMIT → caída antes del motor ⇒ pendiente reportado; la CronTask nativa converge | `[RECEIVE-CRASH-SYNC]` |
| 6 + 6 sobre 10 (procesos reales) ⇒ nunca 12 | `[RECEIVE-CONC]` |
| Claim concurrente disjunto; lease vencido; token viejo rechazado; RETRY/ERROR | `[OUTBOX]` |
| Lease vencido sin re-toma no confirma (determinista); la fila sigue reclamable | `[OUTBOX-LEASE]` |
| `getHandoff`/claim con la misma validación: versión de fila, uuid/solicitud/entidad editados + hash recalculado ⇒ rechazo | `[OUTBOX-IDENTITY]`, unit `validatedPayload` |
| Escala pinneada: USD 3 → cambio global a 2 entre lotes ⇒ ambos lotes a escala 3, Σ exacta, misma política; PYG ⇒ 0 | `[COST-SCALE-PIN]`, unit `CostPolicy`/`HandoffPayload` |
| Congelamiento; deriva post-compra no reabre | `[FREEZE]`, `[POST-PURCHASE-INTEGRITY]` |
| Versión anterior detectada y no mutada | `[LEGACY-DEF]` |
| Sin clientes HTTP/Snipe/activos/Infocom/companyqr/float en el código nuevo; ningún Computer/Infocom creado | unit (escaneo), `[NO-SIDE-EFFECTS]` |

## Alternativas consideradas
- **Envolver `WorkflowApi::transition()` en la transacción de recepción** — rechazada (llamada cruzada dentro
  de una transacción local; el gate y ADR-0018 lo prohíben).
- **Almacenar `pending_qty`** — rechazada: se deriva (`ordered − received`); un contador menos que mantener.
- **Costo = total ÷ cantidad o prorrateo por defecto** — rechazadas (gate §6).
- **Reabrir aprobaciones ante deriva post-compra** — rechazada: no hay forma segura de "des-comprar"; se
  bloquea y se reporta.
- **Reutilizar Super-Admin o `MANAGE_PURCHASING` para SI-4** — rechazada (mínimo privilegio).

## Consecuencias
- Publicar la definición crea una versión nueva; solicitudes ya iniciadas con la versión P2D-2 deben
  cerrarse por su circuito (o decidirse manualmente) — se reportan, no se migran.
- SI-4 (`companyintegrations`) consumirá `PurchasingIntegrationApi` con un perfil `RIGHT_INTEGRATION`; hasta
  entonces el outbox queda en PENDING (visible).
