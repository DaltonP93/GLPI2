# Company QR (`companyqr`)

Plugin propio de la **Plataforma GLPI Modular**.

- **Estrategia (matriz):** Build sobre capacidades nativas (QR/PDF/rutas/hooks/tickets/Altcha).
- **Propósito:** QR por activo → ficha **segura** (filtrada por ACL nativa) y **reporte de
  problema** que crea un ticket vinculado al activo; etiqueta física imprimible.
- **Principio rector:** *el QR identifica; GLPI autoriza* (el token identifica, no autoriza).
- **GLPI soportado:** `>=11.0` y `<12.0` (`max=12.0` excluyente; probado en 11.0.8; GLPI 12
  no soportado hasta suite de regresión — ver `../../docs/architecture/glpi-version-compatibility.md`).
- **Estado:** Fase 1 — implementación funcional; 0.3.0 agrega la API pública `CompanyQrApi` (SI4-3, ADR-0022);
  0.4.0 agrega la impresión masiva de etiquetas (ADR-0024).

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
| GET | `/labels/{batch}` | AUTHENTICATED | PDF de un lote, una etiqueta por página (derecho `print`; lote de la sesión) |
| POST | `/admin/{action}` | AUTHENTICATED | generate/rotate/revoke (derecho `generate`, CSRF) |

## Impresión masiva (ADR-0024)
En el listado de activos: seleccionar → **Acciones** → **Imprimir etiquetas QR**. Se abre un PDF con una etiqueta por
página. Requiere `print`; con `generate` aparece la opción "Generar los códigos QR que falten". Los activos sin acceso,
sin código, con código suspendido/revocado o fuera del tope (`label_batch_max`, default 200, máx. 500) se omiten con
mensaje. Cada etiqueta impresa se audita (`label_printed`, canal `batch`).

## API pública para otros plugins (`CompanyQrApi`, ADR-0022)
`GlpiPlugin\Companyqr\Api\CompanyQrApi` — agnóstica del dominio; la usa SI-4 (companyintegrations) para el código
y la etiqueta de cada unidad recibida.

| Método | Derecho (sesión) | Devuelve |
|---|---|---|
| `ensureForItem(itemtype, items_id)` | `generate` + activo visible (`canViewItem`) | metadatos + `outcome` (created/existing); get-or-create idempotente |
| `findForItem(itemtype, items_id)` / `getCode(code_id)` | read, generate o print + activo visible | metadatos o `null` |
| `renderLabelPdf(code_id)` | `print` + activo visible; código ACTIVO | PDF de la etiqueta (la misma de `GET /label/{code_id}`) |

- Metadatos = `code_id`, `public_code`, `status`, `itemtype`, `items_id`, `entities_id`. **Nunca el token.**
- La URL del QR (con el token) sólo la arma companyqr (`ScanUrl`, `LabelComposer`).
- Errores tipados `CompanyQrException` (`acl`, `not_found`, `inactive`, `render`, `invalid`).
- Nunca rota, revoca ni reactiva un código.

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
