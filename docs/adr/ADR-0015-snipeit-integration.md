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

## Regla 0 / cumplimiento
No se modifica el core de GLPI ni el de Snipe-IT; integración sólo por **API soportada**. Sin
copiar código (AGPL). Verificación de core intacto por CI, como en fases previas.

## Roadmap (resumen; detalle en `../architecture/snipeit-integration-architecture.md`)
`SI-0` contrato/descubrimiento · `SI-1` read-only (client + asset_bridge + reconciliación +
gateway QR + prueba de label) · `SI-2` checkout/checkin · `SI-3` labels/impresión masiva ·
`SI-4` compras→activo · `SI-5` aceptación/firma física referenciada.
