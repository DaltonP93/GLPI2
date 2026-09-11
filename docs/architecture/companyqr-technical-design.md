# Diseño técnico — `companyqr`

Complementa `../adr/ADR-0011-companyqr.md` y `../functional/companyqr.md`. Todo vía plugin
y APIs/hooks/controladores **soportados**. **Sin tocar el core.**

## Estructura del plugin
```
plugins/companyqr/
  setup.php  hook.php
  src/
    Controller/      ScanController · ReportController · LabelController · AdminController (delgados)
    Service/         QrRenderer · LabelRenderer · AssetResolver · TicketCreator · AuditService · AccessPolicyService · CodeManager
    Model/           Code (glpi_plugin_companyqr_codes) · ScanEvent (glpi_plugin_companyqr_scans)
  templates/         fiche.html.twig · fiche_anon.html.twig · label.html.twig · asset_button.html.twig
  locales/           es_ES.po/.mo · en_GB.po/.mo
  tests/
```
- **PSR-4:** `GlpiPlugin\Companyqr\` → `plugins/companyqr/src/`.
- **Controladores delgados:** parsean request → llaman a un Service → devuelven Twig/redirect/PDF.
- **Adaptadores (único punto de contacto con el core):**
  - `QrRenderer` → envuelve `BarcodeManager`/tc-lib-barcode.
  - `LabelRenderer` → envuelve `tcpdf`.
  - `AssetResolver` → token→activo; calcula código visible; **whitelist** de campos seguros; aplica `canViewItem`.
  - `TicketCreator` → `Ticket` + `Item_Ticket`; categoría/urgencia/entidad/origen configurables.
  - `AuditService` → escribe `_scans` (mínimo, sin PII) + `Log` en acciones sensibles.
  - `AccessPolicyService` → decide autenticado/anónimo, ACL de entidad y anti-abuso.
  - `CodeManager` → alta/rotación/revocación/sincronización de ciclo de vida.

## Rutas / controladores (definitivos)
Autoregistradas por `Glpi\Routing\PluginRoutesLoader` (prefijo `/plugins/companyqr`
automático). Namespace `GlpiPlugin\Companyqr\Controller`.

| Método | Ruta (efectiva) | Controlador | SecurityStrategy | Política en controlador |
|---|---|---|---|---|
| GET | `/plugins/companyqr/s/{token}` | `ScanController` | `NO_CHECK` | Si hay sesión → ficha por ACL. Si no → **modo anónimo OFF (default): redirige a login con retorno**; ON: subset mínimo. |
| POST | `/plugins/companyqr/report/{token}` | `ReportController` | `NO_CHECK` | Autenticado → solicitante en sesión. Anónimo (si ON) → `callAsSystem` + **rate limit + Altcha**. |
| GET | `/plugins/companyqr/label/{code_id}` | `LabelController` | `AUTHENTICATED` | Requiere derecho `companyqr:print`; PDF individual. |
| POST | `/plugins/companyqr/admin/{action}` | `AdminController` | `AUTHENTICATED` | `generate`/`rotate`/`revoke`; requiere `companyqr:generate`. |

- **Un único URL en el QR** (`/s/{token}`), estable de por vida; el modo anónimo es un
  **toggle de configuración** sin reimprimir etiquetas. La política se aplica en el
  controlador (el QR no es un gate de seguridad). Confirmar accesibilidad bajo
  `/plugins/companyqr/...` con un **test de integración** en GLPI 11.0.8.
- **Lote de etiquetas:** ruta preparada (`POST /plugins/companyqr/labels`) pero no se
  implementa complejidad de lote en v1 si compromete la entrega.

## Modelo de datos (definitivo)
Prefijo `glpi_plugin_companyqr_`, migraciones **reversibles**, sin FK a tablas core por SQL
directo (se referencia por `itemtype`/`items_id` como hace el core).

### `glpi_plugin_companyqr_codes`
| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK | |
| `itemtype` | varchar(100) | clase del activo |
| `items_id` | int | id interno (**nunca** expuesto) |
| `entities_id` | int | snapshot de entidad (sincronizado por hooks) |
| `is_recursive` | tinyint | visibilidad |
| `token` | varchar(64) **unique** | identificador opaco (≥128 bits, base32 URL-safe) |
| `public_code` | varchar(255), index | código visible (`otherserial` o fallback) |
| `status` | enum(`active`,`suspended`,`revoked`) | estado del código |
| `date_creation`,`date_mod` | datetime | |
| `users_id_creation` | int | quién lo generó |
| `revocation_reason` | varchar(255) null | |

Restricción: **una fila por activo** (unique `itemtype,items_id`); la rotación cambia el
`token` in situ (el anterior deja de resolver); la revocación cambia `status` (fila +
historial se conservan).

### `glpi_plugin_companyqr_scans` (append-oriented, mínimo, sin PII)
| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK | |
| `plugin_companyqr_codes_id` | int, index | referencia al código |
| `itemtype`,`items_id`,`public_code` | snapshot | sobrevive a cambios del código |
| `date` | datetime | |
| `result` | enum(`resolved`,`login_required`,`not_found`,`revoked`,`asset_gone`,`report_created`,`denied`) | |
| `channel` | varchar(30) | `qr` por defecto |
| `is_anonymous` | tinyint | |
| `actor_users_id` | int null | sólo si autenticado |
| `tickets_id` | int null | si generó ticket |

**No** hay columnas de IP/User-Agent. El rate limiting anónimo usa **cache** (TTL corto),
no esta tabla.

### Configuración
Vía `Config` (contexto `plugin:companyqr`): `anonymous_enabled` (default `0`),
`anon_fields` (default `public_code,type`), `fiche_fields_auth`, `default_itilcategories_id`,
`default_urgency`, `code_prefix_map`, `label_size` (default `70.75x24`), `label_fields`,
`scan_retention_months`. Sin secretos.

## Matriz de ACL

Derecho de plugin `companyqr` (bits): `READ`, `generate` (crear/rotar/revocar), `print`,
`config`. **La visibilidad de datos del activo NO depende del plugin, sino de la ACL nativa.**

| Actor | Ver ficha (datos del activo) | Reportar problema | Generar/rotar/revocar token | Imprimir etiqueta | Config plugin |
|---|---|---|---|---|---|
| Anónimo, modo anónimo **OFF** | ❌ (redirige a login) | ❌ | ❌ | ❌ | ❌ |
| Anónimo, modo anónimo **ON** | Subset mínimo (código+tipo) | ✅ (rate limit + Altcha) | ❌ | ❌ | ❌ |
| Autenticado **sin** derecho de ver el activo | Ficha limitada / denegada según ACL nativa | ✅ según política | ❌ | ❌ | ❌ |
| Autenticado **con** READ del activo (ACL nativa) | ✅ ficha completa segura | ✅ | ❌ | ❌ | ❌ |
| Técnico con `companyqr:generate` | ✅ | ✅ | ✅ | según `print` | ❌ |
| Técnico con `companyqr:print` | ✅ | ✅ | ❌ | ✅ | ❌ |
| Admin con `companyqr:config` | ✅ | ✅ | ✅ | ✅ | ✅ |

Enforcement: entidad + `canViewItem()` nativo para datos del activo; derecho de plugin para
acciones de gestión; CSRF en acciones autenticadas; Altcha + rate limit en anónimo.

## Hooks
- `setup.php`: `csrf_compliant`; `Plugin::registerClass` para pestaña "QR" en activos;
  `$PLUGIN_HOOKS['post_item_form']` → botón "Imprimir etiqueta / Ver QR".
- Ciclo de vida del activo: `$PLUGIN_HOOKS['item_update']` (cambio de entidad),
  `['item_delete']`/`['item_restore']` (papelera), `['pre_item_purge']`/`['item_purge']`
  (purga) → `CodeManager` sincroniza estado. (Nombres exactos a confirmar en implementación.)

## Plan de tests
- **Unitarios (servicios):** generación de token (entropía/unicidad); `public_code`
  (otherserial presente/ausente/fallback/unicidad); `AssetResolver` **no** expone
  IP/MAC/hostname/VLAN (assert de whitelist); `QrRenderer` produce QR de una URL.
- **Integración (stack CI):**
  - **Ruteo (obligatorio):** `/plugins/companyqr/s/{token}` accesible en 11.0.8.
  - **Spike Forms gate:** confirmar/derogar Forms con activo previnculado (decide Forms vs propio).
  - Scan autenticado: sin sesión → login; con sesión + rights → ficha; sin rights → limitado/denegado.
  - Anónimo OFF (default): sin sesión → login (sin fuga de datos).
  - Anónimo ON: sólo subset mínimo (assert sin campos prohibidos).
  - Reporte → ticket creado y **vinculado al activo** (`Item_Ticket`), categoría/urgencia por config.
  - Anónimo: rate limit **y** Altcha (ambos).
  - Ciclo de vida: rotar invalida token viejo (404) y valida el nuevo; revocar → "no disponible"; purga → revocado + historial retenido.
  - i18n ES/EN.
  - Etiqueta PDF 70,75×24 con QR+código+tipo; sin campos prohibidos.
- **ACL:** la matriz anterior se verifica caso por caso.
- **CI:** el job `integration` instala/activa `companyqr` (ya lo hace) y corre la suite del
  plugin; fail-closed; sin romper lo existente.

## Rama / PR
Rama `claude/companyqr` desde `main` (`444009a`). Al implementar: PR de Fase 1 **sin
auto-merge**, CI verde requerido, luego revisión y squash merge. **Nunca** desarrollo en `main`.
