# ADR-0020: SI-4 (incremento SI4-1) — identidad remota determinista en Snipe-IT y saga durable por unidad

- **Estado:** Aceptado — SI4-1 implementado y mergeado con aprobación humana: PR #17 (`15cefa5`). El worker SI-4 sigue **deshabilitado** (`si4_enabled = 0`): ver `docs/operations/si4-readiness.md`.
  *(Estado original: "Propuesto (SI4-1 implementado; pendiente de revisión humana)".)*
- **Fecha:** 2026-09-29
- **Decisores:** Producto, TI/activos, seguridad, plataforma
- **Módulo/área:** `plugins/companyintegrations` (0.2.0 → 0.3.0)
- **Complementa:** ADR-0015 (§10 saga, §11 idempotencia saliente), ADR-0019 (outbox + `PurchasingIntegrationApi`),
  gate `../architecture/companypurchasing-native-first-gate.md` §5–§7.

## Contexto
ADR-0015 §11 decía "buscar-primero por `snipe_asset_id` ya persistido o por serial único". Eso **no** cubre el
caso crítico: *Snipe crea el activo → el proceso cae ANTES de guardar `snipe_asset_id` → reintento*. Sin
`snipe_asset_id` local y con serial opcional, el reintento crearía un segundo activo. Antes de decidir se
verificó el **contrato real de Snipe-IT v8.7.2** (tag `v8.7.2`, commit `f3f1dd7`; lectura, sin copiar código AGPL):

| # | Hecho verificado | Evidencia (v8.7.2) |
|---|---|---|
| 1 | `POST /api/v1/hardware` **no** tiene idempotency-key. Éxito ⇒ **HTTP 200** `{"status":"success","payload":{…activo…}}`; error de validación ⇒ **HTTP 200** `{"status":"error","messages":{campo:[…]}}` | `AssetsController@store`; `Exceptions/Handler::render` (ValidationException ⇒ 200) |
| 2 | "No existe" también es **HTTP 200** `status:"error"` (no 404) | `showByTag`, `show`, `showBySerial`; `findOrFail` ⇒ ModelNotFoundException ⇒ 200 |
| 3 | `asset_tag` es obligatorio y `unique_undeleted` — validación de **aplicación** (COUNT antes del INSERT), **sin** índice UNIQUE en la BD | `Asset::$rules`; `ValidationServiceProvider`; migraciones (sólo índice `(deleted_at, asset_tag)`) |
| 4 | `serial` es opcional; su unicidad depende del setting `unique_serial` | `Asset::$rules`; `ValidationServiceProvider` |
| 5 | Un campo personalizado sólo se guarda si está en el fieldset del modelo; uno cifrado se **omite en silencio** sin permiso; el filtro de listado es `TextSearch` (LIKE) | `AssetsController@store`; `index()` |
| 6 | `GET /api/v1/hardware/bytag/{tag}` es exacto sobre activos vivos; con `?deleted=true` incluye soft-deleted y devuelve **siempre** `{total, rows[]}` con `deleted_at` | `showByTag`; `AssetsTransformer` |
| 7 | 401 (sin autenticar), 403 (policy), 429 del middleware `api-throttle` con `Retry-After` (rechaza **antes** del controlador: sin efecto) | `Handler::unauthenticated`; `RouteServiceProvider::configureRateLimiting` |
| 8 | `purchase_cost` es `decimal(20,2)` y Snipe maneja **una** moneda global | migración `increase_purchase_cost_size` |
| 9 | Snipe puede bloquear User-Agent vacío o por patrón (`block_api_user_agents`) | `Middleware/EnforceApiUserAgent` |
| 10 | El transformer devuelve `model.id`, `company.id` y `notes`; `notes` pasa por `Helper::parseEscapedMarkedownInline()` = `Parsedown::line(strip_tags(…))` en safe mode (markdown inline + escape HTML) | `AssetsTransformer::transformAsset`; `Helpers/Helper.php` |

> **Continuación:** SI4-2 ([ADR-0021](ADR-0021-si4-glpi-asset-infocom-bridge.md)) lleva la misma saga de
> `SNIPE_CREATED` a `GLPI_RESOLVED_OR_CREATED → INFOCOM_READY → BRIDGED` (activo GLPI, Infocom y `asset_bridge`).

## Decisión
1. **Identidad remota determinista = `asset_tag`.** Para cada unidad: `asset_tag = <prefijo><UUIDHEX>`, donde
   `UUIDHEX` son los 32 hex (mayúsculas, sin guiones) de `receipt_unit_uuid` y `<prefijo>` sale de la
   configuración (`si4_asset_tag_prefix`, validado `^[A-Z0-9][A-Z0-9-]{0,15}$`). Es **biyectiva**: el tag
   identifica la unidad y viceversa, y se busca con un endpoint **exacto** (hechos 3 y 6).
2. **Buscar primero, siempre, y adoptar sólo lo que es DE ESTA unidad** (`RemoteAssetMatcher`). Cada intento empieza
   con `GET bytag/{tag}?deleted=true`, también cuando la saga ya tiene una intención de creación. Un activo encontrado
   se **vincula** sólo si se cumple **todo** lo siguiente, sin corregir diferencias:
   - exactamente un activo vivo y ninguno soft-deleted con ese tag exacto;
   - `company.id` = compañía mapeada y `model.id` = modelo mapeado;
   - si la unidad trae serial, el remoto es exactamente ese;
   - **marca de procedencia**: `notes` contiene exactamente una marca `receipt_unit_uuid=<uuid>` y es la de esta unidad;
   - y es la **recuperación de un POST propio** (caso B: la saga ya registró una intención de creación).

   Un tag determinista que ya existía **antes** de cualquier POST de esta saga (caso A) no se adopta nunca, aunque
   coincida todo. Cualquier otra combinación ⇒ `MANUAL_REVIEW`: `PREEXISTING`, `DELETED`, `DUPLICATE`,
   `COMPANY_MISMATCH`, `MODEL_MISMATCH`, `SERIAL_MISMATCH` u `OWNERSHIP_MISMATCH`. Nunca se sobrescribe, se recrea ni
   se borra.
   - La marca va **al final** de `notes` (hecho 10). Así ningún `_…_` posterior puede formar un énfasis que la
     atraviese. El resto de los valores de `notes` se reduce a `[A-Za-z0-9.:-]`, y se compara tras
     `strip_tags` + decode de entidades.
   - Si la saga ya registró un `snipe_asset_id` propio y el tag devuelve otro id, o ninguno ⇒ `MANUAL_REVIEW`
     (`id_mismatch` / `created_asset_missing`). Jamás un segundo POST.
3. **El POST es de un solo disparo** (nunca se reintenta a ciegas).
   - `429` ⇒ se puede reintentar (hecho 7).
   - Timeout, error de transporte o `5xx` ⇒ **resultado incierto**: la saga queda en `SNIPE_CREATING` y el outbox
     pasa a `RETRY` con enfriamiento ≥ `si4_uncertain_cooldown_seconds`. El siguiente intento busca primero.
   - Validación sobre `asset_tag`, o un `409` ⇒ "ya existe": buscar y vincular (o `MANUAL_REVIEW`).
4. **Verificación posterior a la creación con las MISMAS exigencias.** Un POST `success` registra el `snipe_asset_id`
   como **no verificado**: la saga sigue en `SNIPE_CREATING`.
   - Sólo pasa a `SNIPE_CREATED` si el GET posterior devuelve ese mismo id cumpliendo todo lo del punto 2 (tag,
     compañía, modelo, serial si lo hay y marca).
   - Cualquier diferencia ⇒ `MANUAL_REVIEW`.
   - GET no disponible ⇒ `RETRY`; el próximo intento verifica igual.
   - Nunca se da por limpio un activo porque sólo coincidan el id o el tag.
5. **Saga durable propia** (`glpi_plugin_companyintegrations_si4_sagas`) y bitácora append-only (`si4_saga_log`).
   - Restricciones: `UNIQUE(receipt_unit_uuid)` (una saga por unidad) y `UNIQUE(snipe_asset_id)` (un activo remoto
     nunca queda ligado a dos unidades).
   - Estados de SI4-1: `PENDING → SNIPE_CREATING → SNIPE_CREATED`, con las excepciones `BLOCKED_CONFIG` y
     `MANUAL_REVIEW`.
   - El `snipe_asset_id` se persiste en cuanto se conoce.
6. **Fencing: época monótona + reloj de la BD.** El dueño de la saga es `sha256(lease_token)` + `lease_until` +
   **época**, copiados del `claimPending()` de Compras (el token en claro no se guarda).
   - La época es `attempts` del claim. Compras la incrementa en **cada** toma y nunca la decrementa, así que es un
     token de fencing monótono: sólo un claim con época **mayor** toma la saga, y un claim viejo nunca.
   - Toda escritura de la saga es un UPDATE condicionado a ese dueño y a `lease_until >= NOW()`.
   - Marcar la intención de creación exige además `lease_until >= NOW() + presupuesto de escritura`. Así ningún
     POST empieza con un lease que podría vencer mientras está en vuelo.
   - Un worker con el lease vencido o re-tomado no puede escribir la saga, y Compras le rechaza el ack/retry/error
     (ADR-0019 §6).
7. **Consumo exclusivo por `PurchasingIntegrationApi`** (`claimPending`, `getHandoff`, `acknowledgeProcessed`,
   `markRetry`, `markError`) a través de un puerto (`HandoffSource`). No hay SQL contra tablas de Compras.
   **`acknowledgeProcessed()` sólo se llama cuando la saga SI-4 completa termina.** En SI4-1 la última etapa
   implementada es `SNIPE_CREATED`: la unidad queda **estacionada**. No se confirma y tampoco se llama
   `markRetry` (consumiría intentos hasta ERROR), así que el lease **vence** y la fila sigue reclamable. Un
   re-claim de una saga estacionada no hace llamadas remotas.
   Por eso el worker de SI4-1 está **deshabilitado por defecto** (`si4_enabled = 0`), no registra Acción
   automática y **no debe programarse en producción** hasta completar SI-4. SI4-2 continuará desde
   `SNIPE_CREATED` dentro del mismo lease.
8. **Mapeos validados, nunca IDs literales:**
   - **Compañía:** la fila **aprobada** de `map_companies` para la entidad de la unidad. Debe ser exactamente una;
     si falta o hay varias ⇒ `BLOCKED_CONFIG`.
   - **Modelo:** `map_models` (nueva), con `category_key` exacto → `snipe_model_id` **aprobado**.
   - **Estado:** `si4_snipe_status_id` de la configuración, verificado contra Snipe en el preflight.
   - Si Snipe rechaza por validación `model_id`/`status_id`/`company_id` ⇒ `BLOCKED_CONFIG`.
9. **Clasificación → outbox:**

   | Situación | Saga | Outbox |
   |---|---|---|
   | Creado / vinculado | `SNIPE_CREATED` | sin confirmar (lease vence) |
   | Mapeo ausente/ambiguo; validación de model/status/company | `BLOCKED_CONFIG` | `markRetry` (`si4_config_retry_seconds`) |
   | 401/403 (también en el preflight, que corre **antes** de reclamar) | sin cambio | `markRetry` y se aborta la corrida |
   | 429, 5xx/timeout en lecturas | sin cambio | `markRetry` con backoff (`Retry-After` si viene) |
   | POST incierto (timeout/transporte/5xx) | `SNIPE_CREATING` | `markRetry` (enfriamiento) |
   | Tag preexistente, duplicado, borrado, compañía/modelo/serial divergente, marca ausente o ajena, verificación posterior fallida, validación desconocida | `MANUAL_REVIEW` | `markError` |
   | Lease perdido (escritura de saga o settle rechazados) | sin cambio | nada (el nuevo dueño continúa) |

   `last_error` se sanea igual que en SI-1 (sin tokens ni credenciales en URL) y el token nunca se registra.
10. **Sin costo en Snipe en SI4-1:** la matriz de ownership lo marca como reflejo opcional y el hecho 8 impide
    representar PYG o escalas > 2 con exactitud. El costo atribuible irá a `Infocom` en un incremento posterior.
11. El cliente de escritura envía un `User-Agent` propio y estable (hecho 9) y exige TLS (`https://`, verificación
    del certificado) igual que SI-1. El bit de Compras que exige el worker se toma de su contrato público
    (`Request::$rightname`, `Request::RIGHT_INTEGRATION`), sin copiar el número. Si companypurchasing no está
    disponible ⇒ fail-closed. Rol mínimo de la cuenta de servicio en Snipe (RBAC por usuario,
    `config/permissions.php` + `Policies/*`): `assets.view`, `assets.create` y `statuslabels.view` (preflight).
    Sin `superuser`.

## Alternativas consideradas
- **Campo personalizado con `receipt_unit_uuid`** — rechazada. Se descarta en silencio si el campo no está en el
  fieldset del modelo o está cifrado, y la búsqueda es LIKE (hecho 5): una mala configuración vuelve a duplicar.
- **Serial** — rechazada: es opcional y su unicidad depende de un setting (hecho 4).
- **`order_number`** — rechazada: no es único.
- **Tag auto-incremental de Snipe** — rechazada: sin clave determinista, un crash después del POST duplica.
- **`markRetry` para estacionar tras `SNIPE_CREATED`** — rechazada: consume `outbox_max_attempts` y termina en ERROR.

## Consecuencias
- (+) El caso "crash después del POST y antes de persistir" se resuelve buscando por un tag que ya se conoce antes
  del POST. N procesamientos de la misma unidad dan **un** activo remoto.
- (−) Los tags de SI-4 no son secuenciales "humanos" (p. ej. `GP2-3F9A…`). La matriz de ownership se aclara así:
  GLPI2 **propone el valor inicial** del tag al crear; después **Snipe es el dueño** (un renombre pasa al alias
  histórico de SI-1 y el QR impreso no se rompe).
- (−) Riesgo residual documentado: un renombre manual del tag entre la creación y la persistencia local, o un
  request colgado en Snipe más allá del enfriamiento, puede dejar un duplicado. La verificación posterior a la
  creación y la reconciliación de SI-1 lo **detectan**; nunca se borra nada automáticamente.
- (−) Mientras SI-4 no esté completo, las unidades estacionadas re-toman el lease al vencer (sube `attempts` en
  Compras). Mitigación: el worker está deshabilitado por defecto y no hay Acción automática.
- SI-1 (lecturas, reconciliación, gateway) no cambia de comportamiento. Una sola corrección de contrato:
  `getHardwareByTag`/`getHardwareById` devuelven `null` también ante `200 + status:"error"` (hecho 2).

## Regla 0 / cumplimiento
Sin cambios en el core de GLPI ni de Snipe-IT; integración sólo por API soportada; sin copiar código AGPL; sin
SQL contra tablas de Compras ni DB-a-DB; token fuera de Git/BD/logs.
