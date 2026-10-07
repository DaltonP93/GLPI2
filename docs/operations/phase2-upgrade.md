# Upgrade de una instalación existente — baseline de Fase 2

> Lleva una instalación **existente** de GLPI 11.0.x, con versiones anteriores de los plugins propios, a la baseline
> `main` `5ffcd32` (ver `../releases/phase2-baseline.md`).
> **Fail-closed:** si un paso falla, no se sigue; se aplica el rollback (§8).
> **Staging primero**, sobre una copia restaurada de producción (`deployment.md`). Nunca directo en producción.
> **Nunca se toca el core de GLPI ni `vendor/`.** Sólo cambia el código de `plugins/<plugin>`.
> **Sin secretos en este documento.**

Este procedimiento cubre **sólo los plugins**. Actualizar el core de GLPI (otra versión 11.0.x) es otro procedimiento
(`glpi-upgrade-test.md`). No combinar ambos en la misma ventana: si algo falla, no se sabría cuál de los dos lo causó.

## 0. Cómo actualiza GLPI 11.0.8 un plugin (verificado en el código de GLPI)

- Al arrancar, GLPI compara la versión de `setup.php` con la registrada en `glpi_plugins`. Si difiere, marca el
  plugin **"Para actualizar"** (`NOTUPDATED`) y lo **desactiva** (`Plugin::checkPluginState`). Desde ese momento sus
  hooks, menús y Acciones automáticas dejan de correr.
- `plugin:install <plugin>` vuelve a ejecutar `plugin_<plugin>_install()` (el camino de upgrade). El plugin queda
  "Instalado / no activado".
  - Un plugin **sin** cambio de versión sigue activado. `plugin:install` lo rechaza ("already installed") salvo con
    `--force`. No hace falta: GLPI sólo relanza `install()` cuando cambia la versión.
- `plugin:activate <plugin>` lo vuelve a activar.
- **Sólo los plugins activados registran su autoloader.** Mientras el motor no esté activado, Compras no ve
  `WorkflowApi`. Por eso el orden de §4 es **obligatorio**.
- `plugin:install` falla si la ejecución de plugins está suspendida (`plugin:suspend_execution`). **No** usar ese
  comando en este procedimiento: sirve para upgrades del core.
- `install()` de los 5 plugins es idempotente y seguro en upgrade. Crea sólo las tablas, columnas e índices que
  faltan, siembra sólo la configuración ausente, no duplica Acciones automáticas y no toca los derechos de los
  perfiles.
  - Lo cubren los selftests `[UPGRADE]` / `[UPGRADE-P2D3]` / `[UPGRADE-P2D4]` / `[SI4Q-*]`.
  - El PR #20 hizo un upgrade real desde `9382ef2`, con datos, comparando huellas sha256 de todas las tablas.

### Contrato de derechos (instalación y upgrade)

- **Primera instalación:** Super-Admin (id 4) recibe los derechos iniciales (todos los bits de cada plugin).
- **Upgrade:** ningún plugin re-otorga automáticamente derechos que un administrador haya retirado. Los derechos de
  todos los perfiles, Super-Admin incluido, quedan exactamente como estaban.
- Vale para los 5 plugins de la baseline: `companyworkflow` ≥ 0.6.1, `companysignature` ≥ 0.5.1,
  `companypurchasing` ≥ 0.5.1, `companyintegrations` ≥ 0.6.1 y `companyqr` ≥ 0.3.0 (ver
  `../releases/phase2-baseline.md` §2).
- Un bit **nuevo** que traiga una versión futura tampoco se otorga en el upgrade: si hace falta, un administrador lo da
  a mano después (§5.3).
- Ojo con el sentido: el contrato rige para el código **nuevo**, que es el que corre `install()` durante el upgrade.
  Por eso al pasar a esta baseline los derechos ya se preservan, aunque se venga de versiones que no lo hacían.
- **Bits que Super-Admin no recibe según la versión de partida.** Super-Admin tiene desde Compras 0.2.0 todos los bits
  de Compras salvo `INTEGRATION`, y desde Integraciones 0.3.0 todos los de Integraciones (verificado en el `hook.php`
  de cada versión). Desde `9382ef2` (Compras 0.4.0, Integraciones 0.5.0) **no falta ninguno**. Sólo si se viene de
  versiones más viejas, un upgrade ya no los suma:

  | Si se viene de… | Bit que Super-Admin no recibe | ¿Hace falta otorgarlo? |
  |---|---|---|
  | `companypurchasing` < 0.4.0 | `INTEGRATION` (1024) | sólo a la cuenta técnica de SI-4, cuando se habilite (`si4-readiness.md`) |
  | `companyintegrations` < 0.3.0 | `RIGHT_SI4` (16) | ídem |

  Los perfiles propios (entrega, métricas, etc.) nunca recibieron bits automáticamente: se configuran a mano como en
  la instalación limpia.

### Qué cambia desde el `main` anterior (`9382ef2`)

| Plugin | Versión en `9382ef2` | Baseline `5ffcd32` | ¿GLPI lo marca "Para actualizar"? |
|---|---|---|---|
| `companyworkflow` | 0.5.0 | **0.6.1** | sí |
| `companysignature` | 0.5.0 | **0.5.1** | sí |
| `companyqr` | 0.3.0 | 0.3.0 | no (sigue activado) |
| `companypurchasing` | 0.4.0 | **0.5.1** | sí |
| `companyintegrations` | 0.5.0 | **0.6.1** | sí |

Desde `6c7d994` (el `main` previo a los PR #22 y #24) cambian `companyworkflow` 0.6.0 → 0.6.1, `companysignature`
0.5.0 → 0.5.1, `companypurchasing` 0.5.0 → 0.5.1 y `companyintegrations` 0.6.0 → 0.6.1. Son parches sin cambios de
esquema ni de lógica de dominio: sólo cambia cómo `install()` trata los derechos de Super-Admin. Los cuatro aparecen
"Para actualizar" y siguen el mismo procedimiento.

Desde una base más vieja, cambian más plugins. El procedimiento es el mismo: se actualiza **cada plugin que
`plugin:list` muestre "Para actualizar"**, en el orden de §4.

## 1. Pre-chequeos (antes de la ventana)

1. Leer `../releases/phase2-baseline.md` §6 (limitaciones conocidas).
2. Registrar el estado actual:
   - `php bin/console plugin:list` → versiones y estados de partida (guardar la salida);
   - versión de GLPI 11.0.x. Los plugins declaran `11.0 ≤ GLPI < 12.0`; fuera de ese rango, **no seguir**;
   - derechos del perfil Super-Admin y de los perfiles propios para cada plugin (**Administración → Perfiles**).
     Hace falta para comprobar en §5.3 que el upgrade no los cambió.
3. PHP con `bcmath`.
4. Reconciliaciones **antes** del upgrade. Son seguras con datos reales; guardar la salida:

   ```bash
   php bin/console plugins:companysignature:reconcile --no-interaction
   php bin/console plugins:companypurchasing:reconcile --no-interaction
   ```

   Toda anomalía previa (`sin_instancia`, `integridad_pendiente`, `anomalias_recepcion`, `definicion_anterior`…) se
   documenta **antes**, para no atribuirla al upgrade.
5. Confirmar que SI-4 sigue apagado. Es una lectura sin efectos: con `si4_enabled = 0` el comando termina antes de
   abrir sesión o contactar a Snipe-IT.

   ```bash
   php bin/console plugins:companyintegrations:si4-run
   # esperado: "SI-4 worker is disabled (si4_enabled = 0): nothing to do."  (exit 0)
   ```

6. Tener a mano el SHA exacto del código **anterior** de los plugins (para el rollback) y el de la baseline.

## 2. Quiescencia + backup

En este orden, para que el backup sea un punto consistente y no entre tráfico durante el upgrade:

```bash
php bin/console maintenance:enable          # la web muestra "mantenimiento"; front/cron.php sale sin ejecutar
# Docker: además detener el servicio cron →  docker compose stop cron
infra/backup/backup.sh                       # DB (--single-transaction) + files/ + config/ ; ver backup-restore.md
```

- Verificar que el backup existe y no está vacío (`ls -lh _backups/<timestamp>/`), y anotar su ruta.
  **Sin backup verificado, no seguir.**
- SI-4 no se programa nunca, así que no hay corridas del worker que esperar. Confirmar que nadie lo esté ejecutando a
  mano.
- Durante el mantenimiento, un administrador puede entrar a la web con `?skipMaintenance=1` para validar (§6).

## 3. Upgrade de código (sólo plugins)

- Reemplazar `plugins/<plugin>` por el código de la baseline: `git checkout 5ffcd32 -- plugins/` en el despliegue, o
  la imagen/artefacto equivalente.
  - No copiar nada dentro del core.
  - No tocar `vendor/` de GLPI.
  - No dejar archivos de versiones anteriores mezclados.
- Propietario de los archivos: el usuario del servidor web.
- Verificar:

  ```bash
  php bin/console plugin:list
  ```

  Los plugins con versión nueva deben aparecer **"Para actualizar"**, y los demás **activados**.

## 4. `plugin:install` + `plugin:activate`, en orden de dependencias

**Orden obligatorio:** `companyworkflow` → `companysignature` → `companyqr` → `companypurchasing` →
`companyintegrations`. Se procesan **sólo** los que estén "Para actualizar".

```bash
for p in companyworkflow companysignature companyqr companypurchasing companyintegrations; do
  # sólo si plugin:list lo muestra "Para actualizar"
  php bin/console plugin:install --username=<admin> "$p" || { echo "FALLO install $p → rollback (§8)"; exit 1; }
  php bin/console plugin:activate "$p"                  || { echo "FALLO activate $p → rollback (§8)"; exit 1; }
done
php bin/console plugin:list
```

> ⛔ **`companyworkflow` debe quedar instalado y ACTIVADO antes del `plugin:install` de `companypurchasing`.**
> Al pasar a 0.5.0, Compras siembra `notify_cursor` con el último id del ledger del motor. Si el motor no está activo
> en ese momento, el cursor arranca en **0**: el recorrido de notificaciones encontraría los hechos históricos y
> podría **notificar la historia**.
> - Cada comando corre en un proceso nuevo, así que el `plugin:activate` del motor ya está vigente para el siguiente.
> - Un fallo de `install` o de `activate` **detiene** el procedimiento: nunca se sigue con el resto.

En la consola (Docker), los mismos comandos van con el prefijo `docker compose exec -T -u www-data glpi`. Fuera de
Docker se ejecutan **como el usuario del servidor web**, desde el directorio de GLPI.

## 5. Validación (todavía en mantenimiento)

1. `plugin:list` debe mostrar los 5 plugins **activados**, con las versiones de la baseline:
   - workflow 0.6.1
   - signature 0.5.1
   - qr 0.3.0
   - purchasing 0.5.1
   - integrations 0.6.1
2. **Configuración → Acciones automáticas**: siguen existiendo `escalation`, `reconcile` y `reconcileprojection`,
   sin duplicados.
   - **No** existe ninguna para SI-4.
   - El modo y la frecuencia que haya ajustado un administrador se conservan.
3. **Administración → Perfiles**:
   - los derechos de los perfiles que ajustó un administrador se conservan;
   - todos los perfiles, Super-Admin (id 4) incluido, tienen **exactamente** los mismos derechos registrados en §1.2.
     Un upgrade no re-otorga derechos retirados ni agrega bits nuevos. Si alguno cambió ⇒ rollback;
   - sólo si se viene de versiones anteriores a `9382ef2`, revisar la tabla de bits de §0.
4. **Notificaciones (sólo en un upgrade de Compras desde < 0.5.0)**, en **Administración → Cola de notificaciones**:
   - **no** debe aparecer una ráfaga de notificaciones de solicitudes históricas;
   - si aparece, el orden de §4 no se respetó ⇒ rollback.
5. SI-4 sigue apagado: `plugins:companyintegrations:si4-run` ⇒ "disabled" (como en §1.5).

## 6. Reconciliación y pasos funcionales post-upgrade

```bash
php bin/console plugins:companysignature:reconcile --no-interaction
php bin/console plugins:companypurchasing:reconcile --no-interaction
```

- Comparar con la salida de §1.4. No debe aparecer **ninguna anomalía nueva**.
- `companypurchasing:reconcile` termina con código ≠ 0 si hay `sin_instancia`, `integridad_pendiente`,
  `recepcion_pendiente`, `anomalias_recepcion` o `legacy_blocked`. Lo reporta **sin mutar**.
  - `recepcion_pendiente` **no** es un fallo del upgrade. Lo converge la Acción automática `reconcileprojection`
    cuando vuelva el cron (§7). Re-ejecutar el reconcile después.
  - `definicion_anterior` (`legacy`) es esperable: las instancias en curso **conservan** su versión de la definición
    y no se migran (ADR-0023 §1).
  - `legacy_blocked`: instancias aprobadas sin fase de compra, o recibidas sin fase de entrega. Requieren una
    decisión humana. Comparar con la lista de §1.4; si apareció alguna nueva, investigar antes de abrir.
- **Upgrade de Compras desde < 0.5.0: publicar la definición nueva.**
  - Dónde: `/plugins/companypurchasing/config` (MANAGE_CONFIG) → **Publicar**. Mientras siga en mantenimiento, entrar
    con `?skipMaintenance=1`.
  - Crea una **versión nueva** con las fases de entrega y cierre.
  - Sólo las solicitudes **nuevas** la usan. Las existentes conservan la suya.

**Selftests: NO.** Son destructivos (`../releases/phase2-baseline.md` §6). La regresión con selftests y E2E se corre
sobre una **copia desechable** restaurada del mismo backup, nunca sobre esta instancia:

```bash
# en una instancia DESECHABLE, restaurada del backup de §2 y actualizada con este mismo procedimiento
for p in companyworkflow companyintegrations companysignature companypurchasing; do
  php bin/console "plugins:${p}:selftest" --no-interaction
done
php bin/console plugins:companyqr:selftest --out=/tmp/companyqr-label.pdf
```

## 7. Reabrir

```bash
# Docker: docker compose start cron   (ver la limitación del cron como root en phase2-baseline.md §6)
php bin/console maintenance:disable
```

- Verificar en **Configuración → Acciones automáticas** que la última ejecución de las tres avanza.
- Re-ejecutar `plugins:companypurchasing:reconcile`: los `recepcion_pendiente` deben haber convergido.
- Smoke: `tests/smoke/run-smoke.sh` (sólo lectura).

## 8. Rollback (si cualquier paso de §3–§7 falla)

**Nunca** `plugin:uninstall` como rollback: borra las tablas propias, es decir, los datos. El rollback es **código
anterior + backup de §2**:

1. Mantener o volver a poner `maintenance:enable`, y detener el cron.
2. Volver a desplegar el código **anterior** de `plugins/` (el SHA anotado en §1.6).
3. Restaurar el backup (`backup-restore.md`). Pide confirmación explícita:

   ```bash
   infra/backup/restore.sh _backups/<timestamp>
   ```

   Restaura la base (incluida `glpi_plugins`, con las versiones anteriores), `files/` y `config/`.
4. `plugin:list`: versiones **anteriores** y todos **activados**.
   - Si alguno figura "Para actualizar", el código desplegado no coincide con el backup: corregir el código, **no**
     la base.
5. Reconciliaciones (como en §1.4). La salida debe coincidir con la previa.
6. El backup se tomó en mantenimiento, así que la base restaurada también lo está. Revisar y recién entonces
   `maintenance:disable`, y volver a levantar el cron.
7. Registrar la causa del fallo antes de reintentar.

## 9. Cierre

- `tests/upgrade/verify-core-untouched.sh` → el core sigue sin cambios.
- Guardar junto con el backup:
  - salida de `plugin:list` antes y después;
  - salidas de los reconcile antes y después;
  - SHA desplegado.
- **No** activar SI-4 como parte de un upgrade. Su habilitación es una decisión separada:
  `si4-readiness.md`.
