# Architecture Decision Records (ADR)

Un **ADR** registra una decisión de arquitectura importante: su **contexto**, la
**decisión** tomada, las **alternativas** evaluadas, las **consecuencias** y su
**estado**. Sirven para que el equipo (y quien llegue después) entienda *por qué*
las cosas son como son.

## Reglas del proyecto
- Antes de desarrollar cada módulo funcional se escribe (o actualiza) un ADR con
  el resultado del **análisis nativo GLPI 11**
  (`../architecture/native-first-process.md`).
- Cualquier necesidad de tocar el core exige un **ADR de excepción aprobado por un
  humano** antes de realizar cambio alguno (ver `ADR-0002`).
- Formato basado en el modelo de Michael Nygard.

## Estados posibles
`Propuesto` · `Aceptado` · `Rechazado` · `Reemplazado por ADR-XXXX` · `Obsoleto`

## Índice
| ADR | Título | Estado |
|-----|--------|--------|
| [0001](ADR-0001-platform-architecture.md) | Arquitectura de la plataforma modular | Aceptado |
| [0002](ADR-0002-glpi-core-immutable.md) | El core de GLPI es inmutable | Aceptado |
| [0003](ADR-0003-plugin-strategy.md) | Estrategia de plugins propios | Aceptado |
| [0004](ADR-0004-api-first-integrations.md) | Integraciones API-first | Aceptado |
| [0005](ADR-0005-localization-paraguay.md) | Localización Paraguay (es/PYG/America\_Asuncion) | Aceptado |
| [0006](ADR-0006-purchasing-workflow.md) | Workflow de Compras configurable | Aceptado |
| [0007](ADR-0007-electronic-signature.md) | Firma electrónica vs. firma digital | Aceptado |
| [0008](ADR-0008-ai-architecture.md) | Arquitectura del asistente de IA (RAG) | Aceptado |
| [0009](ADR-0009-whatsapp-omnichannel.md) | WhatsApp y omnicanalidad | Aceptado |
| [0010](ADR-0010-observability-audit-metrics.md) | Observabilidad, auditoría y métricas | Aceptado |
| [0011](ADR-0011-companyqr.md) | `companyqr` — QR por activo, ficha segura y reporte (Fase 1) | Aceptado (implementado) |
| [0012](ADR-0012-companyworkflow.md) | `companyworkflow` — motor de workflow configurable y reusable (Fase 2) | Aceptado (diseño) |
| [0013](ADR-0013-companypurchasing.md) | `companypurchasing` — solicitudes de compra sobre el motor (Fase 2) | Aceptado (diseño) |
| [0014](ADR-0014-companysignature.md) | `companysignature` — aprobación electrónica interna y evidencia (Fase 2) | Aceptado (diseño) |
| [0015](ADR-0015-snipeit-integration.md) | Integración con Snipe-IT (gestión física de activos, API-only) | Aceptado (diseño) |
| [0016](ADR-0016-timezone-init-verification.md) | Inicialización y verificación de timezones (separar configuración de verificación) | Aceptado |
| [0017](ADR-0017-glpi-security-key-hardening.md) | Pre-provisión de `glpicrypt.key` (workaround del bug `kernel.secret` de GLPI 11) | Aceptado |
| [0018](ADR-0018-companypurchasing-approvals.md) | `companypurchasing` P2D-2 — circuito de aprobación sobre workflow + firma | Propuesto |
| [0019](ADR-0019-companypurchasing-receiving.md) | `companypurchasing` P2D-3 — recepción física, costo por unidad y handoff a SI-4 | Propuesto |

> Plantilla para nuevos ADR: [`adr-template.md`](adr-template.md).
