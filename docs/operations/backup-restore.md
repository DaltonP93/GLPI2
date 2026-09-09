# Backup y restauración

Scripts: `infra/backup/backup.sh` y `infra/backup/restore.sh`. Leen credenciales
de `infra/docker/.env` (no versionado).

## Qué se respalda
- **Base de datos** de GLPI (volcado consistente `--single-transaction`).
- **Archivos**: `files/` (documentos, logs, sesiones) y `config/`.
- Los **plugins** ya están en Git (no requieren backup de código).

## Hacer un backup (obligatorio antes de actualizar)
```bash
infra/backup/backup.sh
# genera _backups/<timestamp>/ con db-*.sql.gz, glpi-files.tar.gz y MANIFEST.txt
```

## Restaurar (rollback)
```bash
infra/backup/restore.sh _backups/<timestamp>
# pide confirmación explícita (escribir RESTAURAR)
```

## Buenas prácticas
- **Probar la restauración** periódicamente (un backup sin restore probado no es
  un backup).
- Guardar backups fuera del host de producción, cifrados si contienen datos
  sensibles.
- Definir **retención** por política.
- Registrar en `MANIFEST.txt` la versión de GLPI del backup (útil en upgrades).

## Relación con actualización
El backup es el **paso previo** de la prueba de actualización y el mecanismo de
**rollback** ante fallo (`glpi-upgrade-test.md`, `deployment.md`).
