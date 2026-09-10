# Company Portal (`companyportal`)

Plugin propio de la **Plataforma GLPI Modular**.

- **Estrategia (matriz):** Extend
- **Propósito:** Personalizaciones de UI y del portal de autoservicio sobre el Self-Service Portal y Forms nativos de GLPI 11.
- **GLPI soportado:** `>=11.0` y `<12.0` (el `max=12.0` es límite superior **excluyente**; probado en 11.0.8; GLPI 12 no soportado hasta suite de regresión — ver `../../docs/architecture/glpi-version-compatibility.md`)
- **Estado:** Fase 0 — esqueleto (sin lógica de negocio)

## Regla 0
Este plugin **no modifica el core de GLPI**. Solo usa hooks/API oficiales.
Ver `../../CLAUDE.md` y `../../docs/adr/ADR-0002-glpi-core-immutable.md`.

## Definition of Done (por módulo)
código · migración reversible · ACL · i18n ES/EN · auditoría · métricas/logs ·
tests · documentación · changelog · verificación de core intacto.

## Estructura
| Ruta | Rol |
|------|-----|
| `setup.php` | Metadatos, requisitos e `init` (registro de hooks) |
| `hook.php` | `install()` / `uninstall()` con migraciones reversibles |
| `src/` | Clases del plugin (PSR-4: `GlpiPlugin\...`) |
| `locales/` | Traducciones ES/EN (i18n) |
| `templates/` | Vistas Twig |
| `tests/` | Pruebas del plugin |

## Antes de desarrollar
Ejecutar el **análisis nativo GLPI 11**
(`../../docs/architecture/native-first-process.md`) y registrar la decisión
en un ADR bajo `../../docs/adr/`.
