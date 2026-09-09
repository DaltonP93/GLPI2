# Prueba de actualización de GLPI (los plugins deben sobrevivir sin tocar el core)

Objetivo: **poder actualizar GLPI sin perder personalizaciones** y verificar que
nuestros módulos siguen funcionando, **sin modificar el core**. Toda actualización
mayor requiere esta suite en staging antes de producción.

Script orquestador: `infra/scripts/glpi-upgrade-test.sh <version_destino>`.

## Procedimiento
```
1. Backup            → infra/backup/backup.sh
2. Verificar core    → tests/upgrade/verify-core-untouched.sh
                       (el repo no contiene código de core; nada que se pierda)
3. Reconstruir imagen con la NUEVA versión oficial de GLPI (upstream)
4. Migraciones core  → php bin/console db:update --no-interaction
5. Migrar/activar plugins (cada plugin declara su rango de versiones)
6. Smoke tests       → tests/smoke/run-smoke.sh
7. Si algo falla     → rollback: infra/backup/restore.sh <backup>
```

## Por qué funciona sin fork
- GLPI vive **dentro de la imagen** y se **descarga** por versión (`GLPI_VERSION`).
  Actualizar = reconstruir la imagen con otra versión oficial.
- Nuestros plugins están **montados** desde `plugins/` y usan sólo hooks/API
  oficiales + tablas propias con prefijo → no dependen de archivos internos del core.
- Si un plugin no soporta aún la nueva versión, **no se activa** y se adapta antes
  de promover a producción (no se parchea el core para forzarlo).

## Criterios de aceptación
- El core no tiene cambios propios (paso 2 en verde).
- Migraciones de core y de plugins ejecutan sin error.
- Smoke tests OK.
- Plan de **rollback** probado.

## Regla de versión
- Producción sólo sobre release **estable** (11.0.8). **12.0.0-rc1 NO** en producción.
- Antes de aceptar una major nueva: staging idéntico, backup, actualizar core,
  actualizar plugins, migraciones, smoke tests, regresión, y recién producción.
