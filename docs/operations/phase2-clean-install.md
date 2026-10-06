# Instalación limpia — baseline de Fase 2

> Guía única y reproducible para una instalación **nueva** (test / staging) de GLPI 11.0.8 + los plugins de la
> baseline `main` `74dc2c7` (ver `../releases/phase2-baseline.md`).
> **No ejecutar en producción sin pasar antes por staging** (`deployment.md`).
> **Sin secretos en este documento:** todo valor sensible va por `.env` (DEV/test) o por el gestor de secretos
> (staging/producción). Ver `../security/secrets-management.md`.

Los comandos de la columna Docker son los mismos que ejecuta el CI (`.github/workflows/ci.yml`). Para un servidor
sin Docker, el equivalente es el mismo `php bin/console …` ejecutado **como el usuario del servidor web**
(`www-data`), desde el directorio de GLPI.

## 0. Requisitos

- GLPI **11.0.8 oficial**. La imagen `infra/docker/Dockerfile.glpi` descarga el tarball del release.
  - En staging/producción, pasar `GLPI_SHA256` para verificar la integridad (`docker-image-pinning.md`).
- PHP ≥ 8.2 con las extensiones de GLPI 11, **incluida `bcmath`** (obligatoria). La imagen instala
  `bcmath mysqli gd intl zip bz2 exif opcache ldap`.
- MariaDB 11.4: base `utf8mb4`, usuario dedicado y acceso root sólo para cargar las zonas horarias.
- Plugins: `plugins/<plugin>` del commit de la baseline, copiados o montados en `glpi/plugins/`.
  - Nunca se copia nada dentro del core.
  - Nunca se edita `vendor/`.

## 1. Clave de seguridad (`glpicrypt.key`) — antes de instalar

GLPI 11 usa `config/glpicrypt.key` como `kernel.secret`. Una clave con `%` rompe toda la consola: es un bug
upstream (ADR-0017).

```bash
bash infra/docker/glpi-config/provision-security-key.sh            # Docker
```

Fuera de Docker: 32 bytes criptográficamente aleatorios **sin el byte `%`**, en `config/glpicrypt.key`, con
propietario `www-data` y modo `0640`. Nunca una clave fija ni impresa en logs.

## 2. Instalar GLPI (es_ES) + zonas horarias

```bash
bash infra/docker/glpi-config/install-and-localize.sh               # Docker (lo mismo que el CI)
```

El script es fail-closed y equivale a:

1. `php bin/console database:install --db-host=… --db-name=… --db-user=… --db-password=… --default-language=es_ES
   --no-interaction` (alias `db:install`), y después borrar `install/install.php`.
2. Cargar las zonas horarias en MariaDB (`mariadb-tzinfo-to-sql /usr/share/zoneinfo | mariadb … mysql`) y verificar
   que `mysql.time_zone_name` no esté vacía.
3. `GRANT SELECT ON mysql.time_zone_name TO '<usuario glpi>'@'%'`.
4. `php bin/console database:enable_timezones --no-interaction` (**una sola vez**: es configuración, no sonda;
   ADR-0016).
5. Verificar como usuario GLPI: `CONVERT_TZ('2020-06-01 12:00:00','UTC','America/Asuncion')` ≠ `NULL`.

Pasos **administrativos** (procedimiento vigente en `localization.md`), en **Configuración → General**:
- zona horaria por defecto `America/Asuncion`;
- formato de números para PYG (0 decimales).

La zona del servidor PHP (`date.timezone=America/Asuncion`) ya viene en la imagen.

**Cuentas por defecto:** GLPI crea `glpi`, `tech`, `normal` y `post-only` con contraseñas conocidas. En
staging/producción deben cambiarse o desactivarse antes de exponer la instancia (`../security/security-baseline.md`).

## 3. Plugins — en el orden real de dependencias

GLPI **no** impone el orden: ningún plugin declara dependencias en `setup.php`. Este orden sale de las
dependencias de runtime del código (ver `../releases/phase2-baseline.md` §4).

```bash
cd infra/docker
for p in companyworkflow companysignature companyqr companypurchasing companyintegrations; do
  docker compose exec -T -u www-data glpi php bin/console plugin:install --username=<admin> "$p"
  docker compose exec -T -u www-data glpi php bin/console plugin:activate "$p"
done
# Opcionales (esqueletos sin lógica de negocio; no aportan funcionalidad):
#   companyportal companydashboard
docker compose exec -T -u www-data glpi php bin/console plugin:list
```

- `--username` es el administrador con el que corre el `install()`. En esta instalación inicial, cada plugin crea su
  derecho en todos los perfiles (valor 0) y otorga **todos** los bits al perfil Super-Admin (id 4).
- `plugin:list` debe mostrar los 5 plugins **activados**, con las versiones de la baseline: workflow 0.6.1,
  signature 0.5.1, qr 0.3.0, purchasing 0.5.0, integrations 0.6.0.
- **Por qué este orden:**
  - Compras inicializa `notify_cursor` con el ledger del motor activo.
  - Compras exige el motor (≥ 0.6.0) y Firma.
  - El worker SI-4 de integrations exige Compras y companyqr (≥ 0.3.0).

## 4. Cron — Acciones automáticas

Las tres Acciones automáticas de la baseline están en modo **CLI** (`mode = 2`):

| Acción | Plugin | Frecuencia | Qué hace |
|---|---|---|---|
| `escalation` | companyworkflow | 1 h | SLA vencido + escalamiento (nunca aprueba solo) |
| `reconcile` | companysignature | 5 min | materializa evidencia pendiente (harvest + worker) |
| `reconcileprojection` | companypurchasing | 15 min | proyección `domain_state`, saga física y recorrido de notificaciones |

Requieren el cron del sistema **como el usuario del servidor web**:

```
* * * * *  www-data  php /var/www/glpi/front/cron.php
```

> ⚠️ GLPI 11.0.8 **rechaza** `front/cron.php` como root: sale con código 1, salvo con `--allow-superuser`.
> El servicio `cron` del `docker-compose.yml` actual corre como root y su bucle usa `|| true`. Ver la limitación
> en `../releases/phase2-baseline.md` §6: verificarlo en staging antes de confiar en las Acciones automáticas.

**No** existe ni debe crearse una Acción automática para SI-4 (`si4_enabled = 0`; ver `si4-readiness.md`).

Verificación: en **Configuración → Acciones automáticas**,
- las tres aparecen y se ejecutan;
- su última ejecución avanza.

## 5. Derechos — verificación y perfiles mínimos

1. En **Administración → Perfiles → Super-Admin**, comprobar que tiene todos los bits de `plugin_companyworkflow`,
   `plugin_companysignature`, `plugin_companyqr`, `plugin_companypurchasing` y `plugin_companyintegrations`.
   - Los bits completos se otorgan **sólo en la instalación inicial**. Si después un administrador recorta Super-Admin,
     un upgrade de workflow (≥ 0.6.1), signature (≥ 0.5.1) o qr **preserva** ese recorte. Compras 0.5.0 e
     Integraciones 0.6.0 todavía no: ver `../releases/phase2-baseline.md` §6 y `phase2-upgrade.md` §5.
2. Crear o ajustar perfiles de **mínimo privilegio**, nunca por nombre de persona. Los bits están en
   `../releases/phase2-baseline.md` §2.

| Perfil funcional | Compras (`plugin_companypurchasing`) | Motor (`plugin_companyworkflow`) | Otros |
|---|---|---|---|
| Solicitante | READ + CREATE_REQUEST + VIEW_OWN + EDIT_DRAFT | READ | — |
| Aprobador (jefe / finanzas) | READ (+ VIEW_ENTITY si debe ver el historial general) | READ + RIGHT_ACT | `plugin_companysignature` RIGHT_RECORD |
| Compras | READ + MANAGE_PURCHASING (+ VIEW_ENTITY opcional) | READ + RIGHT_ACT (aprueba su etapa) | `plugin_companysignature` RIGHT_RECORD |
| Receptor | READ + RECEIVE | READ | — |
| Entregador | READ + DELIVER | READ | — |
| Métricas | READ + VIEW_METRICS | — | — |
| Configuración de Compras | READ + MANAGE_CONFIG | — | — |
| Usuario técnico SI-4 | INTEGRATION (sólo cuando se habilite SI-4) | — | ver `si4-readiness.md` |

- Los aprobadores efectivos los decide el **motor**, por los grupos configurados en Compras, el quórum y las
  delegaciones.
- Sin `VIEW_*`, un perfil operativo sólo abre la solicitud que hoy puede accionar (lectura contextual, ADR-0023).

## 6. Configuración mínima

| Dónde | Qué | Cómo |
|---|---|---|
| GLPI | notificaciones activas + correo saliente | Configuración → Notificaciones (sin ellas Compras no encola correos) |
| Compras | `approver_group_area_head`, `_purchasing`, `_finance` (obligatorios), `quorum_*`, `sla_hours_*` | `/plugins/companypurchasing/config` (MANAGE_CONFIG) → Guardar |
| Compras | publicar la definición del workflow | misma página → **Publicar** (crea una versión nueva; las instancias existentes conservan la suya) |
| Firma | `compose_pdf = 1`, `presentation_timezone = America/Asuncion` | defaults (sin cambios) |
| companyqr | etiqueta (`label_*`), prefijos; **`anonymous_enabled` sigue en 0** salvo decisión explícita | `config:set --context=plugin:companyqr` |
| companyintegrations | `snipe_base_url` vacío (sin integración) **o** apuntando a un Snipe-IT de **test**; token sólo por `COMPANYINTEGRATIONS_SNIPEIT_TOKEN` | `config:set --context=plugin:companyintegrations` + entorno del servidor |
| companyintegrations | **`si4_enabled = 0`** (no cambiar) | — |

- Sólo `companypurchasing` tiene página de configuración.
- `companyworkflow`, `companysignature`, `companyqr` y `companyintegrations` se configuran con el comando nativo
  `php bin/console config:set --context=plugin:<plugin> <clave> <valor>`. Las claves y defaults están en
  `../releases/phase2-baseline.md` §3.
- Verificación sin efectos de que SI-4 está apagado: `php bin/console plugins:companyintegrations:si4-run` ⇒
  `SI-4 worker is disabled (si4_enabled = 0): nothing to do.`

## 7. Reconciliación (segura con datos reales)

```bash
docker compose exec -T -u www-data glpi php bin/console plugins:companysignature:reconcile --no-interaction
docker compose exec -T -u www-data glpi php bin/console plugins:companypurchasing:reconcile --no-interaction
```

Esperado en una instalación limpia:
- `reconcile: encolados=0 … errores=0`
- `reconcile: revisadas=0 … errores=0`

Ambos son idempotentes. Si `companypurchasing:reconcile` lista anomalías, sale con código ≠ 0 y **las reporta sin
mutar**.

## 8. Selftests y pruebas — SÓLO en una instancia desechable

> ⛔ **Los selftests son destructivos.** `companypurchasing` hace uninstall + reinstall (`[MIGRATE]`). La limpieza
> de `companyintegrations` vacía el puente, los mapeos y las sagas. `companysignature` vacía su cola. Correrlos
> **sólo** en una instancia nueva y desechable (como el CI), **antes** de cargar datos reales, y luego descartarla
> o reinstalarla limpia. **Nunca** en staging con datos ni en producción.

```bash
for p in companyworkflow companyintegrations companysignature companypurchasing; do
  docker compose exec -T -u www-data glpi php bin/console "plugins:${p}:selftest" --no-interaction
done
docker compose exec -T -u www-data glpi php bin/console plugins:companyqr:selftest --out=/tmp/companyqr-label.pdf
bash tests/security/verify-glpi-security-key.sh
bash tests/smoke/run-smoke.sh
bash tests/e2e/companyqr-http.sh
bash tests/e2e/companypurchasing-http.sh
bash tests/localization/verify-localization.sh          # sólo lectura; el CI lo repite 5 veces
```

En la baseline, cada selftest termina con `SELFTEST: todas las comprobaciones pasaron.`
- workflow 114 ✓
- integrations 268 ✓
- signature 87 ✓
- purchasing 573 ✓
- qr OK

Los E2E HTTP también crean datos de prueba (sólo test).

## 9. Verificación final (Regla 0)

```bash
bash tests/upgrade/verify-core-untouched.sh     # el core no está versionado ni modificado
bash tests/security/secret-scan.sh
```
