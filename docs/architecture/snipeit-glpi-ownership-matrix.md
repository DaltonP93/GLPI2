# Matriz Source of Truth — Snipe-IT ↔ GLPI ↔ GLPI2

Regla: **un mismo dato no tiene dos dueños.** Si un valor existe en ambos sistemas, esta matriz
declara **cuál prevalece** y en qué **dirección** se sincroniza. Sin sincronización bidireccional
indiscriminada. Nunca DB-a-DB; sólo API.

| Campo / concepto | **Dueño (Source of Truth)** | Sincronización | Precedencia / regla |
|---|---|---|---|
| `asset_tag` / código físico | **Snipe-IT** | Snipe → GLPI (referencia en `asset_bridge`) | Snipe manda; GLPI/GLPI2 no lo reasignan. |
| Custodia (checkout/checkin) | **Snipe-IT** | Snipe → GLPI (evento/reconciliación) | Snipe manda; GLPI refleja para soporte/CMDB. |
| Responsable físico | **Snipe-IT** | Snipe → GLPI | Snipe manda. |
| Ubicación de custodia | **Snipe-IT** | Snipe → GLPI | Snipe manda (distinta de ubicación técnica/red). |
| Estado físico/operativo del activo | **Snipe-IT** | Snipe → GLPI | Snipe manda (status label). |
| Aceptación de entrega (+ firma-imagen) | **Snipe-IT** (`CheckoutAcceptance`) | estado/fecha/URL/hash → GLPI | Snipe manda; GLPI2 sólo referencia; no se copia la firma. |
| Etiqueta/QR (motor de labels) | **Snipe-IT** (config + API) | — | Snipe genera; el **contenido del QR** apunta al gateway GLPI2 (CONFIGURE). |
| Accesorios / consumibles / componentes / licencias | **Snipe-IT** | (futuro) | dominio físico de Snipe. |
| Modelo / categoría / fabricante (catálogos físicos) | **Snipe-IT** | mapeo por **ID** en tablas de mapeo | correlación por ID, nunca por nombre. |
| **Proveedor de adquisición** (`Supplier`) | **GLPI2/GLPI** (Compras / `Supplier` GLPI) | GLPI2 → Snipe (**reflejo/mapping**) | El proveedor de la **compra** lo fija GLPI2; Snipe lo **recibe como reflejo** vía `..._map_suppliers`. Snipe **no** es segundo dueño del proveedor de adquisición. |
| **Identidad corporativa de usuarios** | **GLPI / IdP** | — | Snipe es dueño de la **custodia**, **no** de la identidad. `..._map_users` explícito; **nunca** correlacionar por nombre/email inferido. |
| **Compañía Snipe ↔ entidad GLPI** | **GLPI/GLPI2** (mapeo aprobado) | `snipe_company_id ↔ glpi_entity_id` (`..._map_companies`) | Compañía **no** mapeada → `conflict`/`pending`; **no** se infiere la entidad. Test multi-entidad obligatorio. |
| Serial number | **política declarada** (por defecto: origina quien crea el activo físico —Snipe/Compras—; GLPI Agent lo **verifica**) | condicional | **Nunca** sobrescribir en silencio. Divergencia Snipe↔GLPI → `serial_conflict` (reportar, decisión humana); no se une automáticamente. |
| specs CPU/RAM/OS/software | **GLPI** (Agent/SNMP) | — | **no** se sobrescribe desde Snipe. |
| IP / MAC / hostname / VLAN | **GLPI** | — | **nunca** se copia a etiquetas ni se expone en el QR/portal público. |
| Inventario técnico automático / CMDB técnica | **GLPI** | — | GLPI manda. |
| Tickets / SLA / Help Desk / KB | **GLPI** | — | GLPI manda; no se crea help desk en Snipe. |
| Ficha segura al escanear + creación de ticket | **GLPI2** (`companyqr`) | — | el QR resuelve por `asset_bridge` y entrega la ficha por ACL. |
| Compra / solicitud / aprobaciones / workflow | **GLPI2** (`companypurchasing`+`companyworkflow`) | GLPI2 → Snipe (al recibir: crear activo) | Snipe `orders` **no** es workflow (confirmado en código). |
| Firma/evidencia de **aprobación empresarial** | **GLPI2** (`companysignature`) | — | distinta de la **aceptación física** (Snipe). |
| Entidad / aislamiento multi-tenant | **GLPI/GLPI2** | — | ACL de entidad se aplica del lado GLPI; el mapeo guarda `glpi_entity_id`. |
| Datos de adquisición (fecha/costo/proveedor de compra) | **GLPI2** (compra) → se **reflejan** en Snipe/Infocom | GLPI2 → Snipe (opcional) y GLPI `Infocom` | evitar doble captura; declarar la fuente por despliegue. |

## Notas de precedencia
- **Bidireccional prohibido por defecto.** Cada fila tiene **una** flecha. Donde exista tentación
  de doble edición (p. ej. estado, ubicación), gana el dueño de la fila; el otro sistema lo
  muestra como **reflejo de sólo lectura**.
- **Conflictos** (dos valores divergentes para un campo con dueño único) → se registran como
  `ownership_conflict` en la reconciliación y **no** se auto-resuelven silenciosamente (ver
  `snipeit-integration-architecture.md` §Reconciliación).
- **Identidad de correlación:** siempre **IDs + asset_tag + mapping explícito** (`asset_bridge`),
  **nunca** nombres.
