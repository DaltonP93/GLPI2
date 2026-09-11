# Changelog — Company QR (`companyqr`)

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y versionado [SemVer](https://semver.org/lang/es/).

## [Unreleased]
### Security / Hardening (revisión de PR #3)
- **URL del formulario de reporte ahora absoluta** (generada por el controlador), no
  relativa en Twig — evita resolverse como `/scan/scan/{token}/report`.
- **Altcha corregido**: `AltchaManager::getInstance()->verifySolution()` (método de
  instancia) + `removeChallenge()` anti-replay, aislado en `AltchaVerifier`. Modo anónimo
  marcado **experimental/OFF**; widget diferido (no soportado en v1).
- **ACL en `rotate`/`revoke`**: exigen la ACL nativa del **activo** (`canMutateCode`), no
  sólo el bit `generate` (bloquea mutación entre entidades por `code_id`).
- **Ticket + `Item_Ticket` atómico/fail-closed**: si falla el vínculo, se revierte el ticket.
- **Rate limit por actor**: bucket `HMAC(ip|token)` sólo en cache (no persiste IP);
  fail-open documentado sin cache.
- **`public_code` concurrente**: `createForItem` reintenta ante colisión UNIQUE y nunca
  devuelve un `Code` inválido.
- **E2E HTTP real** en CI (`tests/e2e/companyqr-http.sh`) + **previsualización PNG** de la
  etiqueta como artefacto.

### Added
- **Fase 1 — implementación funcional.** Principio rector: *el QR identifica; GLPI autoriza*.
- Modelo de datos propio (migración reversible): `glpi_plugin_companyqr_codes`
  (token único, `public_code` único, estado, entidad, ciclo de vida) y
  `glpi_plugin_companyqr_scans` (auditoría mínima **sin PII**).
- Servicios adaptadores: `TokenGenerator`, `CodeManager`, `AssetResolver`,
  `AccessPolicyService`, `QrRenderer` (tc-lib-barcode), `LabelRenderer` (TCPDF),
  `TicketCreator`, `AuditService`, `RateLimiter`, `PluginConfig`.
- Controladores Symfony (rutas bajo `/plugins/companyqr/`):
  - `GET /scan/{token}` — **AUTHENTICATED** (ruta estándar del QR; la ACL nativa autoriza).
  - `GET /public/{token}` — **NO_CHECK**, modo anónimo **apagado por defecto**.
  - `POST /scan/{token}/report` y `POST /public/{token}/report` — reporte → ticket vinculado.
  - `GET /label/{code_id}` — PDF 70,75×24 mm (derecho `print`).
  - `POST /admin/{action}` — generar/rotar/revocar (derecho `generate`, CSRF).
- Derechos de plugin `plugin_companyqr` (READ / generate / print / config).
- `public_code` propio y **único**: usa `otherserial` si es válido; si no, genera uno;
  **nunca** escribe en `otherserial` (no toca datos maestros del inventario).
- Sincronización del ciclo de vida del activo por hooks (update/delete/restore/purge).
- i18n ES/EN (`locales/es_ES.po`, `locales/en_GB.po`).
- Tests: unitarios puros (`tests/unit/run.php`) y autotest de integración
  (`plugins:companyqr:selftest`) con pruebas **obligatorias** de ACL multi-entidad y no-fuga,
  ciclo de vida y generación de etiqueta real (artefacto de CI). Probe no fatal del gate de Forms.

### Notes
- Los catálogos `.mo` se compilan en build (`msgfmt`); sin `.mo`, GLPI usa el texto fuente.
- La decisión Forms vs. formulario propio: v1 usa formulario mínimo propio; el gate de Forms
  corre como probe de integración (ver `../../docs/architecture/companyqr-forms-spike.md`).

## [0.1.0] — Fase 0
### Added
- Esqueleto inicial del plugin: `setup.php`, `hook.php` y estructura de carpetas.
