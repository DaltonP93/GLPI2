# Diseño técnico — `companyqr`

Complementa `../adr/ADR-0011-companyqr.md` y `../functional/companyqr.md`. Todo vía plugin
y APIs/hooks/controladores **soportados**. **Sin tocar el core.**

> **Core = dependencia upstream.** Las referencias a clases/rutas del core (`Ticket`,
> `Item_Ticket`, `BarcodeManager`, `src/Glpi/Routing/PluginRoutesLoader.php`, etc.) son del
> repositorio oficial **`glpi-project/glpi` @ `11.0.8`**, **no** de `DaltonP93/GLPI2`.

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
| GET | `/plugins/companyqr/scan/{token}` | `ScanController::scan` | **`AUTHENTICATED`** | **Ruta estándar (la que codifica el QR).** El firewall de GLPI exige login y **preserva el retorno**. Con sesión → resolver activo → **ACL nativa** → ficha segura por whitelist. |
| POST | `/plugins/companyqr/scan/{token}/report` | `ReportController::authenticated` | `AUTHENTICATED` | Solicitante = usuario en sesión; CSRF; crea ticket vinculado. |
| GET | `/plugins/companyqr/public/{token}` | `ScanController::public` | `NO_CHECK` | **Sólo si `anonymous_enabled=1`** (si no → 404/redirect a login). Devuelve **subset mínimo** (código + tipo + botón reportar). |
| POST | `/plugins/companyqr/public/{token}/report` | `ReportController::anonymous` | `NO_CHECK` | **Sólo si `anonymous_enabled=1`**; `callAsSystem` + **rate limit + Altcha (ambos)**. |
| GET | `/plugins/companyqr/label/{code_id}` | `LabelController::pdf` | `AUTHENTICATED` | Requiere derecho `companyqr:print`; PDF individual. |
| POST | `/plugins/companyqr/admin/{action}` | `AdminController` | `AUTHENTICATED` | `generate`/`rotate`/`revoke`; requiere `companyqr:generate`. |

- **La ruta estándar NO es `NO_CHECK`.** El QR codifica por defecto `/scan/{token}`
  (`AUTHENTICATED`): así la ficha estándar **nunca** es pública por accidente; el login y
  el retorno los maneja el firewall nativo. El token sólo **identifica** (evita
  enumeración); la autorización es **siempre** ACL nativa (el QR no es un gate).
- **El anónimo vive en una ruta separada y explícita** (`/public/{token}`), **apagado por
  defecto** (`anonymous_enabled=0`). Habilitarlo es una decisión consciente del admin; no
  altera la ruta estándar ya impresa. Compensación aceptada: si algún día se quiere que un
  set de etiquetas sea de acceso anónimo, se imprimen apuntando a `/public/{token}`
  (reimpresión deliberada) — se prioriza la **claridad de seguridad** sobre la comodidad
  del toggle.
- Confirmar accesibilidad de las rutas bajo `/plugins/companyqr/...` con un **test de
  integración** en GLPI `11.0.8` (job `integration` de CI).
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
| `public_code` | varchar(255) **unique** | **código visible propio del plugin** (`UNIQUE`). Copia `otherserial` si es válido; si no, valor generado. **Nunca** se escribe de vuelta en `otherserial`. |
| `status` | enum(`active`,`suspended`,`revoked`) | estado del código |
| `date_creation`,`date_mod` | datetime | |
| `users_id_creation` | int | quién lo generó |
| `revocation_reason` | varchar(255) null | |

Restricción: **una fila por activo** (unique `itemtype,items_id`); la rotación cambia el
`token` in situ (el anterior deja de resolver); la revocación cambia `status` (fila +
historial se conservan).

**Estrategia de `public_code` (no toca datos maestros):**
```
Activo GLPI
   │
   ├── otherserial válido (no vacío)  ──►  public_code := otherserial   (p. ej. NB-001245)
   │
   └── otherserial vacío/duplicado    ──►  public_code := <PREFIJO_TIPO>-<secuencia propia>
                                            (se guarda SOLO en glpi_plugin_companyqr_codes)
```
- `public_code` tiene restricción **`UNIQUE`** en la tabla del plugin.
- El plugin **jamás** hace `UPDATE` sobre `otherserial` del core (no normaliza inventario
  silenciosamente). Un proceso de normalización de inventario sería un módulo aparte.
- Prefijos por `itemtype` configurables (`code_prefix_map`), p. ej. `Computer`→(`NB`
  notebook / `PC` desktop según subtipo/modelo configurable), `Monitor`→`MON`,
  `Printer`→`IMP`, `NetworkEquipment`→`NET`.

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
- **Unitarios (servicios, sin bootstrap completo de GLPI):** generación de token
  (entropía/unicidad); `public_code` (otherserial presente/ausente/fallback/unicidad);
  whitelist de `AssetResolver` **no** contiene IP/MAC/hostname/VLAN; `QrRenderer` produce
  un QR de una URL.
- **Integración (stack CI, dentro del contenedor `glpi`):**
  - **Ruteo (obligatorio):** `/plugins/companyqr/scan/{token}` accesible en `11.0.8`
    (autenticado → redirige a login), y `/plugins/companyqr/public/{token}` responde según
    `anonymous_enabled`.
  - **Gate Forms (runtime):** `tests/integration/forms_gate.php` confirma/deroga si Forms
    nativo permite previncular+lockear el activo del QR de forma soportada (decide Forms vs.
    formulario propio; registra evidencia).
  - **🔒 Multi-entidad (ACL obligatoria):** *Usuario A (entidad A)* escanea un QR de un
    *activo de la entidad B* → **ACCESO DENEGADO** (`AssetResolver`/`canViewItem` niega;
    resultado `denied`). Ningún dato del activo se filtra.
  - **🔒 No-fuga por whitelist:** *usuario sin permiso de ver el activo* (o modo anónimo) →
    la respuesta **no** contiene **IP, MAC, hostname, responsable/usuario, VLAN ni
    ubicación restringida** (assert por clave; falla si aparece cualquiera).
  - Scan autenticado: con sesión + rights → ficha; sin rights → denegado.
  - Anónimo OFF (default): `/public/{token}` → 404/redirect (sin fuga).
  - Anónimo ON: `/public/{token}` → sólo subset mínimo (assert sin campos prohibidos).
  - Reporte → ticket creado y **vinculado al activo** (`Item_Ticket`), categoría/urgencia
    por config.
  - Anónimo: rate limit **y** Altcha (ambos).
  - Ciclo de vida: rotar invalida token viejo y valida el nuevo; revocar → "no disponible";
    purga → revocado + historial retenido.
  - i18n ES/EN (catálogos cargan).
  - **Etiqueta real:** el plugin genera un **PDF 70,75×24 mm** con QR+código+tipo; CI lo
    **sube como artefacto** (evidencia). Sin campos prohibidos.
- **ACL:** la matriz de la sección "Matriz de ACL" se verifica caso por caso; las dos
  pruebas marcadas 🔒 son **obligatorias** y fail-closed.
- **CI:** el job `integration` instala/activa `companyqr` (ya lo hace) y corre la suite del
  plugin (`tests/security/companyqr-acl.sh` orquesta los scripts PHP dentro del
  contenedor); fail-closed; sin romper lo existente.

## Rama / PR
Rama `claude/companyqr` desde `main` (`444009a`). Al implementar: PR de Fase 1 **sin
auto-merge**, CI verde requerido, luego revisión y squash merge. **Nunca** desarrollo en `main`.
