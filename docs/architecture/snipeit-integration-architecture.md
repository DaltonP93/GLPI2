# Arquitectura de integración Snipe-IT ↔ GLPI ↔ GLPI2

**Diseño, sin implementación.** Integración **sólo por API** (Snipe-IT AGPL-3.0; sin copiar
código, sin DB-a-DB, sin tocar cores). Complementa ADR-0015 y los docs native-first / ownership /
`asset_bridge`.

## Sistemas y responsabilidades
```
                          Portal GLPI2 (una sola experiencia)
      Soporte/Tickets · Compras · KB · Mis activos · Solicitar equipo
                                   │
         ┌─────────────────────────┼──────────────────────────┐
         ▼                         ▼                           ▼
   ┌───────────┐          ┌──────────────────┐          ┌──────────────┐
   │   GLPI    │          │  GLPI2 (plugins) │          │   Snipe-IT   │
   │ ITSM/CMDB │          │ compras/workflow │          │ físico/custo-│
   │ técnico   │          │ firma/portal/    │          │ dia/labels/  │
   │ (Agent)   │          │ integraciones    │          │ aceptación   │
   └─────┬─────┘          └────────┬─────────┘          └──────┬───────┘
         │                         │  companyintegrations       │
         │                         │  (SnipeItClient + bridge)  │  API v1 (token)
         └───────── API ───────────┴───────── integration-hub ──┘
                        (broker de eventos/reintentos, fases posteriores)
```
- **`companyintegrations`** (plugin GLPI): hospeda `SnipeItClient`, `asset_bridge`,
  reconciliación read-only y el **gateway QR**. Reutiliza ACL/entidad de GLPI y `companyqr`.
- **`integration-hub`** (servicio): en fases posteriores, **broker** asíncrono (cola, reintentos,
  dead-letter, webhooks salientes). En SI-1 la integración es **síncrona/read-only** desde el plugin.
- **Un dato, un dueño** (matriz Source of Truth). El QR: *el asset tag identifica; GLPI autoriza.*

## Gateway QR (asset tag → ficha segura GLPI)
```
Etiqueta Snipe (QR = https://<portal>/asset/NB-001245)
        │  (Snipe CONFIGURE: label2_2d_target=plain_asset_tag + label2_2d_prefix)
        ▼
GET /plugins/companyintegrations/asset/{asset_tag}   (companyintegrations)
        ▼  resolver por asset_bridge: asset_tag ACTUAL **o histórico** (asset_tag_aliases)
        ▼      → glpi_itemtype/items_id + companyqr_code_id   (identidad = asset_bridge.id, no el tag)
        ▼  AUTHENTICATED (firewall GLPI: login + retorno)   ← el asset_tag NO autoriza
        ▼  ACL nativa + entidad
        ▼  entregar ficha segura de companyqr (Fase 1) → "Reportar problema" → Ticket vinculado
```
- La etiqueta física inicial sigue el estándar Fase 1: **70,75 × 24 mm, horizontal, amarilla,
  QR, `TI • ACTIVOS`, asset tag, tipo; sin IP/MAC/hostname/VLAN**. Se **genera con el motor de
  Snipe** (CONFIGURE del template + `POST /api/v1/hardware/labels`); el **contenido del QR** es
  la URL del gateway (no datos técnicos).
- El gateway **no** convierte el `asset_tag` en autorización (idéntico principio a `companyqr`).
- **Estabilidad ante rename:** una etiqueta física ya impresa **nunca** queda rota si el
  `asset_tag` cambia en Snipe. La identidad estable es técnica (`asset_bridge.id` /
  `receipt_unit_uuid`), no el tag; el gateway resuelve el tag **actual y también los históricos**
  (`..._asset_tag_aliases`). Política recomendada: `asset_tag` **inmutable tras emitir la
  etiqueta** (detalle en `asset-bridge-model.md` §Estabilidad del QR).

## Flujos de sincronización
### A — Alta física (Snipe → GLPI) [SI-1 read-only; escritura en SI-4+]
Listar/leer activos por API; buscar en `asset_bridge`; reportar mapeados/no mapeados/conflictos.
En read-only **no** se crea ni modifica nada.

### B — Checkout (Snipe → GLPI) [SI-2]
Snipe es dueño de la custodia; GLPI **refleja** responsable/ubicación físicos y registra evento
de auditoría. Idempotente por `snipe_asset_id + checkout_id`.

### C — Checkin (Snipe → GLPI) [SI-2]
Quitar la asignación física reflejada en GLPI, **conservando historial**.

### D — Compra aprobada y recibida (GLPI2 → Snipe → GLPI) [SI-4]
**Cardinalidad por unidad + recepciones parciales:** una línea con cantidad **N** genera **N
unidades físicas** (`receipt_unit`), cada una con su propio serial / activo Snipe / activo GLPI /
`asset_bridge`. Una línea `qty=N` **puede recibirse en varios eventos/lotes** (p. ej. 4 hoy y 6
mañana); cada `receipt_unit` **nace al recibir físicamente** su unidad. La **identidad canónica**
de cada unidad es `receipt_unit_uuid` (UUID interno inmutable); la `idempotency_key`/
`correlation_key` `purchase:<req>:item:<line>:unit:<n>` es **clave de correlación/debug legible**,
**no** la identidad técnica.
```
companypurchasing: purchase.received  (ítem is_inventoriable = 1) — llega received_qty (parcial o total)
   └─ para cada unidad recibida en ESTE lote (receipt_batch_id):
       receipt_unit_uuid = UUID interno inmutable (identidad canónica)
       correlation_key    = purchase:<requests_id>:item:<line_no>:unit:<n>   (correlación/debug)
       ▼  buscar-o-crear la receipt_unit por receipt_unit_uuid (NO por correlation_key)
       │  SAGA (estado persistente en receipt_units.saga_state / last_confirmed_step):
       │  reintento RESUME desde last_confirmed_step; cada paso PERSISTE su resultado antes de avanzar
       ├─ ya BRIDGED/QR_READY/LABEL_READY → no-op (idempotente)
       └─ PENDING → avanzar:
            0) resolver **entidad** por company/entity mapping (si no mapeada → CONFLICT, NO inferir)
            1) SNIPE_CREATED: crear activo en Snipe-IT — **idempotencia saliente** (su API no tiene
               idempotency key): **buscar-primero** (por snipe_asset_id ya persistido / serial único)
               antes de POST /api/v1/hardware; **persistir snipe_asset_id inmediatamente** tras crear
            2) obtener/asignar asset_tag (Snipe) + serial de la unidad (política de serial)
            3) GLPI_RESOLVED_OR_CREATED: **RESOLVER-o-crear** el activo GLPI por serial/UUID/
               identificador soportado → match único, VINCULAR; **ambiguo** → CONFLICT (sin
               auto-merge); no existe → crear (itemtype config)
            4) poblar Infocom con el **costo atribuible a ESTA unidad** (`unit_cost` derivado de la
               línea; **proveedor = el de la compra GLPI2**) — nunca el total general prorrateado
            5) BRIDGED: insertar/actualizar fila asset_bridge (sync_status=mapped) + alias de asset_tag
            6) QR_READY: generar companyqr (Fase 1) + registrar companyqr_code_id
            7) LABEL_READY: etiqueta imprimible por el motor de Snipe (QR → gateway GLPI2)
```
- **Saga (item detallado en `asset-bridge-model.md` §Saga):** Snipe/GLPI/companyqr **no comparten
  transacción**; si el proceso falla tras `SNIPE_CREATED` pero antes de `BRIDGED`, la unidad queda
  en ese estado con `snipe_asset_id` guardado y el reintento **vincula, no recrea**. `CONFLICT`/
  `MANUAL_REVIEW` no auto-avanzan (tablero de diferencias).
- **Recepción parcial:** la línea lleva `ordered_qty / received_qty / pending_qty`; cada lote crea
  sólo las unidades de ese lote; reenviar el mismo lote **no** duplica (UUID + saga).
- **Dedup con GLPI Agent:** el paso 3 **nunca** crea a ciegas: primero intenta **resolver** un
  activo GLPI existente; sólo crea si no existe; un match **ambiguo** queda en `conflict` para
  decisión humana (jamás une dos activos ambiguos).
- **No** se usa `orders` de Snipe como workflow (confirmado: no es workflow). El workflow/compra
  es GLPI2 (`companypurchasing`).

### E — Solicitud de equipo [futuro]
Primera etapa: **requestable assets nativo de Snipe**. El portal GLPI2 sólo **enlaza/consulta**
estado por API; no se duplica el workflow.

### F — Aceptación de entrega [SI-5]
Snipe conserva la aceptación/firma (`CheckoutAcceptance`). GLPI2 **sólo referencia**
estado/fecha/URL/hash. **No** se copian firmas/PII entre sistemas.

## Reconciliación y conflictos (SI-1)
- **SI-1 es read-only sobre Snipe y sobre los activos core de GLPI** (no crea ni modifica esos
  datos), **pero sí persiste en tablas propias de `companyintegrations`**: `asset_bridge`,
  `receipt_units`, resultados de reconciliación, **auditoría** y `estado/error/timestamps`.
- **Cruce:** listar activos Snipe (paginado por API), cruzar con `asset_bridge` y con activos GLPI;
  clasificar: `mapped` · `orphan_snipe` (sólo en Snipe) · `orphan_glpi` (sólo en GLPI) ·
  `conflict` (divergencia en campo con dueño único) · `serial_conflict` · **`company_unmapped`**
  (compañía Snipe sin entidad GLPI mapeada).
- **Entidad/compañía:** si la `company` de Snipe **no** está mapeada a una entidad GLPI, la fila
  queda `company_unmapped`/`pending` y **no** se sincroniza a una entidad **inferida** (nunca se
  adivina la entidad).
- **No auto-resuelve** conflictos: los reporta en un **tablero de diferencias** para decisión
  humana. La precedencia por campo la fija la matriz Source of Truth.
- Ejecutable como **tarea programada** (CronTask GLPI) + on-demand; respeta **entidad**.

## Política de errores / resiliencia
- **Timeouts** por request; **retry con backoff exponencial** (2s,4s,8s,16s) sólo para errores
  transitorios (5xx/timeouts), **no** para 4xx.
- **Circuit breaker:** tras N fallos consecutivos, abrir el circuito (dejar de llamar a Snipe por
  un período) → **falla de Snipe no tumba GLPI** (tickets/soporte siguen operativos).
- **Idempotencia** en toda escritura (claves naturales; ver `asset-bridge-model.md`).
- **Dead-letter:** los eventos que agotan reintentos quedan **pendientes/reintentables** (no se
  pierden) → **falla de GLPI deja evento pendiente**; en fases con `integration-hub`, cola +
  DLQ; en SI-1, tabla de pendientes en el plugin.
- **Correlation ID** en cada operación (propagado a logs/auditoría/webhooks).
- **Degradación:** el gateway QR y la ficha `companyqr` funcionan aunque la reconciliación esté
  caída (usan el `asset_bridge` ya persistido).

## Impacto sobre módulos existentes
- **`companypurchasing` (ADR-0013):** el flujo de recepción cambia a
  `received → si inventariable → POR UNIDAD → Snipe → asset_bridge → resolver-o-crear GLPI →
  companyqr → etiqueta` (idempotente **por unidad**). El `InventoryHandoff` delega la creación
  física a Snipe y **resuelve** (no duplica) el activo GLPI existente (dedup con GLPI Agent).
- **`companyqr` (ADR-0011):** se **mantiene**. La ficha segura y la creación de ticket no cambian;
  sólo se añade una **puerta de entrada por `asset_tag`** (gateway) que resuelve vía `asset_bridge`
  y luego entra al flujo `companyqr` habitual. La etiqueta puede imprimirse desde Snipe con el QR
  apuntando al gateway.
- **`companysignature` (ADR-0014):** sin cambios; la **aceptación física** (Snipe) es distinta de
  la **firma de aprobación empresarial** (GLPI2). No se duplican.

## Roadmap
| Fase | Alcance | Escribe datos |
|---|---|---|
| **SI-0** | Contrato/descubrimiento: ADR, matriz ownership, mapeos, OpenAPI del hub, credenciales sandbox | no |
| **SI-1** | `SnipeItClient` + auth + `asset_bridge`/`receipt_units` + **reconciliación read-only** + mapping + **gateway QR** + prueba de label | **no** en Snipe ni en activos core GLPI; **sí** en tablas propias de `companyintegrations` (bridge, reconciliación, auditoría, estado/error/timestamps) |
| **SI-2** | Checkout/checkin Snipe → GLPI (custodia) | sí (reflejo en GLPI) |
| **SI-3** | Labels/impresión masiva completa (70,75×24 amarilla, QR→gateway) | config |
| **SI-4** | Compra recibida → por unidad (saga con estado persistente, recepciones parciales): Snipe → bridge → resolver-o-crear GLPI → companyqr → etiqueta | sí (idempotente por `receipt_unit_uuid`) |
| **SI-5** | Aceptación/firma física referenciada desde GLPI2 | referencia |

> **La primera implementación (tras aprobación) es SOLO SI-1** (read-only). No checkout/checkin
> sync, ni compras→activo, ni aceptación, ni consumibles/licencias, ni IA/WhatsApp todavía.
