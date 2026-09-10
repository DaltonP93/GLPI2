# ADR-0003: Estrategia de plugins propios

- **Estado:** Aceptado
- **Fecha:** 2026-09-09
- **Decisores:** Equipo de plataforma
- **Módulo/área:** `plugins/*`

## Contexto
Los diferenciadores de la organización deben vivir desacoplados del core y ser
compatibles con futuras versiones de GLPI. GLPI ofrece un sistema de plugins
oficial (`setup.php` + `hook.php`, hooks, `Plugin::registerClass`, migraciones).

## Decisión
Cada módulo propio es un **plugin GLPI independiente** con:

- Clave en minúsculas sin guiones (sufijo válido de función: `plugin_init_<key>`).
- `setup.php` con `plugin_version_<key>()` que declara **rango de versiones GLPI**
  soportadas (`min` 11.0, `max` 12.0; probado en 11.0.8) y PHP mínimo.
- `hook.php` con `install()`/`uninstall()` usando **migraciones reversibles**.
- **Prefijo propio de tablas** `glpi_plugin_<key>_*`. Nunca SQL directo a tablas
  del core: se usan clases/servicios/API de GLPI.
- Clases en `src/` (PSR-4 `GlpiPlugin\...`), vistas en `templates/` (Twig),
  traducciones en `locales/` (i18n ES/EN), pruebas en `tests/`.
- `README.md` y `CHANGELOG.md` por plugin.

Los 7 plugins iniciales: `companyportal`, `companypurchasing`, `companyworkflow`,
`companyqr`, `companydashboard`, `companysignature`, `companyintegrations`.
Se genera su esqueleto con `infra/scripts/new-plugin.sh` (reproducible).

**Plugin de validación:** `companyqr` será el primero funcional, por ser pequeño
y permitir ejercitar instalación, permisos, hooks, i18n, auditoría, migraciones y
compatibilidad de actualización.

## Alternativas consideradas
- **Un mega-plugin monolítico** — descartado: acopla módulos y dificulta versionar
  y probar de forma independiente.
- **Personalizaciones vía plantillas del core** — descartado por Regla 0.
- **Un plugin por módulo (elegida)**.

## Consecuencias
- (+) Versionado, pruebas y despliegue independientes por módulo.
- (+) Aislamiento de datos por prefijo de tabla.
- (−) Requiere coordinar contratos entre plugins (ej. `companyworkflow` reusable).

## Cumplimiento de la Regla 0
Sólo hooks/API oficiales. Sin edición de core.
