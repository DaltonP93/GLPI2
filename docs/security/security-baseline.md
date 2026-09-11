# Línea base de seguridad

## Identidad vs. autorización (regla transversal)
> **Un identificador identifica; GLPI autoriza.**

Regla formal para **toda la plataforma** (introducida con `companyqr`, ver
`../adr/ADR-0011-companyqr.md`): cualquier token, código, QR o enlace es a lo sumo un
**identificador no enumerable**, **nunca** un secreto ni un mecanismo de
autenticación/autorización. Poseer el identificador (p. ej. una foto de una etiqueta QR)
**no** concede acceso. El acceso a datos protegidos depende **siempre** de
**sesión + ACL nativa de GLPI** (perfil + entidad + `canViewItem`). Todo módulo futuro
(Compras, Firma, Portal, IA, integraciones) que use tokens/enlaces debe cumplir esta regla.

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
