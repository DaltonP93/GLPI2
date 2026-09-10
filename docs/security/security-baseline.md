# Línea base de seguridad

## Control de acceso
- **RBAC** por perfil, entidad, departamento y rol de proceso.
- **Principio de mínimo privilegio** en usuarios, tokens e integraciones.

## Validación y protección
- Validar toda **entrada**; sanitizar archivos (tipo/tamaño/**MIME**).
- Protección **CSRF/XSS/SQLi** según el framework de GLPI y buenas prácticas
  (los plugins declaran `csrf_compliant`).
- No SQL directo a tablas del core para saltar reglas de negocio.

## Endpoints
- authn + authz + validación + rate limit (cuando aplique) + logging + manejo
  uniforme de errores (`../api/api-standards.md`).

## Datos y secretos
- **Secretos fuera del repositorio** (`secrets-management.md`).
- Logs estructurados **sin secretos**.

## Continuidad
- **Backups probados** y restauración documentada (`../operations/backup-restore.md`).
- Dependencias **fijadas y escaneadas**.
- Actualizar GLPI por releases **estables y de seguridad**; nunca una RC en
  producción.

## Cumplimiento
- Auditoría de acciones sensibles (`audit-logging.md`).
- Revisión de seguridad como parte del *Definition of Done*.
