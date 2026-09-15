# ADR-0016: Inicialización y verificación de timezones — separar configuración de verificación

- **Estado:** Aceptado
- **Fecha:** 2026-09-15
- **Decisores:** Plataforma / Infraestructura
- **Módulo/área:** Transversal — infraestructura de CI/instalación (localización)

## Contexto

La localización Paraguay (ADR-0005) requiere **soporte de timezones** en GLPI. En
GLPI 11 eso implica tres prerequisitos de base de datos:

1. Las tablas de husos horarios de MariaDB (`mysql.time_zone_*`) **pobladas**
   (se cargan con `mariadb-tzinfo-to-sql`).
2. El usuario de aplicación de GLPI con **`SELECT` sobre `mysql.time_zone_name`**.
3. Ejecutar `database:enable_timezones`, que —una vez cumplidos 1 y 2— migra las
   columnas `datetime`→`timestamp` y habilita el manejo tz.

GLPI decide en tiempo de ejecución si los timezones están disponibles consultando
esas tablas como el usuario de GLPI (equivalente a `areTimezonesAvailable()`).

### Síntoma observado

El job `Integration (stack + smoke)` fallaba de forma **intermitente** en el paso
`verify-localization.sh` → `database:enable_timezones` con "faltan requisitos",
**después** de que la instalación (`install-and-localize.sh`) ya había habilitado
timezones con éxito en la misma corrida.

### Causa raíz (diagnóstico)

Dos problemas, ambos de **diseño del script**, no del negocio ni de los plugins:

1. **Se usaba un comando de _configuración_ como _sonda de estado_.**
   `verify-localization.sh` reejecutaba `database:enable_timezones` para "probar"
   que los timezones estaban habilitados. Ese comando es una **mutación**: su
   resultado depende (a) del prerequisito tz (tablas pobladas + privilegio del
   usuario), (b) de sus propios efectos de migración de columnas y (c) del estado
   transitorio de la BD en ese instante. Reejecutarlo como test acopla la
   verificación a una escritura y la vuelve no determinista.

2. **La carga de tablas tz no era fail-closed.**
   El paso de instalación cargaba las tablas con
   `mariadb-tzinfo-to-sql … 2>/dev/null | mariadb …` **sin `pipefail`** y
   **descartando `stderr`**. Un fallo del generador quedaba **enmascarado**: el
   paso podía "tener éxito" con las tablas vacías o parcialmente cargadas, dejando
   el prerequisito realmente insatisfecho y explicando la intermitencia posterior.

> Evidencia: el diagnóstico se deriva de los scripts (`install-and-localize.sh`,
> `verify-localization.sh`) y del prerequisito que GLPI evalúa en runtime
> (`mysql.time_zone_name` legible por el usuario de GLPI). La reproducción y la
> demostración de estabilidad se hacen en CI con la regresión descrita abajo
> (repetir la verificación N veces sobre la misma instalación).

## Decisión

**Separar claramente configuración de verificación**, y no ocultar errores con
`|| true`:

- **Configuración (mutación), una sola vez, fail-closed** — en
  `infra/docker/glpi-config/install-and-localize.sh`:
  1. Esperar a que la BD acepte conexiones.
  2. Cargar las tablas tz con exit-codes encadenados (portable, sin `pipefail`
     de `dash`) y **verificar** que `mysql.time_zone_name` quedó poblada
     (`COUNT(*) > 0`), fail-closed.
  3. Otorgar `SELECT` al usuario de GLPI.
  4. Ejecutar `database:enable_timezones` (paso de habilitación).
  5. **Verificar** que el usuario de GLPI resuelve una zona **nombrada**
     (`CONVERT_TZ('…','UTC','America/Asuncion')` no nulo), fail-closed.

- **Verificación (sólo lectura, idempotente)** — en
  `tests/localization/verify-localization.sh`:
  1. Idioma por defecto ES (login anónimo).
  2. Zona horaria del servidor PHP = `America/Asuncion`.
  3. `mysql.time_zone_name` poblada y **accesible por el usuario de GLPI**
     (`COUNT(*) > 0`).
  4. Timezones **usables**: el usuario de GLPI resuelve `America/Asuncion` vía
     `CONVERT_TZ` (equivalente de sólo lectura al prerequisito de GLPI).
  **Ya no** se reejecuta `database:enable_timezones`.

- **Regresión en CI**: tras la verificación, ejecutarla **N veces** sobre la
  misma instalación para demostrar que es estable/idempotente como verificación.

`database:enable_timezones` se usa **sólo donde corresponde** (configurar/habilitar),
no como prueba repetitiva de estado.

## Alternativas consideradas

- **A — `|| true` sobre la reejecución de `database:enable_timezones`.**
  Rechazada: oculta fallos reales y viola la premisa fail-closed. Explícitamente
  descartada por el pedido.
- **B — Reintentar `database:enable_timezones` hasta que pase.**
  Rechazada: sigue tratando una mutación como sonda; enmascara la causa raíz y
  deja migraciones de columnas ejecutándose en cada verificación.
- **C — (elegida) Configurar una vez fail-closed + verificar estado read-only +
  regresión de idempotencia.** Separa responsabilidades, elimina la mutación de
  la verificación y prueba la estabilidad de forma reproducible.
- **D — Leer un flag persistente "timezones habilitado" de GLPI.**
  Rechazada: GLPI resuelve la disponibilidad en runtime consultando las tablas tz
  (no hay un flag estable garantizado por versión); la sonda read-only elegida
  (`CONVERT_TZ` + `COUNT(*)` como usuario de GLPI) refleja exactamente ese
  prerequisito sin acoplarse a internos.

(Orden nativo-first: aquí no hay lógica de negocio; es tooling de instalación/CI.
Se siguen usando **sólo comandos oficiales** de GLPI y SQL sobre el esquema de
sistema `mysql`, sin tocar tablas core de negocio ni el core de GLPI.)

## Consecuencias

**Positivas**
- CI determinista: la verificación de localización deja de ser intermitente.
- Fallos reales siguen siendo `nonzero` (fail-closed real, sin `|| true`).
- Diagnóstico más claro: la instalación aborta apenas un prerequisito no se cumple.
- La regresión impide reintroducir el anti-patrón (mutación como sonda).

**Negativas / costos**
- Los scripts son algo más largos (helpers SQL + verificaciones explícitas).
- La verificación depende de poder leer `mysql.time_zone_name` como usuario de
  GLPI (que es, precisamente, el prerequisito que GLPI exige).

Impacto en actualizabilidad/seguridad/auditoría: nulo sobre el core. No cambia
plugins ni lógica de negocio. Sin secretos nuevos (se usan variables de entorno
ya presentes del stack).

## Cumplimiento de la Regla 0

No se modifica el **core** de GLPI. Sólo se cambian scripts de infraestructura
(`infra/docker/glpi-config/install-and-localize.sh`), de prueba
(`tests/localization/verify-localization.sh`) y el workflow de CI. El único SQL
directo es sobre el **esquema de sistema `mysql`** (tablas de husos horarios y un
`GRANT`), que es la vía soportada para habilitar timezones; **no** hay SQL contra
tablas core de negocio de GLPI ni se saltan reglas de negocio.
