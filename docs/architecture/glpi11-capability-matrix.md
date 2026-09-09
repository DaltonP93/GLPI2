# Matriz de capacidades GLPI 11 (referencia anti-duplicación)

Para cada requisito de la plataforma se indica el **veredicto** del análisis
native-first y la **referencia oficial** relevante. Esta matriz es de consulta
**obligatoria** antes de desarrollar: si una fila dice *Nativo* o *Configurar*,
**no se construye** nada propio.

## Leyenda de veredictos
- **Nativo** — GLPI 11 lo provee; usar tal cual.
- **Configurar** — nativo, requiere configuración/parametrización.
- **Plugin existente** — cubierto por plugin oficial/comunitario a evaluar.
- **Extender** — plugin propio sobre una base nativa (hooks/API).
- **Servicio** — servicio externo desacoplado (API/webhook).
- **Construir** — plugin propio (no existe base nativa suficiente).

> Referencias rápidas: Help Center (`help.glpi-project.org`), API REST v2
> (`.../configuration/general/api/restful-api-v2`), documentación de desarrollador
> (hooks/plugins), Marketplace.

## Matriz

| # | Requisito | Veredicto | Módulo | Referencia oficial (endpoint / hook / clase / doc) |
|---|-----------|-----------|--------|----------------------------------------------------|
| 1 | Inventario / CMDB (PC, red, software, relaciones) | **Configurar** | GLPI core | Inventario nativo + agente; Help Center → Assets |
| 2 | Tipos de activo personalizados | **Nativo** | GLPI core | **Asset Definitions** (Setup → Asset Definitions); clase `GlpiCustomAsset...` |
| 3 | Help Desk / Tickets, colas, plantillas, reglas | **Configurar** | GLPI core | Tickets + Business Rules; Help Center → Assistance |
| 4 | SLA / OLA | **Configurar** | GLPI core | SLA nativo (Setup → Dropdowns → SLA) |
| 5 | Catálogo de servicios / formularios condicionales | **Nativo** | `companyportal` (extiende) | **Forms nativo** (Administración → Formularios) |
| 6 | Portal de autoservicio | **Extender** | `companyportal` | **Self-Service Portal** nativo + hooks de UI |
| 7 | Base de conocimiento (revisión/publicación) | **Configurar** | GLPI core | KB nativa con validación |
| 8 | Correo entrante/saliente + notificaciones | **Configurar** | GLPI core | Mailcollector + Notifications (Setup → Notifications) |
| 9 | Identidad: local / LDAP / OIDC / OAuth | **Configurar** | GLPI core | Authentication (Setup → Authentication) |
| 10 | MFA / 2FA | **Configurar** | GLPI core | **2FA nativo** (GLPI 11) |
| 11 | API para lectura/escritura de datos GLPI | **Nativo** | transversal | **API REST v2** (`/api`) — preferida sobre v1 `apirest.php` |
| 12 | Webhooks de eventos (ticket, activo, etc.) | **Nativo** | `companyintegrations`/`integration-hub` | **Webhooks nativos** (Setup → Webhooks) |
| 13 | Dashboards | **Configurar + Construir** | `companydashboard` | **Dashboards nativos** (Grid) + widgets propios sólo si faltan KPIs |
| 14 | Historial / trazabilidad de cambios | **Configurar** | GLPI core | Historial nativo por ítem (`Log`) |
| 15 | Solicitud de compra + circuito de aprobación | **Construir** | `companypurchasing` | No nativo; intake con **Forms**, tablas `glpi_plugin_companypurchasing_*` |
| 16 | Motor de estados/transiciones configurable | **Construir** | `companyworkflow` | No nativo (validaciones nativas sólo para casos simples) |
| 17 | Proveedores / presupuestos | **Configurar** | GLPI core | Management (Suppliers, Budgets) |
| 18 | Aprobación/validación simple | **Configurar** | GLPI core | Validaciones nativas de tickets/cambios |
| 19 | Firma electrónica interna (hash + timestamp + evidencia) | **Construir** | `companysignature` | No nativo; genera PDF con hash/QR de verificación |
| 20 | Firma digital certificada | **Servicio** | `companysignature` + proveedor | Integración externa según requisito legal |
| 21 | QR por activo + ficha segura + ticket desde activo | **Construir** | `companyqr` | No nativo; se apoya en activos nativos y creación de ticket |
| 22 | Canal WhatsApp (bot, escalado, notificaciones) | **Servicio** | `whatsapp-adapter` | Externo vía API/webhooks; proveedor intercambiable |
| 23 | Asistente IA (RAG con ACL, citas, escalado) | **Servicio** | `ai-assistant` | Externo; consume API REST v2 con permisos |
| 24 | Hub de integraciones (mapeo, idempotencia, reintentos) | **Servicio** | `integration-hub` | Externo; normaliza webhooks nativos |
| 25 | Métricas/KPIs por área | **Configurar + Construir** | `companydashboard` | Dashboards nativos + KPIs propios (`../functional/metrics.md`) |
| 26 | Localización es / America\_Asuncion / PYG | **Configurar** | GLPI core | Idioma, zona horaria por usuario, monedas |

## Notas de uso
- Antes de construir cualquier fila marcada **Construir/Extender/Servicio**, verificar
  en Marketplace si existe un **plugin existente** aceptable (columna Veredicto puede
  cambiar a "Plugin existente" tras la evaluación) y registrar la decisión en un ADR.
- Las referencias exactas de endpoints/clases se fijan al desarrollar cada módulo,
  contra la documentación de la versión objetivo (11.0.8).
