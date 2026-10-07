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

> Estado en la baseline de Fase 2 (`../releases/phase2-baseline.md`):
> - **implementados:** `companyqr`, `companyworkflow`, `companysignature` (sólo aprobación interna / evidencia),
>   `companyintegrations` (SI-1 + SI-4, worker deshabilitado) y `companypurchasing` v1;
> - **esqueletos sin lógica:** `companyportal` y `companydashboard`;
> - **no implementada:** la integración de firma certificada.

## Servicios desacoplados (`services/*`)
| Servicio | Estrategia | Rol |
|----------|-----------|-----|
| `ai-assistant` | Servicio | RAG con ACL, cita fuente, escala a persona |
| `whatsapp-adapter` | Servicio | Canal WhatsApp con proveedor intercambiable |
| `integration-hub` | Servicio | Normaliza webhooks, idempotencia, `correlation_id`, reintentos |

## Dependencias entre módulos

Estado real del código en la baseline de Fase 2: ver `../releases/phase2-baseline.md` §4. Ningún `setup.php`
declara dependencias; todas son de runtime y fallan cerradas.

- `companypurchasing` → requiere `companyworkflow` ≥ 0.6.0 (estados y aprobaciones) y `companysignature` (versiones,
  evidencia y PDF).
  - Lee **opcionalmente** `companyintegrations` (`InventoryLinkApi`, sólo lectura).
  - **No** usa `companyqr` directamente.
- `companysignature` → requiere `companyworkflow`: escucha sus eventos y lee su ledger. Compras lo invoca para el PDF
  y la evidencia de aprobación.
- `companyintegrations` (worker SI-4) → requiere `companypurchasing` (`PurchasingIntegrationApi`) y `companyqr` ≥ 0.3.0
  (`CompanyQrApi`: código y etiqueta del activo). SI-1 sólo habla con Snipe-IT por HTTP.
- `companyqr` → independiente: hooks sobre activos nativos.
- *(Planificado, sin implementar)* `companyintegrations` ↔ `integration-hub`: eventos y webhooks hacia sistemas
  externos. `integration-hub` es un esqueleto.
- *(Planificado, sin implementar)* `ai-assistant` → lee conocimiento y tickets vía API REST v2 con ACL.

## Roadmap (resumen)
`Fase 0 Fundaciones` → `Fase 1 ITSM/Activos` → `Fase 2 Compras` →
`Fase 3 Portal/Dashboards` → `Fase 4 Integraciones` → `Fase 5 IA/Conocimiento` →
`Fase 6 Madurez`. Detalle en `master-document.md` (§16).

> **Plugin de validación de Fase 0 → Fase 1:** `companyqr` (el más pequeño;
> valida instalación, permisos, hooks, i18n, auditoría, migraciones y upgrade).
