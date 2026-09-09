# ADR-0001: Arquitectura de la plataforma modular sobre GLPI

- **Estado:** Aceptado
- **Fecha:** 2026-09-09
- **Decisores:** Propietario del producto, equipo de plataforma
- **Módulo/área:** Transversal

## Contexto
Se necesita un portal interno empresarial que unifique soporte, activos,
solicitudes, compras, aprobaciones, conocimiento, integraciones y analítica.
GLPI 11 (estable **11.0.8**) cubre de forma nativa ITSM, inventario/CMDB,
usuarios, entidades, SLA, base de conocimiento y —novedad de la versión 11—
formularios nativos, activos personalizados (*Asset Definitions*), portal de
autoservicio, webhooks, 2FA y API REST v2. Los diferenciadores de la
organización (compras, workflows, firma, QR, IA, WhatsApp) no son nativos.

## Decisión
Adoptar una arquitectura en **5 capas** desacoplada del core:

1. **Experiencia** — portales (autoservicio, técnico, aprobadores), móvil, canales.
2. **GLPI (core, inmutable)** — ITSM, activos, usuarios, SLA, conocimiento, Forms.
3. **Plugins propios** — compras, workflows, QR, dashboards, firma, integraciones, portal.
4. **Servicios desacoplados** — IA (RAG), adaptador WhatsApp, integration-hub.
5. **Datos/observabilidad** — base GLPI, tablas de plugin con prefijo propio,
   almacenamiento documental, logs estructurados, métricas y auditoría.

GLPI permanece como **dependencia upstream** (descargada, no copiada). Los
módulos propios viven en `plugins/` y `services/` de este repositorio.

## Alternativas consideradas
- **Fork de GLPI y desarrollo dentro del core** — descartado: rompe la
  actualizabilidad (prioridad #1 del producto) y la seguridad.
- **Todo como servicios externos sin plugins** — descartado: pierde integración
  nativa (permisos, entidades, activos, UI) y duplica lógica que GLPI ya provee.
- **Arquitectura modular en capas (elegida)** — equilibra reutilización nativa,
  desacoplamiento y capacidad de evolución.

## Consecuencias
- (+) GLPI se actualiza sin perder personalizaciones.
- (+) Cada módulo es versionable, testeable y reemplazable.
- (+) Reutiliza al máximo capacidades nativas de GLPI 11.
- (−) Requiere disciplina de contratos (API/hooks) y una suite de regresión de
  actualización.

## Cumplimiento de la Regla 0
La arquitectura está diseñada explícitamente para **no modificar el core**.
Ver `ADR-0002`.
