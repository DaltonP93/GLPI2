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
        ▼  resolver por asset_bridge (asset_tag → glpi_itemtype/items_id + companyqr_code_id)
        ▼  AUTHENTICATED (firewall GLPI: login + retorno)   ← el asset_tag NO autoriza
        ▼  ACL nativa + entidad
        ▼  entregar ficha segura de companyqr (Fase 1) → "Reportar problema" → Ticket vinculado
```
- La etiqueta física inicial sigue el estándar Fase 1: **70,75 × 24 mm, horizontal, amarilla,
  QR, `TI • ACTIVOS`, asset tag, tipo; sin IP/MAC/hostname/VLAN**. Se **genera con el motor de
  Snipe** (CONFIGURE del template + `POST /api/v1/hardware/labels`); el **contenido del QR** es
  la URL del gateway (no datos técnicos).
- El gateway **no** convierte el `asset_tag` en autorización (idéntico principio a `companyqr`).

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
```
companypurchasing: purchase.received  (ítem is_inventoriable = 1)
   │  idempotency_key = purchase:<requests_id>:item:<line_no>
   ▼  buscar-o-crear en asset_bridge por la clave
   ├─ ya existe → no-op (idempotente)
   └─ no existe:
        1) crear activo en Snipe-IT (POST /api/v1/hardware) con modelo/categoría mapeados
        2) obtener/asignar asset_tag (política Snipe)
        3) crear/vincular activo GLPI (itemtype configurable) + poblar Infocom (costo/proveedor/presupuesto)
        4) generar companyqr (Fase 1) + registrar companyqr_code_id
        5) insertar fila asset_bridge (sync_status=mapped)
        6) etiqueta imprimible por el motor de Snipe (QR → gateway GLPI2)
```
**No** se usa `orders` de Snipe como workflow (confirmado: no es workflow). El workflow/compra
es GLPI2 (`companypurchasing`).

### E — Solicitud de equipo [futuro]
Primera etapa: **requestable assets nativo de Snipe**. El portal GLPI2 sólo **enlaza/consulta**
estado por API; no se duplica el workflow.

### F — Aceptación de entrega [SI-5]
Snipe conserva la aceptación/firma (`CheckoutAcceptance`). GLPI2 **sólo referencia**
estado/fecha/URL/hash. **No** se copian firmas/PII entre sistemas.

## Reconciliación y conflictos (SI-1)
- **Read-only:** listar activos Snipe (paginado por API), cruzar con `asset_bridge` y con activos
  GLPI; clasificar: `mapped` · `orphan_snipe` (sólo en Snipe) · `orphan_glpi` (sólo en GLPI) ·
  `conflict` (divergencia en campo con dueño único).
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
  `received → si inventariable → Snipe → asset_bridge → GLPI → companyqr → etiqueta` (idempotente).
  El `InventoryHandoff` delega la creación física a Snipe (no crea el activo GLPI "a secas").
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
| **SI-1** | `SnipeItClient` + auth + `asset_bridge` + **reconciliación read-only** + mapping + **gateway QR** + prueba de label | **no** (read-only) |
| **SI-2** | Checkout/checkin Snipe → GLPI (custodia) | sí (reflejo en GLPI) |
| **SI-3** | Labels/impresión masiva completa (70,75×24 amarilla, QR→gateway) | config |
| **SI-4** | Compra recibida → crear activo Snipe → bridge → GLPI → etiqueta | sí (idempotente) |
| **SI-5** | Aceptación/firma física referenciada desde GLPI2 | referencia |

> **La primera implementación (tras aprobación) es SOLO SI-1** (read-only). No checkout/checkin
> sync, ni compras→activo, ni aceptación, ni consumibles/licencias, ni IA/WhatsApp todavía.
