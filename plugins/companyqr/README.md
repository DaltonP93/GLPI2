# Company QR (`companyqr`)

Plugin propio de la **Plataforma GLPI Modular**.

- **Estrategia (matriz):** Build sobre capacidades nativas (QR/PDF/rutas/hooks/tickets/Altcha).
- **Propósito:** QR por activo → ficha **segura** (filtrada por ACL nativa) y **reporte de
  problema** que crea un ticket vinculado al activo; etiqueta física imprimible.
- **Principio rector:** *el QR identifica; GLPI autoriza* (el token identifica, no autoriza).
- **GLPI soportado:** `>=11.0` y `<12.0` (`max=12.0` excluyente; probado en 11.0.8; GLPI 12
  no soportado hasta suite de regresión — ver `../../docs/architecture/glpi-version-compatibility.md`).
- **Estado:** Fase 1 — implementación funcional (en revisión / PR).

## Regla 0
Este plugin **no modifica el core de GLPI**. Solo usa hooks/API/controladores oficiales.
Ver `../../CLAUDE.md` y `../../docs/adr/ADR-0002-glpi-core-immutable.md`.

## Diseño
- ADR: `../../docs/adr/ADR-0011-companyqr.md`
- Funcional: `../../docs/functional/companyqr.md` (+ mock `../../docs/functional/mocks/companyqr-mock.html`)
- Técnico: `../../docs/architecture/companyqr-technical-design.md`
- Spike Forms: `../../docs/architecture/companyqr-forms-spike.md`

## Rutas (prefijo automático `/plugins/companyqr/`)
| Método | Ruta | Seguridad | Uso |
|---|---|---|---|
| GET | `/scan/{token}` | AUTHENTICATED | Ficha estándar (login + retorno por el firewall; ACL nativa) |
| GET | `/public/{token}` | NO_CHECK | Modo anónimo (subset mínimo), **OFF por defecto** |
| POST | `/scan/{token}/report` | AUTHENTICATED | Reporte → ticket (solicitante = sesión) |
| POST | `/public/{token}/report` | NO_CHECK | Reporte anónimo (rate limit + Altcha), sólo si habilitado |
| GET | `/label/{code_id}` | AUTHENTICATED | PDF de etiqueta (derecho `print`) |
| POST | `/admin/{action}` | AUTHENTICATED | generate/rotate/revoke (derecho `generate`, CSRF) |

## Datos (tablas propias, migración reversible)
- `glpi_plugin_companyqr_codes` — token único, `public_code` único, estado, entidad.
- `glpi_plugin_companyqr_scans` — auditoría mínima **sin PII** (sin IP/User-Agent).

## Tests
- Unitarios (sin GLPI): `php tests/unit/run.php`.
- Integración (dentro del contenedor GLPI): `php bin/console plugins:companyqr:selftest`
  — ACL multi-entidad, no-fuga, ciclo de vida, etiqueta real (fail-closed).

## Definition of Done (por módulo)
código · migración reversible · ACL · i18n ES/EN · auditoría · métricas/logs ·
tests · documentación · changelog · verificación de core intacto.
