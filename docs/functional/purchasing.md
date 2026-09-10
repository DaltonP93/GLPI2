# Funcional — Compras (`companypurchasing`)

## Objetivo
Circuito de compras sin papel: solicitud → aprobaciones → compra → recepción →
entrega, con evidencia y trazabilidad en cada paso, en PYG y español.

## Formulario de solicitud (configurable, intake con Forms nativo)
Campos base: número, solicitante, departamento, fecha, motivo, ítems, cantidad,
descripción, destino, proveedor/lugar sugerido, estimado (PYG), observaciones,
adjuntos. El formulario debe ser configurable por **tipo de solicitud**.

## Estados (configurables — no reescribir el módulo para cambiarlos)
`Borrador → Enviada → Pendiente Jefe de Área → Aprobada por Jefe → En Compras →
Pendiente Gerencia Financiera → Aprobada/Rechazada → En compra → Recibida →
Entregada → Cerrada`. Motor delegado en `companyworkflow`.

## Reglas
- **Aprobadores por rol/configuración**, nunca personas hardcodeadas.
- Cada transición registra: usuario, rol, fecha/hora, comentario, estado
  anterior/nuevo, origen/canal y evidencia técnica disponible (auditoría).
- **Cotizaciones y documentos versionados**, ligados a la solicitud.
- Al recibir un bien **inventariable**: acción para **crear/vincular activo GLPI**
  (activos nativos) y **generar QR** (vía `companyqr`).
- Importes en **PYG** (miles sin decimales por defecto).

## Salidas
- PDF de solicitud aprobada con evidencia (ver `signature.md`).
- Métricas de compras (ver `metrics.md`).

## Datos
Tablas propias con prefijo `glpi_plugin_companypurchasing_*`, migraciones
reversibles. Nunca SQL directo al core.

## Análisis native-first (resumen)
- Intake: **Forms nativo**. Proveedores/presupuestos: **Management nativo**.
  Activos en recepción: **activos nativos**. Circuito de estados: **Construir**.
- ADR: `../adr/ADR-0006-purchasing-workflow.md`.
