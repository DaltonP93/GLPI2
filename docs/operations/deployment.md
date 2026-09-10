# Despliegue y rollback

Flujo obligatorio (ningún salto directo a producción):

```
desarrollo → tests → staging → backup → migración → smoke tests → producción → rollback (si falla)
```

## 1. Desarrollo
- Cambios en `plugins/*` o `services/*` (nunca en el core).
- Cumplir *Definition of Done* (`../../CLAUDE.md`).

## 2. Tests
- Unitarios/integración por módulo; lint/typecheck.
- `tests/upgrade/verify-core-untouched.sh` (core no versionado).

## 3. Staging (idéntico a producción)
- Desplegar los plugins/servicios y su configuración.
- Datos: copia anonimizada de producción.

## 4. Backup (antes de migrar)
- `infra/backup/backup.sh` (DB + files/config). Ver `backup-restore.md`.

## 5. Migración
- Migraciones de plugins (reversibles) y, si aplica, del core:
  `php bin/console db:update --no-interaction`.

## 6. Smoke tests
- `tests/smoke/run-smoke.sh`. Si fallan → **rollback**.

## 7. Producción
- Promover el artefacto validado en staging.
- Ventana de cambio y monitoreo post-despliegue.

## 8. Rollback
- Restaurar backup: `infra/backup/restore.sh <backup>`.
- Revertir la versión de plugins a la anterior.
- Registrar incidente y causa (post-mortem).

## Reglas
- Producción sólo sobre GLPI **estable**; nunca una RC.
- Secretos por secret manager; nunca en Git.
