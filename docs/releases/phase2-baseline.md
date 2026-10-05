# Baseline de Fase 2 — inventario real

> **Fuente única: el código de `main` `6c7d9940977204bb2f85786bcfd742f25de5bdda`.**
> Versiones, requisitos, tablas, Acciones automáticas, derechos y configuración se leyeron de
> `plugins/*/setup.php`, `plugins/*/hook.php` y `plugins/*/src/**`. **No** se tomaron de ADRs ni READMEs.
> No hay tag ni GitHub Release: esta baseline todavía no está publicada como release.

## 1. Plataforma probada

| Componente | Valor | Origen |
|---|---|---|
| GLPI | **11.0.8** oficial (upstream, no fork; core no versionado) | `infra/docker/Dockerfile.glpi`, CI |
| Rango declarado por cada plugin | `min 11.0` ≤ GLPI < `max 12.0` (límite superior **excluyente**) | `requirements.glpi` de cada `setup.php` |
| PHP | ≥ 8.2 | `requirements.php.min` de cada `setup.php` |
| Base de datos | MariaDB 11.4 (la del stack de CI/DEV) | `infra/docker/docker-compose.yml` |
| Localización | es_ES · America/Asuncion · PYG (escala 0) | `infra/docker/glpi-config/install-and-localize.sh`, ADR-0005 |

## 2. Versiones y estado por plugin

| Plugin | Versión | GLPI | Estado | Tablas propias | Acción automática | Derecho (`ProfileRight`) |
|---|---|---|---|---|---|---|
| `companyqr` | **0.3.0** | 11.0 – <12.0 | implementado | `codes`, `scans` | — | `plugin_companyqr` |
| `companyworkflow` | **0.6.0** | 11.0 – <12.0 | implementado | `defs`, `statedefs`, `transitions`, `steps`, `instances`, `assignments`, `delegations`, `history` | `Instance::escalation` (1 h) | `plugin_companyworkflow` |
| `companysignature` | **0.5.0** | 11.0 – <12.0 | implementado (firma **interna** / evidencia) | `evidences`, `document_versions`, `reconcile_queue` | `ReconcileTask::reconcile` (5 min) | `plugin_companysignature` |
| `companyintegrations` | **0.6.0** | 11.0 – <12.0 | implementado (SI-1 + SI4-1/2/3) | `asset_bridge`, `asset_tag_aliases`, `map_companies`, `map_users`, `map_models`, `map_glpi_assettypes`, `recon`, `si4_sagas`, `si4_saga_log`, `si4_runtime` | **ninguna** (SI-4 sin CronTask a propósito) | `plugin_companyintegrations` |
| `companypurchasing` | **0.5.0** | 11.0 – <12.0 | implementado (v1) | `requests`, `items`, `numbering`, `scope_defs`, `events`, `quotes`, `quote_items`, `doc_versions`, `docseq`, `policies`, `integrity`, `cost_policies`, `receipt_batches`, `receipt_units`, `inventory_outbox`, `delivery_batches` | `ProjectionTask::reconcileprojection` (15 min) | `plugin_companypurchasing` |
| `companyportal` | **0.1.0** | 11.0 – <12.0 | **skeleton only — no business logic** | — | — | — |
| `companydashboard` | **0.1.0** | 11.0 – <12.0 | **skeleton only — no business logic** | — | — | — |

- Todas las tablas llevan el prefijo `glpi_plugin_<plugin>_`.
- Todas las Acciones automáticas se registran con `mode = 2` (EXTERNAL, CLI). Necesitan el cron del sistema:
  `php front/cron.php`, como el usuario del servidor web. En el stack Docker lo ejecuta el servicio `cron`.
- `install()` es idempotente y seguro en upgrade en los 5 plugins implementados:
  - crea solo lo que falta;
  - siembra solo las claves de configuración ausentes;
  - `CronTask::register()` no duplica;
  - no pisa los derechos ajustados por un administrador.

  El perfil Super-Admin (id 4) recibe todos los bits de cada plugin.
- `uninstall()` borra las tablas propias (migración reversible). Compras también purga sus notificaciones y
  plantillas nativas.

### Bits de derecho (código)

| Derecho | Bits |
|---|---|
| `plugin_companyqr` | `RIGHT_GENERATE` 2 · `RIGHT_PRINT` 4 · `RIGHT_CONFIG` 8 |
| `plugin_companyworkflow` | `RIGHT_ACT` 2 · `RIGHT_ADMIN` 4 · `RIGHT_DELEGATE` 8 · `RIGHT_CONFIG` 16 |
| `plugin_companysignature` | `RIGHT_RECORD` 2 · `RIGHT_VERIFY` 4 · `RIGHT_CONFIG` 8 |
| `plugin_companyintegrations` | `RIGHT_RECONCILE` 2 · `RIGHT_MAP` 4 · `RIGHT_CONFIG` 8 · `RIGHT_SI4` 16 |
| `plugin_companypurchasing` | `CREATE_REQUEST` 2 · `VIEW_OWN` 4 · `VIEW_ENTITY` 8 · `EDIT_DRAFT` 16 · `MANAGE_CONFIG` 32 · `MANAGE_PURCHASING` 64 · `RECEIVE` 128 · `DELIVER` 256 · `VIEW_METRICS` 512 · `INTEGRATION` 1024 |

`READ` (1) es el bit estándar de GLPI en todos.

### Comandos de consola (código)

| Comando | Uso | ¿Seguro con datos reales? |
|---|---|---|
| `plugins:companysignature:reconcile` | reconciliación durable de evidencia (idempotente) | **sí** |
| `plugins:companypurchasing:reconcile` | proyección `domain_state` + saga física (idempotente; reporta anomalías sin mutar) | **sí** |
| `plugins:companyintegrations:si4-run --user=<id> [--profile=<id>]` | worker SI-4; con `si4_enabled = 0` no hace nada | ver `../operations/si4-readiness.md` |
| `plugins:<p>:selftest` (workflow, signature, purchasing, integrations, qr) | integración + E2E | **NO** — ver §6 |
| `plugins:companypurchasing:concurrency-probe` | sonda de tests (exige `COMPANYPURCHASING_ALLOW_PROBE=1`) | **NO** (sólo tests) |
| `plugins:companyqr:emit-fixture` / `verify-report` | fixtures del E2E HTTP | **NO** (sólo tests) |

## 3. Configuración crítica por plugin

Contexto `plugin:<plugin>` en `glpi_configs`. Defaults tomados de cada `src/Service/PluginConfig.php`.

| Plugin | Clave | Default | Por qué es crítica |
|---|---|---|---|
| companypurchasing | `approver_group_area_head` / `_purchasing` / `_finance` | `0` | **obligatorias**: sin ellas `publishDefinition()` falla (no hay aprobadores) |
| companypurchasing | `quorum_*`, `sla_hours_*` | `1` / vacío | reglas de aprobación; se pinnean por solicitud al enviar |
| companypurchasing | `stage_scopes`, `scope_checkpoints`, `pdf_stages`, `quote_states`, `amend_states` | JSON | política de aprobación pinneada por solicitud |
| companypurchasing | `default_currency` / `currency_scale_overrides` | `PYG` / `{}` | dinero exacto; la escala se pinnea al iniciar la compra |
| companypurchasing | `cost_include_discounts/taxes/freight` | `0` | costo por unidad (se pinnea al iniciar la compra) |
| companypurchasing | `notifications_enabled` | `1` | además exige las notificaciones nativas de GLPI activas |
| companypurchasing | `reconcile_cursor`, `notify_cursor` | `0` / último id del ledger | **estado interno**: no editar a mano |
| companysignature | `compose_pdf`, `presentation_timezone` | `1`, `America/Asuncion` | PDF de aprobación; zona de presentación |
| companysignature | `listen_workflow_events` | `1` | si se apaga, sólo la reconciliación materializa evidencia |
| companysignature | `last_seen_history_id` | `0` | **estado interno** (high-watermark): no editar |
| companyworkflow | `sla_check_enabled`, `escalation_enabled`, `notifications_enabled` | `1` | SLA/escalamiento por la Acción automática |
| companyqr | `anonymous_enabled` | `0` | ficha anónima desactivada (requiere decisión explícita) |
| companyqr | `label_*`, `code_prefix_map` | ver código | etiqueta física |
| companyintegrations | `snipe_base_url` | vacío | SI-1/SI-4 sin URL ⇒ sin integración |
| companyintegrations | token Snipe | — | **sólo** por variable de entorno `COMPANYINTEGRATIONS_SNIPEIT_TOKEN` (nunca en BD/Git) |
| companyintegrations | `allow_insecure_http` | `0` | TLS obligatorio |
| companyintegrations | **`si4_enabled`** | **`0`** | worker SI-4 **apagado** en esta baseline |

## 4. Matriz de dependencias real

**Hallazgo:** ningún `setup.php` declara dependencias entre plugins. `check_prerequisites()` devuelve `true` en
todos, y `requirements` sólo declara GLPI y PHP. **GLPI no impone un orden.** Las dependencias son de
**runtime**: el código consulta `class_exists()` / `Plugin::isPluginActive()` y **falla cerrado** si falta la
dependencia. El CI instala en otro orden (`companyportal companypurchasing companyworkflow companyqr
companydashboard companysignature companyintegrations`) y funciona, porque parte de una base vacía.

| Plugin (consumidor) | Requiere (runtime, fail-closed) | Opcional | Install / upgrade |
|---|---|---|---|
| `companyworkflow` | — | — | sin dependencias |
| `companysignature` | `companyworkflow`: escucha `companyworkflow:decision_recorded` / `transitioned` / `approval_invalidated` y lee su ledger por `WorkflowApi`. Sin el motor, la materialización queda **pendiente** (nunca consume el evento). | — | sin dependencias |
| `companypurchasing` | `companyworkflow` **≥ 0.6.0** (`WorkflowGateway`; las bandejas y notificaciones exigen la API de lectura 0.6.0) · `companysignature` (`SignatureGateway`: versiones, evidencia, PDF) | `companyintegrations` ≥ 0.6.0 (`InventoryLinkApi`, sólo lectura; sin él, el detalle no muestra la fase SI-4) | `install()` inicializa `notify_cursor` con el último id del ledger del motor **si está activo**; si no, con 0 (ver orden) |
| `companyintegrations` — SI-1 | — (sólo Snipe-IT por HTTP) | — | sin dependencias |
| `companyintegrations` — worker SI-4 | `companypurchasing` (`PurchasingIntegrationApi` + `RIGHT_INTEGRATION`) · `companyqr` **≥ 0.3.0** (`CompanyQrApi`, `RIGHT_GENERATE` + `RIGHT_PRINT`), verificados **antes** de reclamar | — | sin dependencias |
| `companyqr` | — (hooks `item_*` sobre activos nativos) | — | sin dependencias |
| `companyportal`, `companydashboard` | — | — | esqueletos |

Relación efectiva (→ = "depende de", en runtime):

```
GLPI 11.0.8
 ├── companyworkflow ◄── companysignature
 │        ▲                    ▲
 │        └──── companypurchasing ────┘          (requiere ambos)
 │                  ▲      ┊
 │                  │      ┊ (opcional, lectura: InventoryLinkApi)
 │                  │      ▼
 │            companyintegrations (SI-4) ──► companyqr
 └── companyqr   (independiente)
```

- El diagrama de la consigna (`purchasing ↔ integrations ↔ qr`) se ajusta así:
  - **purchasing ↔ integrations** es real: integrations consume la API de Compras (SI-4) y Compras lee
    opcionalmente la de integrations.
  - **integrations → qr** es **unidireccional**: companyqr no conoce a companyintegrations.
  - **signature no depende de purchasing**: es purchasing la que depende de signature.

### Orden de instalación recomendado

1. `companyworkflow`
2. `companysignature`
3. `companyqr`
4. `companypurchasing`
5. `companyintegrations`
6. (opcional) `companyportal`, `companydashboard` — esqueletos sin lógica

Motivos:
- Se instalan las dependencias antes que sus consumidores.
- Compras toma `notify_cursor` del ledger del motor ya activo. Si se instalara antes que el motor sobre una base
  **con historial**, el cursor arrancaría en 0 y el recorrido de notificaciones podría notificar hechos
  históricos.
- SI-4 necesita Compras y companyqr activos.

En un upgrade se respeta el mismo orden: ver `../operations/phase2-upgrade.md`.

## 5. Estado de SI-4

Código completo (SI4-1/2/3) y **deshabilitado**: `si4_enabled = 0`, sin Acción automática, sin CronTask. Qué falta
para habilitarlo operacionalmente: `../operations/si4-readiness.md`.

Con SI-4 apagado, las líneas **inventariables** no se pueden entregar ni cerrar: la unidad queda en "Inventario
pendiente" porque el gate de entrega exige el outbox `DONE`, y sólo SI-4 lo lleva a `DONE`. Las líneas no
inventariables completan el circuito sin SI-4.

Bloqueantes para habilitarlo, detallados en `../operations/si4-readiness.md` §1:
- B1: no hay interfaz operativa (UI ni comando) para cargar y aprobar mapeos;
- B2: la aprobación de un mapeo no registra actor ni fecha;
- B3: la categoría de la línea es texto libre y el mapeo exige coincidencia exacta;
- B4: el worker no puede ser una Acción automática de GLPI (decisión de programación pendiente).

## 6. Limitaciones conocidas de esta baseline

- **Los selftests son destructivos**: sólo para instancias desechables (CI / test efímero), **nunca** en staging
  con datos ni en producción.
  - `companypurchasing` ejecuta `[MIGRATE]`: uninstall + reinstall, borra todas sus tablas.
  - La limpieza de `companyintegrations` vacía `asset_bridge`, los mapeos, las sagas y `recon`.
  - `companysignature` vacía su cola de reconciliación.
- **Reconciliación SI-1 sin punto de entrada operativo.** `Service\Reconciler` existe y está probado, pero sólo lo
  invoca el selftest: no hay comando, Acción automática ni UI. El gateway `GET /asset/{asset_tag}` sí es operativo.
- **GET sobre rutas POST-only de plugins ⇒ 500 en GLPI 11.0.8** (el router de plugins no traduce
  `MethodNotAllowedException`).
  - `companypurchasing` responde 405 con `MethodGuardController`.
  - Las rutas POST de `companyqr` (`/admin/{action}`, `/scan|public/{token}/report`) y `companyworkflow`
    (`/instance/{id}/{action}`) siguen respondiendo 500 a un GET. No hay mutación, pero queda un log CRITICAL.
    Pendiente como seguimiento.
- **Cron del stack Docker corre como root ⇒ las Acciones automáticas no se ejecutarían allí** (hallazgo de esta
  revisión; **no corregido**: es un cambio de infraestructura fuera de este PR documental).
  - El servicio `cron` de `infra/docker/docker-compose.yml` ejecuta `php /var/www/glpi/front/cron.php` en bucle, con
    `|| true`.
  - La imagen no declara `USER`, así que corre como root.
  - GLPI 11.0.8 se niega a correr `front/cron.php` como root sin `--allow-superuser`: sale con código 1, verificado.
    El `|| true` oculta el error.
  - Corrección propuesta: correr el cron como `www-data`, con `user: www-data` en el servicio o un `runuser`.
  - En staging/producción, el cron del sistema debe correr como el usuario del servidor web. Ver la guía de
    instalación.
- **`companyintegrations` no tiene UI** (ni página de configuración, ni de mapeos, ni menú).
  - La configuración se cambia con el comando nativo `php bin/console config:set
    --context=plugin:companyintegrations <clave> <valor>`.
  - Los mapeos no tienen interfaz operativa: ver §5 y `../operations/si4-readiness.md` §1.
- **Firma digital certificada:** no implementada. companysignature es aprobación electrónica **interna**: identidad
  autenticada + acción explícita + auditoría + hash + timestamp.
- **companyportal / companydashboard:** esqueletos. Las métricas de Compras viven en su propia página
  (`RIGHT_VIEW_METRICS`), no en `companydashboard`.

## 7. Evidencia de la baseline

- CI post-merge de `6c7d994` (run 37362214075, evento `push`), con Static checks e Integration en verde:
  - selftests: workflow 114 · integrations 268 · signature 87 · purchasing 573 · qr OK;
  - los 2 reconcile limpios;
  - E2E HTTP de companyqr y de companypurchasing (51 verificaciones);
  - localization ×5, America/Asuncion, security-key, core-untouched y secret-scan.
- Core de GLPI no versionado ni modificado (`tests/upgrade/verify-core-untouched.sh`).

## 8. Changelogs

- No hay CHANGELOG raíz: cada plugin mantiene el suyo en `plugins/<plugin>/CHANGELOG.md`, con la versión de esta
  baseline como la última entrada.
- Este documento y las guías de `docs/operations/` **no cambian código**.
