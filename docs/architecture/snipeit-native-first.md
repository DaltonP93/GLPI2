# Native-first Snipe-IT (v8.7.2) — qué se reutiliza vs. qué es de GLPI/GLPI2

Análisis del **código real** de Snipe-IT (entregado como referencia **read-only**; **AGPL-3.0**
→ integración **sólo por API**, sin copiar código). Clasificación por capacidad:
**USE AS-IS** (usar tal cual en Snipe) · **CONFIGURE** (config de Snipe, sin tocar core) ·
**API INTEGRATION** (consumir por API) · **GLPI OWNED** · **GLPI2 OWNED**.

> No se desarrollan equivalentes de lo que Snipe ya ofrece. **Un dato, un dueño.**

## Matriz de capacidades (con evidencia `snipe-it-master`, v8.7.2)

| Capacidad | Evidencia (endpoint/clase) | Clasificación | Nota |
|---|---|---|---|
| Hardware **list/get** | `Route::resource('hardware', AssetsController)`; `GET assets/bytag/{tag}` (`showByTag`) | **API INTEGRATION** | lookup por asset tag disponible. |
| Hardware **create/update** | `POST hardware`; `PATCH/PUT hardware/{asset}` (`AssetsController@store/update`) | **API INTEGRATION** | crear activo Snipe al recibir compra (SI-4). |
| **Checkout / checkin** | `POST hardware/{id}/checkout|checkin`; `POST assets/bytag/{tag}/checkout|checkin`; `POST checkinbytag` | **USE AS-IS + API INTEGRATION** | Snipe es dueño; GLPI sólo refleja custodia (SI-2). |
| **Audit** de activo | `POST hardware/{asset}/audit` | **USE AS-IS** | auditoría física de Snipe. |
| **Asset labels / QR** | `POST /api/v1/hardware/labels` (`getLabels`); settings `label2_2d_type`, `label2_2d_target=plain_asset_tag`, `label2_2d_prefix` (`app/View/Label.php`) | **CONFIGURE + API INTEGRATION** | QR = `prefix + asset_tag` **por configuración**; render/impresión masiva por API. |
| **Requestable assets / requests** | `GET requests`; `POST request/{asset}`, `.../cancel`; `GET requestable/hardware|models|...` (`CheckoutRequest`) | **USE AS-IS** | solicitud de equipo física en Snipe (futuro; el portal GLPI2 sólo enlaza). |
| **Accessories / components / consumables / licenses** | `*/checkout`, `*/checkin`, `requestable/*` (controllers respectivos) | **USE AS-IS** | dominio físico de Snipe (fases posteriores). |
| **Acceptance / firma de entrega** | `CheckoutAcceptance` (`accept()/decline()`, `signature_filename`, PDF con `<img src="@signature">`) | **USE AS-IS (Snipe OWNED)** | GLPI2 sólo **referencia** estado/fecha/URL/hash. Imagen de firma ≠ firma digital (ADR-0014). |
| **Suppliers / manufacturers / models / categories / custom fields** | modelos y endpoints nativos (`Supplier`, `Manufacturer`, `AssetModel`, `Category`, `CustomField`) | **API INTEGRATION (mapeo)** | mapeo por **ID** en `asset_bridge`/tablas de mapeo; nunca por nombre. |
| **Users / locations / departments (companies)** | modelos nativos + `company_id` scoping | **API INTEGRATION (mapeo)** | correlación por ID; multi-entidad GLPI se respeta del lado GLPI. |
| **Orders (adquisición)** | `app/Models/Order.php`: *"**Explicitly NOT a purchase-order workflow (no state machine, approvals, receiving)**"* | **GLPI2 OWNED** | **no** se usa como workflow; compras/aprobaciones = `companypurchasing`. |
| **Inventario técnico (CPU/RAM/OS/software/red)** | — (GLPI Agent/SNMP) | **GLPI OWNED** | **no** se sobrescribe desde Snipe; no va a etiquetas. |
| **Tickets / SLA / Help Desk / KB / CMDB técnica** | — | **GLPI OWNED** | **no** se crea un segundo help desk en Snipe. |
| **Compras / workflow / aprobaciones / firma empresarial** | — | **GLPI2 OWNED** | `companyworkflow` + `companypurchasing` + `companysignature`. |
| **Ficha segura por QR + creación de ticket** | — (`companyqr`, Fase 1) | **GLPI2 OWNED** | el QR de Snipe apunta al **gateway** de GLPI2. |
| **Webhooks/eventos de Snipe** | Snipe emite notificaciones (Slack/webhook) limitadas; no un bus de dominio completo | **API INTEGRATION (polling + reconciliación)** | SI-1 usa **reconciliación read-only** por API; no se depende de webhooks Snipe para la verdad. |

## Conclusiones
1. **Snipe hace lo físico**; lo consumimos por **API** (o **CONFIGURE** para el QR/etiqueta).
2. **`orders` no es compras** (confirmado en código) → `companypurchasing` se mantiene.
3. **Aceptación/firma física** vive en Snipe; GLPI2 sólo referencia (sin duplicar PII).
4. **Nada de reconstruir** gestión física en GLPI; **nada de copiar código** (AGPL).
5. La verdad de cada campo la fija la **matriz Source of Truth**
   (`snipeit-glpi-ownership-matrix.md`); el mapeo estable lo da `asset_bridge`.
