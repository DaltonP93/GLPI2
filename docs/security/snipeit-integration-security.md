# Seguridad — integración Snipe-IT ↔ GLPI2

Complementa `security-baseline.md` y ADR-0015. **Diseño, sin implementación.** Integración
**sólo por API soportada**; **nunca** acceso directo a la base de datos de Snipe-IT ni a tablas
core de GLPI para saltar reglas.

## Credenciales y tokens (modelo real de Snipe-IT v8.7.2)
- **Autenticación API = Laravel Passport** (`config/auth.php`: `api → passport`). El **personal
  access token** autentica **como un usuario**; la **autorización** la da el **RBAC granular por
  usuario** de Snipe (`config/permissions.php`: `assets.view/create/edit/checkout/checkin/
  audit/...`). **No** existen *scopes por endpoint*.
- **Mínimo privilegio ⇒ cuenta de servicio con ROL restringido**, no scopes por endpoint. Para
  **SI-1 (read-only)** el rol basta con **lectura de activos** (`assets.view`) + ver labels; nada
  de create/edit/checkout. Para fases de escritura se amplía el rol lo mínimo (p. ej. `assets.create`
  en SI-4).
- **No documentar scopes por endpoint** (la plataforma no los soporta): el control es el conjunto
  de permisos del rol del usuario del token.
- **Token fuera de Git** (secrets del entorno / gestor de secretos), **nunca** en código ni en el
  repo (ver `secrets-management.md`). `.env.example` sólo con placeholders.
- Rotación de token soportada por configuración; sin credenciales embebidas.

## Transporte y resiliencia
- **HTTPS** obligatorio hacia Snipe-IT.
- **Timeouts** por request; **retry con backoff exponencial** sólo en errores transitorios.
- **Circuit breaker**: aísla fallos de Snipe (no tumban GLPI).
- **Idempotencia** en escrituras (claves naturales; ver `../architecture/asset-bridge-model.md`).
- **Correlation ID** por operación, propagado a logs/auditoría.

## Logs y auditoría
- **Logs estructurados SIN tokens ni secretos** (redacción/enmascarado obligatorio).
- **Auditoría append-only** de cada operación de integración (`external_integration`): quién/qué/
  cuándo, correlation_id, resultado, sin PII innecesaria y **sin** tokens.
- No se registran IP/MAC/hostname/VLAN en etiquetas ni en el portal público.

## Autorización y multi-entidad
- El **gateway QR** es **AUTHENTICATED**: el `asset_tag` **identifica**, **GLPI autoriza** (ACL +
  entidad). Un `asset_tag` no concede acceso por sí mismo.
- **Multi-entidad estricto:** la resolución `asset_bridge → activo GLPI` respeta `glpi_entity_id`;
  un usuario de la entidad A no obtiene datos de activos de la entidad B (test 🔒 fail-closed).
- **No fuga** de datos técnicos (IP/MAC/hostname/VLAN) por el gateway ni por etiquetas.

## Aislamiento de dominios (ownership)
- **Un dato, un dueño** (matriz Source of Truth). Sin edición cruzada del mismo campo.
- **No** DB-a-DB. **No** copiar código de Snipe-IT (AGPL): cualquier reutilización de código
  **detiene** el trabajo para revisión de licencia.

## Métricas (para observabilidad)
- assets mapeados / no mapeados; sincronizaciones OK/fallidas; **lag** de sincronización;
  reintentos; **conflictos de ownership**; checkouts/checkins por período; activos sin
  responsable; activos sin etiqueta; comprados pendientes de alta; aceptaciones pendientes;
  tickets por activo; estado del **circuit breaker**; tamaño de la **dead-letter**.

## Definition of Done (seguridad, integración)
Token en secrets · mínimo privilegio · sin secretos en logs · sin DB directa · idempotencia ·
circuit breaker/reintentos/DLQ · correlation_id · multi-entidad probada (🔒) · QR exige ACL ·
sin fuga IP/MAC/hostname/VLAN · auditoría append-only · core (GLPI y Snipe) intactos.
