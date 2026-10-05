# SI-4 — readiness operativo (SIN activar)

> **Estado en la baseline `6c7d994`:** código completo (SI4-1 + SI4-2 + SI4-3; ADR-0020/0021/0022) y
> **deshabilitado**.
> - `si4_enabled = 0`.
> - Sin Acción automática y sin CronTask.
>
> Este documento enumera lo que tiene que existir **antes** de habilitarlo.
> - **No habilita nada.**
> - **No crea CronTask.**
> - **No se prueba contra el Snipe-IT de producción.**
>
> Todo lo de acá se lee del código de `plugins/companyintegrations` (y de las APIs públicas que consume).
> La habilitación es una decisión explícita posterior: **staging primero**, con un Snipe-IT de **test**.

## 0. Qué implica tenerlo apagado (comportamiento actual)

- **Líneas NO inventariables:** el circuito de Compras completo funciona (recepción → entrega → cierre) sin SI-4.
- **Líneas inventariables:**
  - al recibir, cada unidad deja su handoff en el outbox de Compras (`PENDING`);
  - la entrega exige outbox `DONE` (`DeliveryRules::gateStatus`), así que la unidad queda en **"Inventario
    pendiente"** (`inventory_pending`) y **no se puede entregar**;
  - por lo tanto la solicitud **no se puede cerrar**;
  - es fail-closed y no hay atajo manual: sólo SI-4 lleva el outbox a `DONE`, con
    `PurchasingIntegrationApi::acknowledgeProcessed()`.
- Mientras SI-4 siga apagado, la operación debe saber que marcar una línea como "inventariable" la deja retenida
  hasta que SI-4 se habilite. Ver también `../testing/phase2-uat.md`.

## 1. Bloqueantes detectados (no resueltos en esta baseline)

Estos puntos **impiden** habilitar SI-4 de forma auditable. Requieren desarrollo o una decisión **fuera** de este PR
documental. No se corrigen acá: no se modifica código.

| # | Bloqueante | Evidencia en el código | Qué haría falta |
|---|---|---|---|
| B1 | **No hay interfaz operativa para los mapeos** (`map_companies`, `map_models`, `map_glpi_assettypes`) | `companyintegrations` no tiene páginas (`front/`), plantillas, rutas de administración ni comandos de mapeo. `RIGHT_MAP` (bit 4) está declarado, pero ningún código de producción lo exige: sólo lo usan los selftests. Hoy sólo el selftest crea mapeos (`CommonDBTM::add`) | Interfaz con `RIGHT_MAP` (página o comando de consola) que cree, apruebe y revoque mapeos. Cargarlos con SQL directo sobre las tablas del plugin **saltearía la ACL y la auditoría**: no se recomienda |
| B2 | **La aprobación de un mapeo no deja rastro de quién ni cuándo** | Las tablas de mapeo tienen `is_approved`, `notes`, `date_creation` y `date_mod`, pero **no** aprobador ni fecha de aprobación | Columnas o eventos de auditoría de aprobación (actor, fecha y hora, valor anterior y nuevo), según CLAUDE.md "Auditoría para cambios sensibles" |
| B3 | **La categoría de la línea es texto libre** | `items.category`: `VARCHAR(190)` libre en Compras. `map_models` y `map_glpi_assettypes` exigen coincidencia **exacta**, que se compara en PHP y distingue mayúsculas | Vocabulario controlado de categorías inventariables (lista de configuración o validación en el formulario), o un procedimiento operativo. Sin eso, las variantes de escritura terminan en `BLOCKED_CONFIG` |
| B4 | **Programación** | `WorkerSession::assertRights()` rechaza el modo cron de GLPI (`glpicronuserrunning`): es a propósito, porque la ACL debe ser la de un usuario real. **No puede** ser una Acción automática de GLPI | Decidir un programador del sistema operativo (systemd timer o cron del SO) que invoque el comando de consola (§4). No se crea en esta fase |

La configuración **sí** tiene una interfaz nativa soportada: `php bin/console config:set
--context=plugin:companyintegrations <clave> <valor>` (GLPI 11.0.8). Es acceso de consola al servidor: no hay ACL de
GLPI, así que el control es el acceso al servidor y queda registrado en el procedimiento de cambio.

## 2. Requisitos (checklist)

### 2.1 Snipe-IT — cuenta de API de mínimo privilegio

- [ ] Instancia Snipe-IT **de test o staging**. El contrato se verificó contra Snipe-IT **v8.7.2** (ADR-0020).
  Otra versión exige revalidar el contrato.
- [ ] `snipe_base_url` con **https** (`allow_insecure_http = 0`).
- [ ] Cuenta de servicio dedicada, **sin superuser**, con exactamente:
  - `assets.view` + `assets.create` (SI-4);
  - `statuslabels.view` (preflight).

  SI-1 sólo usa `assets.view`. En Snipe el token **hereda** los permisos del usuario: no hay scopes por endpoint
  (ADR-0015 §8).
- [ ] Token (personal access token) **sólo** en la variable de entorno `COMPANYINTEGRATIONS_SNIPEIT_TOKEN`:
  - en el entorno del **proceso que ejecuta el worker** (CLI);
  - y del servidor web si se usa el gateway SI-1;
  - nunca en BD, Git, logs ni en este repositorio.

  Rotación según `../security/secrets-management.md`.
- [ ] Snipe no bloquea el User-Agent del cliente (`block_api_user_agents`, ADR-0020 hecho 9).
- [ ] Una **status label** de Snipe para los activos nuevos: su id va en `si4_snipe_status_id`. El preflight
  verifica que existe **antes** de reclamar nada.

### 2.2 GLPI — cuenta técnica y derechos (mínimo privilegio)

- [ ] Usuario GLPI **dedicado** (técnico, no una persona), **activo**, con un perfil propio (no Super-Admin).
- [ ] Bits que `WorkerSession` exige **antes de reclamar** (si falta alguno, la corrida no empieza):
  - `plugin_companyintegrations`: `RIGHT_SI4` (16);
  - `plugin_companypurchasing`: `RIGHT_INTEGRATION` (1024);
  - `plugin_companyqr`: `RIGHT_GENERATE` (2) + `RIGHT_PRINT` (4).
- [ ] Derechos nativos de GLPI que se verifican por unidad. Si faltan, la unidad pasa a `BLOCKED_CONFIG`, sin bypass:
  - READ + CREATE + UPDATE sobre los tipos de activo **mapeados**: `computer`, `monitor`, `networking`,
    `peripheral`, `phone` o `printer`;
  - `infocom` READ + CREATE + UPDATE.
- [ ] El perfil asignado en las **entidades** (recursivo si corresponde) donde se reciben unidades inventariables. El
  worker activa "todas" las entidades **del perfil**: no ve más que eso.
- [ ] Si el usuario tiene más de un perfil, pasar `--profile=<id>`. El worker verifica que ese perfil sea suyo.

### 2.3 Mapeos (todos con `is_approved = 1`; ver bloqueantes B1 a B3)

| Mapeo | Clave | Regla del worker | Si falta |
|---|---|---|---|
| `map_companies` | `glpi_entity_id` de la unidad → `snipe_company_id` | **exactamente uno** aprobado por entidad | `BLOCKED_CONFIG` |
| `map_models` | `category` exacta de la línea → `snipe_model_id` | aprobado | `BLOCKED_CONFIG` |
| `map_glpi_assettypes` | `category` exacta → `glpi_itemtype` (+ `glpi_model_id` opcional) | aprobado; itemtype soportado; el modelo GLPI debe existir | `BLOCKED_CONFIG` |
| estado | `si4_snipe_status_id` | existe en Snipe (preflight) | la corrida aborta (`config`) |

- El mapeo GLPI se **pinnea por saga** en su primer uso. Cambiar el mapeo afecta sólo a las unidades nuevas.
- Si se elimina el modelo pinneado, la saga pasa a `MANUAL_REVIEW`.

### 2.4 Configuración (contexto `plugin:companyintegrations`) y validación

`Si4Config::errors()` valida todo esto **antes** de reclamar. Con cualquier error, la corrida aborta con `config` y
no toca nada.

| Clave | Default | Regla |
|---|---|---|
| `si4_enabled` | `0` | **se deja en 0** en esta baseline |
| `si4_asset_tag_prefix` | `GP2-` | `^[A-Z0-9][A-Z0-9-]{0,15}$`. Define la identidad remota (`asset_tag = prefijo + 32 hex del receipt_unit_uuid`). **No cambiarlo** después de la primera corrida |
| `si4_snipe_status_id` | `0` | > 0 (obligatorio) |
| `si4_lease_seconds` | `900` | ≥ `2·(max_retries+1)·(t+16) + t + 60 + 60 + 30`, con `t` = `timeout_ms` en segundos. Con los defaults (`timeout_ms` 5000, `max_retries` 3) el mínimo es **323 s** |
| `si4_max_units_per_run` | `50` | 1..1000 |
| `si4_retry_base_seconds` / `si4_retry_max_seconds` | `60` / `3600` | base ≥ 1; max ≥ base |
| `si4_config_retry_seconds` / `si4_auth_retry_seconds` | `3600` / `900` | ≥ 60 |
| `si4_uncertain_cooldown_seconds` | `300` | ≥ max(60, 2 × timeout) |
| `si4_worker_id` | `''` | vacío (⇒ `si4:<host>:<pid>`) o `^[A-Za-z0-9._:@-]{1,120}$` |
| `si4_glpi_infocom_currency` | `PYG` | ISO 4217. Debe ser la moneda de las compras: otra moneda ⇒ `MANUAL_REVIEW` antes de crear nada |
| `snipe_base_url` / `allow_insecure_http` / `timeout_ms` / `max_retries` | `''` / `0` / `5000` / `3` | compartidas con SI-1 |

### 2.5 Lease

- El worker reclama cada unidad del outbox de Compras **por lease** (`claimPending`), con `si4_lease_seconds`.
- Cada escritura exige ser dueño del lease, con la época y el `sha256` del token, contra el reloj de la BD:
  - antes de un POST a Snipe, un lease restante ≥ `timeout + 60 s`;
  - para cada escritura en GLPI, ≥ 30 s.
- Si el proceso muere, el lease vence y la próxima corrida retoma la unidad. Siempre **busca primero** el tag
  determinista, así que no duplica.
- Dos workers simultáneos son seguros (fencing probado en `[SI4Q-*]`). Igual se recomienda no solapar corridas
  (§4).

## 3. Primer uso en staging (cuando se autorice; NO en esta fase)

1. Completar §2.1 a §2.5 contra un Snipe-IT de **test**.
2. Configurar con `config:set` (sin `si4_enabled` todavía):
   - `snipe_base_url`;
   - `si4_snipe_status_id`;
   - el resto de las claves, si difieren del default.
3. Primera corrida acotada:
   - `si4_max_units_per_run = 1`;
   - recién entonces `config:set … si4_enabled 1`;
   - una corrida manual (§4).
4. Verificar la unidad en los tres lados:
   - Snipe: activo con el tag determinista;
   - GLPI: activo con `otherserial` = tag, Infocom y `asset_bridge`;
   - companyqr: código ACTIVO con `public_code` = tag;
   - en Compras, el outbox `DONE` y la unidad entregable.
5. Volver a `si4_enabled 0` hasta decidir la programación (§4) y subir `si4_max_units_per_run`.

## 4. Invocación

```bash
# como el usuario del servidor web, con el token en el entorno del proceso
php bin/console plugins:companyintegrations:si4-run --user=<id usuario técnico> [--profile=<id perfil>]
```

| Exit | Significado |
|---|---|
| 0 | corrida completa, o deshabilitado: imprime `SI-4 worker is disabled (si4_enabled = 0): nothing to do.` |
| 1 | corrida abortada: configuración, autenticación, Snipe no disponible o ACL |

- Ese mensaje de "disabled" hace del comando una verificación **sin efectos** de que SI-4 sigue apagado: no abre
  sesión ni contacta a Snipe.
- **Programación (decisión pendiente, B4):** temporizador del sistema operativo, nunca Acción automática de GLPI.
  Se recomienda `flock -n` para no solapar corridas.
  - **Ejemplo ilustrativo, NO instalar:** `flock -n /run/si4.lock php bin/console plugins:companyintegrations:si4-run --user=<id>`.
  - Frecuencia a definir. Cada corrida procesa como mucho `si4_max_units_per_run` unidades.

## 5. Observabilidad

- **Salida de cada corrida:** una línea JSON con las métricas.
  - Generales: `claimed`, `created`, `reconciled`, `bridged`, `qr_ready`, `completed`, `parked`, `blocked_config`,
    `manual_review`, `retry`, `uncertain`, `lease_lost`, `error`, `finalized`.
  - `aborted`: motivo o `null`.
  - Sub-resultados: `snipe_created`, `snipe_reconciled`, `glpi_created`, `glpi_linked`, `qr_created`,
    `qr_existing`.
- **Log:** `files/_log/companyintegrations-si4.log`, en JSON por línea. Pasa por `LogSanitizer`: sin token y sin
  secretos. Lleva `correlation_id` por unidad.
- **Estado durable** (tablas propias, sólo lectura para diagnóstico):
  - `si4_sagas`: estado, `resume_state` y último error;
  - `si4_saga_log`: bitácora de transiciones;
  - `si4_runtime`: cursor del finalizador.
- **Compras (UI):**
  - el detalle de cada unidad muestra la fase SI-4 y el activo GLPI vinculado (`InventoryLinkApi`, READ-ONLY);
  - la página de entrega muestra el gate de cada unidad: "Inventario pendiente", "Integración en curso",
    "Error de integración" o "Lista para entregar".
- **Alertas sugeridas:**
  - `aborted ≠ null`;
  - `blocked_config > 0` (falta un mapeo o un derecho);
  - `manual_review > 0` (requiere decisión humana: nunca se corrige solo);
  - unidades en `RETRY` por mucho tiempo;
  - corridas que no terminan.

## 6. Deshabilitar / rollback

- **Apagar:** `php bin/console config:set --context=plugin:companyintegrations si4_enabled 0` y detener el
  temporizador. La próxima invocación no hace nada.
- **Efecto:**
  - las unidades en curso conservan su saga;
  - su lease vence y el outbox vuelve a quedar pendiente;
  - las inventariables vuelven a "Inventario pendiente";
  - **nada se deshace**: los activos ya creados en Snipe y GLPI, el `asset_bridge` y los códigos QR siguen.
- **Re-habilitar:** retoma cada saga de forma idempotente (busca primero; mapeo pinneado).
- **Prohibido:**
  - borrar sagas, puentes o mapeos con SQL;
  - "resetear" un outbox a mano;
  - rotar o reactivar códigos QR para "destrabar".

  Un `MANUAL_REVIEW` se resuelve con una decisión humana documentada.
- **Rollback de código:** el mismo procedimiento de `phase2-upgrade.md` §8 (código anterior + backup). Nunca
  `plugin:uninstall`: borra las tablas propias.
