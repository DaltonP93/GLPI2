# Integración Fase 2 — compras · workflow · firma (+ inventario/companyqr, API/eventos, IA/WhatsApp)

Cómo se relacionan los tres plugins de Fase 2 entre sí, con GLPI nativo y con `companyqr`.
**Diseño, sin implementación.** **Sin tocar el core.**

## Arquitectura (módulos separados, diseñados juntos)
```
                          GLPI 11.0.8  (CORE INTACTO — Regla 0)
   Supplier · Budget · Infocom · Document · Entity/Group/Profile · Notifications
   Rule · Log/Event · State · API v2 · Webhooks · TCPDF
                          ▲            ▲            ▲
                          │ reutiliza  │            │
   ┌──────────────────────┴───┐  ┌─────┴─────────┐  ┌┴───────────────────────┐
   │   companypurchasing      │  │ companyworkflow│  │   companysignature      │
   │  (dominio de compras)    │→ │ (motor genérico│← │ (evidencia + hash + PDF │
   │  solicitud/ítems/quotes  │  │  estados/ACL/  │  │  versionado + verify)   │
   │  montos PYG, proveedor   │  │  SLA/escalado) │  │  + adaptador cert. fut. │
   └───────────┬──────────────┘  └───────┬───────┘  └───────────┬────────────┘
               │ receive (evento)        │ transitioned          │ approved→PDF
               ▼                          ▼                       ▼
        companyqr (Fase 1)      Notificaciones nativas     QR verificación interno
        activo + Infocom + etiqueta
```
- `companypurchasing` **depende** de `companyworkflow` (motor) y `companysignature` (evidencia).
- `companyworkflow` y `companysignature` **no** conocen Compras (reusables).
- Orden de instalación/activación: `companyworkflow` → `companysignature` → `companypurchasing`.

## Secuencia (feliz)
```
Solicitante crea (BORRADOR) → submit
  → companyworkflow enruta a PENDIENTE_JEFE_AREA → Notificación nativa al grupo jefe
Jefe approve (quórum) → EN_COMPRAS
Compras cotiza (Document/Quote) + selecciona proveedor (Supplier) → approve → PENDIENTE_GERENCIA_FINANCIERA
Gerencia approve (condición por monto) → APROBADA
  → companysignature registra evidencia (identidad+hash+timestamp) y compone PDF aprobado (QR verify)
order → EN_COMPRA → receive
  → companypurchasing.InventoryHandoff: crea/vincula activo, puebla Infocom, genera companyqr + etiqueta
deliver → ENTREGADA → close → CERRADA
```
En cualquier etapa de aprobación: `reject`→RECHAZADA, `return`→DEVUELTA (editable). Una **edición
sustantiva** tras aprobar **reinicia** las aprobaciones afectadas (ver `companysignature`).

## Integración con inventario + Snipe-IT + companyqr (flujo, item 6 — actualizado con ADR-0015)
Snipe-IT es **autoridad de lo físico** (asset tag, custodia, etiquetas). Por eso la creación de
activo en la recepción pasa **primero por Snipe-IT** y se puentea a GLPI con `asset_bridge`:
```
Compra RECIBIDA
   │  (ítem is_inventoriable = 1, cantidad N)  ·  POR UNIDAD n=1..N
   ▼  idempotency_key = purchase:<requests_id>:item:<line_no>:unit:<n>
InventoryHandoff (idempotente por unidad): buscar-o-crear receipt_unit / asset_bridge por la clave
   ├─ ya existe → no-op
   └─ no existe:
        1) resolver ENTIDAD por company/entity mapping (no mapeada → conflicto, sin inferir)
        2) crear activo en Snipe-IT (POST /api/v1/hardware, modelo/categoría mapeados)
        3) obtener/asignar asset_tag (Snipe) + serial de la unidad (política de serial)
        4) RESOLVER-o-crear activo GLPI (dedup con GLPI Agent por serial/UUID; ambiguo → conflicto,
           sin auto-merge) + poblar Infocom (costo/presupuesto; proveedor = el de la compra GLPI2)
        5) generar companyqr (ADR-0011) + registrar companyqr_code_id en asset_bridge
        6) imprimir etiqueta con el motor de Snipe (70,75×24 amarilla; QR → gateway GLPI2)
```
- **No se implementa aún**; se documenta (ver `snipeit-integration-architecture.md` §Flujo D y
  `asset-bridge-model.md`). Snipe `orders` **no** se usa como workflow (no lo es).
- **Permisos:** crear/resolver el itemtype destino + `companyqr:generate`/`print` + cuenta de
  servicio Snipe con **rol restringido** (RBAC por usuario; sin scopes por endpoint), todo bajo
  ACL de **entidad**.
- **Idempotencia POR UNIDAD:** N unidades → N activos; reintentar `receive` **no** duplica activos
  (Snipe ni GLPI) ni códigos, por la `idempotency_key` de unidad y los UNIQUE de `asset_bridge`.

## Eventos y API (item 12)
### Eventos de dominio (emitidos por `companypurchasing`)
`purchase.created · purchase.submitted · purchase.approved · purchase.rejected ·
purchase.returned · purchase.ordered · purchase.received · purchase.closed`
(+ internos `purchase.amount_changed`, `purchase.quote_selected`, `purchase.supplier_selected`).

### Cómo se exponen
| Necesidad | Mecanismo | Nota |
|---|---|---|
| Notificar por correo | **Notificaciones nativas** (plantillas/targets) | no construir motor de correo |
| Emitir eventos salientes a terceros | **Webhooks nativos** (`Webhook`/`QueuedWebhook`) | payload firmado por el propio webhook nativo; correlation_id |
| Lectura/escritura estándar (solicitudes, ítems) | **API REST v2** nativa | mínimo privilegio; ACL/entidad |
| Endpoints propios (acciones de workflow, verificación) | **`/api/v1`** propio (OAuth2/token) | sólo lo que v2 no cubra; idempotencia en escrituras |
- Todo evento lleva `correlation_id` y respeta ACL/entidad. La IA/WhatsApp consumen **estos**
  mecanismos, no acceso directo a tablas.

## Puntos de integración futuros IA / WhatsApp (item 13 — NO implementar ahora)
Definidos para no cerrarnos puertas; **ninguno permite que la IA apruebe una compra**:
1. **Consultar estado** de una solicitud (lectura por API v1/v2, con ACL).
2. **Notificaciones** por WhatsApp (a través de `whatsapp-adapter`, escuchando webhooks/eventos).
3. **Aprobación segura**: la IA/WhatsApp sólo **enruta** al usuario a una acción **autenticada**
   en GLPI (nunca ejecuta la aprobación por su cuenta).
4. **Asistente** para completar solicitudes (sugiere campos; el humano confirma y envía).
5. **Búsqueda histórica** de compras (RAG sobre fuentes autorizadas, con ACL — ADR-0008).
6. **Sugerencia de proveedor/producto** según historial (recomendación, no decisión).
7. **Detección de solicitudes similares/duplicadas** (alerta, no bloqueo automático).

> Regla dura: **la IA nunca aprueba una compra por sí misma.** Toda aprobación exige identidad
> humana autenticada + acción explícita (ADR-0008, ADR-0014).

## Riesgos de integración (resumen; detalle en `phase2-risks.md`)
- Acoplamiento firma↔workflow (invalidación) → contrato explícito y tests.
- Idempotencia del handoff a inventario → clave natural + reintento seguro.
- Orden de dependencias entre plugins → chequeo de prerrequisitos en `setup.php`.
