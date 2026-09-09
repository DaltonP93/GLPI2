# Mapa de módulos

Relación entre módulos del documento maestro, su estrategia y dónde viven.

## Plugins propios (`plugins/*`)
| Plugin | Estrategia | Se apoya en (nativo GLPI 11) | Construye |
|--------|-----------|------------------------------|-----------|
| `companyportal` | Extender | Self-Service Portal + Forms | Personalizaciones de UI/portal |
| `companypurchasing` | Construir | Forms (intake), Suppliers/Budgets, Assets | Circuito de compras + cotizaciones + PDF |
| `companyworkflow` | Construir/Extender | Validaciones nativas (casos simples) | Motor de estados/transiciones reutilizable |
| `companyqr` | Construir | Activos nativos, creación de ticket | QR por activo + ficha segura |
| `companydashboard` | Configurar + Construir | Dashboards nativos | Widgets/KPIs no nativos |
| `companysignature` | Construir + Integrar | Historial/validación | Evidencia (hash/timestamp/PDF) + integración firma certificada |
| `companyintegrations` | Integrar/Construir | Webhooks nativos | Conectores + mapeo/idempotencia |

## Servicios desacoplados (`services/*`)
| Servicio | Estrategia | Rol |
|----------|-----------|-----|
| `ai-assistant` | Servicio | RAG con ACL, cita fuente, escala a persona |
| `whatsapp-adapter` | Servicio | Canal WhatsApp con proveedor intercambiable |
| `integration-hub` | Servicio | Normaliza webhooks, idempotencia, `correlation_id`, reintentos |

## Dependencias entre módulos
- `companypurchasing` → usa `companyworkflow` (estados) y `companyqr` (alta de activo).
- `companysignature` → invocado por `companypurchasing` (PDF/evidencia de aprobación).
- `companyintegrations` ↔ `integration-hub` → eventos/webhooks hacia sistemas externos.
- `ai-assistant` → lee conocimiento/tickets vía API REST v2 con ACL.

## Roadmap (resumen)
`Fase 0 Fundaciones` → `Fase 1 ITSM/Activos` → `Fase 2 Compras` →
`Fase 3 Portal/Dashboards` → `Fase 4 Integraciones` → `Fase 5 IA/Conocimiento` →
`Fase 6 Madurez`. Detalle en `master-document.md` (§16).

> **Plugin de validación de Fase 0 → Fase 1:** `companyqr` (el más pequeño;
> valida instalación, permisos, hooks, i18n, auditoría, migraciones y upgrade).
