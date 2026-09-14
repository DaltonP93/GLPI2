# ADR-0013: `companypurchasing` — solicitudes de compra sobre `companyworkflow`

- **Estado:** Aceptado (diseño); implementación pendiente de aprobación humana.
- **Fecha:** 2026-09-14
- **Decisores:** Producto, Compras, finanzas, seguridad, plataforma
- **Módulo/área:** `plugins/companypurchasing` (Fase 2)
- **Complementa:** ADR-0006 (workflow de compras), ADR-0012 (motor), ADR-0014 (firma), ADR-0011 (companyqr)

## Contexto
Hoy la solicitud de compra es un **formulario físico** con circuito de firmas
(Solicitante → Jefe de Área → Compras → Gerencia Financiera → …). Queremos digitalizarlo de
forma **auditable, multi-entidad y en PYG**, reutilizando el máximo de GLPI y sin recrear el
motor de aprobaciones (ver ADR-0012).

## Decisión
Construir **`companypurchasing`** como **dominio de compras** que:

1. Define su **modelo de datos** propio (`glpi_plugin_companypurchasing_*`): solicitud + ítems
   + cotizaciones + eventos de negocio. (Detalle en `../architecture/companypurchasing-technical-design.md`.)
2. **Consume `companyworkflow`** para todo el circuito de estados/aprobaciones. **No** implementa
   su propio motor de estados; los estados iniciales (`BORRADOR … CERRADA/CANCELADA`) son la
   **definición de workflow por defecto**, parametrizable.
3. **Reutiliza nativo**: `Supplier` (proveedor), `Budget` (presupuesto), `Infocom` (al
   inventariar), `Document/Document_Item` (cotizaciones/archivos), `Entity`/`Group`/`Profile`
   (aislamiento y roles), Notificaciones, `Log`, TCPDF, API v2 + Webhooks.
4. **Moneda PYG** por defecto (sin decimales), con importes estimado/aprobado/final; nada de
   montos, departamentos ni aprobadores hardcodeados.
5. Al **recibir**, integra con inventario + `companyqr` (ver
   `../architecture/purchasing-workflow-signature-integration.md`).
6. Emite el **documento aprobado** (PDF versionado con hash + QR de verificación) vía
   `companysignature` (ADR-0014).

## Forms nativo (evaluación)
GLPI **Forms** puede capturar una solicitud simple y crear un Ticket, pero **no** modela
**ítems múltiples, cotizaciones, importes por línea, proveedor seleccionado ni estados de
compra**. → Para la **entrada** rica se usa un **formulario propio** del plugin; se deja como
posible mejora futura ofrecer un Form nativo de "alta rápida" que derive a una solicitud.

## Alternativas consideradas
- **Modelar la compra como Ticket/Change** para heredar validación nativa → rechazado
  (semántica ITIL incorrecta; campos/ítems/cotizaciones forzados). Ver ADR-0012.
- **Comprar/instalar un plugin de compras existente** → evaluado; ninguno cubre multi-entidad +
  PYG + workflow reusable + firma/hash + integración con companyqr sin fork. Se prefiere plugin
  propio delgado sobre nativo.
- **Reusar `Budget`/`Infocom` como "solicitud"** → insuficiente: son datos financieros de
  activos existentes, no un circuito de solicitud/aprobación.

## Consecuencias
- (+) Reemplaza el formulario en papel con trazabilidad completa y métricas.
- (+) Reutiliza el núcleo administrativo de GLPI; superficie propia acotada al dominio.
- (+) Circuito de aprobación desacoplado (motor reusable).
- (−) Depende de `companyworkflow` y `companysignature` (orden de instalación/activación).
- (−) Integración con inventario/`companyqr` requiere permisos y diseño de idempotencia.

## Cumplimiento de la Regla 0
Sólo plugin + APIs/hooks soportados; tablas propias con prefijo. **Sin modificar el core.**

## Compatibilidad
`requirements.glpi` min `11.0`, max `12.0` (excluyente). Depende de `companyworkflow` y
`companysignature` (rango declarado en `setup.php`).
