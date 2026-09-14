# Funcional — Compras (`companypurchasing`)

Diseño funcional de Fase 2. Detalle técnico en
`../architecture/companypurchasing-technical-design.md`; motor en
`../architecture/companyworkflow-technical-design.md`; evidencia/PDF en
`../architecture/companysignature-technical-design.md`; ADRs 0012/0013/0014.

## Objetivo
Reemplazar el **formulario físico** de solicitud de compra por un circuito digital
**auditable, multi-entidad y en PYG**: solicitud → aprobaciones → compra → recepción →
entrega → cierre, con evidencia en cada paso, en español.

## Formulario de solicitud (propio, configurable)
GLPI **Forms** no modela ítems múltiples/cotizaciones/estados de compra → la **entrada rica**
usa un **formulario propio** del plugin (se deja como mejora futura un "alta rápida" con Forms).
Campos (mínimos, parametrizables por tipo de solicitud):
número · solicitante · departamento/área · fecha · motivo/justificación · **uno o varios
ítems** (cantidad · descripción · categoría) · destino · lugar/proveedor sugerido · precio
estimado · **moneda PYG** · observaciones · archivos · cotizaciones · proveedor seleccionado ·
monto final.

## Estados (parametrizables vía `companyworkflow` — no se reescribe el módulo para cambiarlos)
`BORRADOR · ENVIADA · PENDIENTE_JEFE_AREA · EN_COMPRAS · PENDIENTE_GERENCIA_FINANCIERA ·
APROBADA · RECHAZADA · DEVUELTA · EN_COMPRA · RECIBIDA · ENTREGADA · CERRADA · CANCELADA`.
El circuito completo (máquina de estados + matriz de transiciones) vive en `companyworkflow`.

## Reglas
- **Aprobadores por rol/grupo/entidad**, nunca personas hardcodeadas; montos/umbrales por config.
- Cada transición registra usuario, rol, fecha/hora, comentario, estado anterior/nuevo, canal y
  evidencia (auditoría **append-only**; ver `../security/phase2-acl-audit.md`).
- **Cotizaciones y documentos versionados** (Document nativo), ligados a la solicitud.
- **Devolución para corrección** (`DEVUELTA`) permite editar y reenviar; una **edición
  sustantiva tras aprobar reinicia** las aprobaciones afectadas (evidencia/firma).
- Al **recibir** un bien inventariable → **crear activo en Snipe-IT** (autoridad de lo físico) →
  `asset_bridge` → **crear/vincular activo GLPI** + poblar `Infocom` → **generar QR** (`companyqr`)
  → **etiqueta** (motor de Snipe; QR → gateway GLPI2). **Idempotente**. Ver ADR-0015 y
  `../architecture/snipeit-integration-architecture.md`.
- Importes en **PYG** (sin decimales por defecto): estimado / aprobado / final.

## Multi-entidad
Una persona de la entidad A no ve ni aprueba solicitudes de la entidad B salvo permiso
explícito. ACL nativa + entidad en cada acción (test 🔒 fail-closed en CI).

## Salidas
- **PDF de solicitud aprobada** versionado con hash + **QR de verificación** (`companysignature`).
- **Métricas** de compras (tiempos, montos PYG por área/categoría/proveedor, SLA, cuellos de
  botella) — ver el diseño técnico y el futuro `companydashboard`.

## Native-first (resumen)
Reutiliza Supplier/Budget/Infocom/Document/Entity/Group/Notif/Log/TCPDF/API v2/Webhooks nativos;
construye sólo dominio de compras + motor (`companyworkflow`) + evidencia (`companysignature`).
Ver `../architecture/native-first-phase2.md`. ADRs: 0006, 0012, 0013, 0014.
