# ADR-0015: Integración con Snipe-IT (gestión física de activos)

- **Estado:** Aceptado (diseño); implementación pendiente de aprobación humana.
- **Fecha:** 2026-09-14
- **Decisores:** Producto, TI/activos, seguridad, legal (licencia), plataforma
- **Área:** integración GLPI ↔ Snipe-IT vía `plugins/companyintegrations` + `services/integration-hub`
- **Complementa:** ADR-0004 (API-first), ADR-0011 (companyqr), ADR-0013 (companypurchasing), ADR-0010 (auditoría)

## Contexto
Snipe-IT (**v8.7.2**, analizada como referencia read-only) resuelve muy bien la **gestión
física y custodia de activos** (asset tag, checkout/checkin, requestable, etiquetas/impresión
masiva, aceptación de entrega, accesorios/consumibles/componentes/licencias). No queremos
**reconstruir** eso en GLPI. Decisión de arquitectura: **integrar, no copiar ni reimplementar**.

**Principio rector:** *un mismo dato no tiene dos dueños.*

## Licencia (barrera dura)
Snipe-IT es **AGPL-3.0**. La integración es **exclusivamente por API y procesos separados**:
- **No** se copia código de Snipe-IT dentro de GLPI2, **no** se modifica su core, **no** se hace
  DB-a-DB.
- Cualquier necesidad de **reutilizar código** de Snipe-IT **detiene** el trabajo para revisión
  de licencia antes de continuar.
- GLPI2 y Snipe-IT permanecen como **aplicaciones independientes** que se comunican por HTTP/API.

## Decisión
1. **Snipe-IT = autoridad de lo físico:** asset tag/código físico, custodia, checkout/checkin,
   estado físico, etiquetas, aceptación de entrega, accesorios/consumibles/componentes/licencias
   (cuando aplique), historial de custodia.
2. **GLPI = autoridad de lo técnico/soporte:** inventario técnico (GLPI Agent/SNMP), software,
   red, tickets/SLA/Help Desk, CMDB técnica, base de conocimiento.
3. **GLPI2 (nuestros plugins) = autoridad de negocio:** compras, workflows, aprobaciones,
   firma/evidencia de aprobación, portal, integraciones. `companyqr` mantiene la **ficha segura**
   y la **creación de tickets**.
4. **`orders` de Snipe-IT NO es el workflow de compras.** Verificado en el código: `Order` es
   *"Acquisition record for inventory … **Explicitly NOT a purchase-order workflow (no state
   machine, approvals, receiving)**"* (`app/Models/Order.php`). Por tanto **`companypurchasing`
   sigue siendo** el motor de compras/aprobaciones (GLPI2). Snipe no lo reemplaza.
5. **Puente `asset_bridge`** (tabla propia en `companyintegrations`) mantiene el mapeo estable
   1:1 Snipe ↔ GLPI (+ `companyqr`). Nunca correlación por nombre.
6. **QR/etiquetas por configuración de Snipe-IT** (sin tocar su core): `label2_2d_type=qrcode`,
   `label2_2d_target=plain_asset_tag`, `label2_2d_prefix=https://<portal>/asset/` →
   QR = `https://<portal>/asset/<asset_tag>`. El QR **apunta al gateway seguro de GLPI2**; el
   asset tag **identifica**, **GLPI autoriza** (ACL). Evidencia: `app/View/Label.php` (switch
   `label2_2d_target` con caso `plain_asset_tag` y prefijo).
7. **Aceptación de entrega física** la conserva Snipe (`CheckoutAcceptance` con firma-imagen);
   GLPI2 **sólo referencia** estado/fecha/URL/hash. No se duplican firmas/PII. (Ojo: una imagen
   de firma **no** es firma digital — coherente con ADR-0014.)

## Cambio requerido en Compras (impacto ADR-0013)
El flujo documentado cambia de `purchase.received → crear activo GLPI` a:
`purchase.received → si inventariable → crear activo Snipe-IT (API) → asset_bridge →
crear/vincular activo GLPI → companyqr → etiqueta`. **Idempotente** (reintentar no duplica).

## Alternativas consideradas
- **Reconstruir gestión física en GLPI** → rechazado (Snipe ya lo hace; violaría native-first y
  duplicaría dueños de datos).
- **Copiar/forkear Snipe-IT** → rechazado (AGPL + mantenimiento + Regla 0 análoga).
- **Sincronización DB-a-DB** → rechazado (frágil, sin contrato, rompe ownership).
- **Usar `orders` de Snipe como workflow de compras** → rechazado (no es workflow, confirmado en
  código).

## Consecuencias
- (+) Reutiliza una app madura para lo físico; el usuario percibe **una sola plataforma** (portal GLPI2).
- (+) Ownership claro por campo (matriz Source of Truth) → sin conflictos de doble dueño.
- (−) Introduce integración distribuida: exige idempotencia, reconciliación, reintentos, circuit
  breaker y auditoría (diseñados en los docs de arquitectura/seguridad).
- (−) Dependencia operativa de una API externa; se diseña para **degradar** (falla Snipe no tumba
  GLPI; falla GLPI deja evento reintentable).

## Correcciones de diseño (revisión previa a implementación)
1. **Cardinalidad Compras→Activos:** una línea con cantidad **N** produce **N activos físicos**.
   Se modela la **unidad física recibida** (`receipt_unit`) y la idempotencia es **por unidad**:
   `purchase:<req>:item:<line>:unit:<n>`. Cada unidad tiene su propio serial, `snipe_asset_id`/
   `tag`, activo GLPI y `asset_bridge`. (Detalle en `../architecture/asset-bridge-model.md`.)
2. **Identidad ≠ custodia:** Snipe es SoT de **custodia** (checkout/checkin), **no** de la
   **identidad corporativa**. Los usuarios son SoT de **GLPI/IdP**; `map_users` explícito; nunca
   correlación automática por nombre/email.
3. **Compañía ↔ entidad:** `map_companies` (`snipe_company_id ↔ glpi_entity_id`). Un activo de
   compañía **no** mapeada queda en `conflict`/`pending` y **no** se sincroniza a una entidad
   inferida. Test multi-entidad obligatorio.
4. **Proveedor de adquisición = GLPI2/GLPI** (`Supplier`): Snipe lo **recibe como reflejo/mapping**,
   no es segundo dueño.
5. **Dedup con GLPI Agent:** en SI-4 el paso GLPI es **resolver-o-crear**: buscar un activo GLPI
   existente (p. ej. descubierto por Agent) por serial/UUID/identificador soportado y **vincular**;
   crear **sólo** si no existe; un match **ambiguo** queda en `conflict` (jamás auto-merge).
6. **Política de serial:** origina quien crea el activo físico (Snipe/Compras); GLPI Agent lo
   **verifica**; divergencia Snipe↔GLPI → `serial_conflict` (decisión humana), **sin** sobreescritura
   silenciosa.
7. **Alcance SI-1:** read-only sobre **Snipe** y sobre **activos core de GLPI**; **sí** persiste en
   **tablas propias** de `companyintegrations` (`asset_bridge`, `receipt_units`, reconciliación,
   auditoría, estado/error/timestamps).
8. **Permisos reales de Snipe-IT (v8.7.2):** autenticación **API por Laravel Passport**
   (`config/auth.php`: `api → passport`); autorización por **RBAC granular por usuario**
   (`config/permissions.php`: `assets.view/create/edit/checkout/checkin/audit/...`). **No** hay
   *scopes por endpoint*: el token (personal access token) **hereda los permisos del usuario**.
   → Mínimo privilegio = **cuenta de servicio con rol restringido**, no scopes por endpoint (ver
   `../security/snipeit-integration-security.md`).

### Precisión documental final (previa a implementar Fase 2 + Snipe-IT)
9. **Identidad canónica por unidad = `receipt_unit_uuid`** (UUID interno inmutable), generado al
   **recibir físicamente** la unidad. La clave `purchase:<req>:item:<line>:unit:<n>` pasa a ser
   **correlación/debug legible**, **no** la única identidad técnica (una reimpresión o
   renumeración de líneas no reasigna identidad; el UUID sí es estable).
10. **Saga de integración (estado persistente por unidad):** Snipe/GLPI/`companyqr` **no comparten
    transacción**. Estados `PENDING → SNIPE_CREATED → GLPI_RESOLVED_OR_CREATED → BRIDGED → QR_READY
    → LABEL_READY` + excepciones `RETRYABLE_ERROR / CONFLICT / MANUAL_REVIEW`. Cada paso persiste su
    resultado **antes** de avanzar; el reintento **resume desde el último paso confirmado** y
    **nunca** duplica. (Detalle en `../architecture/asset-bridge-model.md` §Saga.)
11. **Idempotencia del request saliente a Snipe:** su API **no** tiene idempotency key nativa → el
    cliente hace **buscar-primero** (por `snipe_asset_id` ya persistido para ese `receipt_unit_uuid`
    o por serial único) antes de `POST /hardware`, y **persiste `snipe_asset_id` inmediatamente**
    tras crear, de modo que un fallo tras `SNIPE_CREATED` **vincula, no recrea**.
12. **Recepciones parciales:** una línea `qty=N` puede recibirse en **varios eventos/lotes**
    (`ordered_qty`/`received_qty`/`pending_qty`, `receipt_batch_id`). Cada `receipt_unit` nace en la
    recepción física; reenviar un lote **no** duplica (UUID + saga).
13. **Estabilidad del QR ante cambio de `asset_tag`:** la identidad estable es técnica
    (`asset_bridge.id`/`receipt_unit_uuid`), **no** el `asset_tag`. Se conserva un **alias histórico**
    (`..._asset_tag_aliases`) y el gateway resuelve tag **actual e históricos** → una etiqueta física
    impresa **nunca** queda rota por un rename. Política recomendada: `asset_tag` **inmutable tras
    emitir la etiqueta**.
14. **Costo del activo = costo por línea, no prorrateo del total general.** Se registra
    `final_unit_price`/`final_line_total` por línea (+ descuento/impuesto/gasto opcionales, con
    política de asignación) y un `unit_cost` **derivado de esa línea**; `Infocom` recibe el **costo
    atribuible al activo concreto**, nunca el total general dividido entre activos.

## Regla 0 / cumplimiento
No se modifica el core de GLPI ni el de Snipe-IT; integración sólo por **API soportada**. Sin
copiar código (AGPL). Verificación de core intacto por CI, como en fases previas.

## Roadmap (resumen; detalle en `../architecture/snipeit-integration-architecture.md`)
`SI-0` contrato/descubrimiento · `SI-1` read-only (client + asset_bridge + reconciliación +
gateway QR + prueba de label) · `SI-2` checkout/checkin · `SI-3` labels/impresión masiva ·
`SI-4` compras→activo · `SI-5` aceptación/firma física referenciada.
