# ADR-0006: Workflow de Compras configurable

- **Estado:** Aceptado
- **Fecha:** 2026-09-09
- **Decisores:** Producto, Compras, plataforma
- **Módulo/área:** `plugins/companypurchasing`, `plugins/companyworkflow`

## Contexto
Compras necesita un circuito de solicitud → aprobaciones → compra → recepción →
entrega, con evidencia por cada transición. GLPI ofrece nativamente proveedores,
presupuestos, validaciones/aprobaciones y —en 11— **Forms** para el intake y
**activos** para el alta en recepción, pero **no** una máquina de estados de
compras configurable.

## Decisión
Construir **`companypurchasing`** (plugin propio) apoyado en lo nativo:

- **Intake** con **Forms nativo** de GLPI 11 cuando cubra el formulario; campos
  base: número, solicitante, departamento, fecha, motivo, ítems, cantidad,
  descripción, destino, proveedor sugerido, estimado (PYG), observaciones, adjuntos.
- **Motor de estados configurable** (delegado en `companyworkflow`): permite
  agregar/quitar pasos sin reescribir el módulo. Flujo inicial:
  `Borrador → Enviada → Pendiente Jefe de Área → Aprobada por Jefe → En Compras →
   Pendiente Gerencia Financiera → Aprobada/Rechazada → En compra → Recibida →
   Entregada → Cerrada`.
- **Aprobadores por rol/configuración**, nunca personas hardcodeadas.
- Cada transición registra **usuario, rol, fecha/hora, comentario, estado
  anterior/nuevo, canal y evidencia** (auditoría append-oriented).
- **Cotizaciones y documentos versionados** y ligados a la solicitud.
- Al recibir un bien inventariable: acción para **crear/vincular el activo GLPI**
  y generar su **QR** (vía `companyqr`).

## Alternativas consideradas
- **Estados hardcodeados en el plugin** — descartado: viola "workflow configurable"
  y "no hardcodear aprobadores".
- **Plugin de terceros de compras** — a evaluar en el análisis nativo del módulo;
  si no cumple requisitos locales (PYG, roles, evidencia), se construye propio.
- **Motor de workflow reutilizable + plugin de compras (elegida)**.

## Consecuencias
- (+) Compras sin papel, auditable y adaptable por administración.
- (+) `companyworkflow` reutilizable por otros módulos.
- (−) Mayor complejidad; exige buen diseño de auditoría y permisos.

## Cumplimiento de la Regla 0
Plugins propios + Forms/activos nativos. Sin edición de core.
