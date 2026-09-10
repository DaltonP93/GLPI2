# Compatibilidad de versiones de GLPI (semántica de `min`/`max`)

Cada plugin declara en `setup.php` un rango de versiones soportadas:

```php
'requirements' => [
    'glpi' => [
        'min' => '11.0',   // versión mínima soportada
        'max' => '12.0',   // LÍMITE SUPERIOR EXCLUYENTE
    ],
],
```

## Qué significa `max = '12.0'`

`max = '12.0'` significa **compatibilidad con GLPI `>= 11.0` y `< 12.0`**, es decir:

- ✅ Soportado y probado: **GLPI 11.x** (base de producción actual: **11.0.8**).
- ⛔ **GLPI 12 NO está soportado** todavía. `12.0` es el límite **exclusivo**: la
  serie 12.x queda fuera del rango hasta que exista y pase la **suite de regresión**.

> **No declaramos soporte a una major futura sin pruebas.** Subir el `max` a `13.0`
> (para admitir 12.x) sólo se hace **después** de ejecutar la prueba de actualización
> (`../operations/glpi-upgrade-test.md`) contra la nueva major, con todos los plugins
> requeridos instalando/migrando/activando y los smoke tests en verde.

## Por qué el límite es exclusivo
Es la convención de rangos de GLPI y de SemVer: una major nueva (12.0) puede
introducir cambios incompatibles. Mantener el tope exclusivo evita que un plugin
se auto-declare compatible con una versión que **no** se probó.

## Procedimiento para ampliar el rango a una nueva major
1. Levantar staging idéntico a producción con la nueva major (RC **solo** en staging).
2. Ejecutar `infra/scripts/glpi-upgrade-test.sh <nueva_version>` (fail-closed).
3. Corregir los plugins que lo requieran.
4. Recién entonces actualizar `max` en los `setup.php` afectados y su `CHANGELOG`.
5. Producción sólo sobre release **estable** de la nueva major (nunca una RC).

## Estado actual
- `min = 11.0`, `max = 12.0` en los 7 plugins → **>=11.0 y <12.0**, probado en 11.0.8.
- GLPI 12: **no soportado** (12.0.0-rc1 es pre-release; no va a producción).

## Referencias
- Política de compatibilidad: `../../CLAUDE.md` (Objetivo de compatibilidad).
- Riesgo R1: `risks.md`. Prueba de upgrade: `../operations/glpi-upgrade-test.md`.
