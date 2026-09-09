#!/usr/bin/env bash
# =====================================================================
# new-plugin.sh — Generador de esqueleto de plugin GLPI (Fase 0)
# ---------------------------------------------------------------------
# Crea un plugin GLPI mínimo y VÁLIDO, sin lógica de negocio, siguiendo
# las convenciones oficiales (setup.php + hook.php) y la Regla 0 del
# proyecto: NO se modifica el core de GLPI.
#
# Uso:
#   ./new-plugin.sh <key> "<Nombre>" "<Descripción>" "<Estrategia>"
#
# <key>  debe ser minúsculas/alfanumérico (se usa como sufijo de función
#        PHP: plugin_init_<key>). Por eso NO admite guiones.
# =====================================================================
set -euo pipefail

if [ "$#" -ne 4 ]; then
  echo "Uso: $0 <key> \"<Nombre>\" \"<Descripción>\" \"<Estrategia>\"" >&2
  exit 2
fi

KEY="$1"; LABEL="$2"; DESC="$3"; STRATEGY="$4"

if ! printf '%s' "$KEY" | grep -Eq '^[a-z][a-z0-9]*$'; then
  echo "ERROR: key inválida '$KEY' (solo minúsculas/dígitos, sin guiones)." >&2
  exit 1
fi

KEYUPPER="$(printf '%s' "$KEY" | tr '[:lower:]' '[:upper:]')"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DIR="$ROOT/plugins/$KEY"

mkdir -p "$DIR/src" "$DIR/locales" "$DIR/templates" "$DIR/tests"
touch "$DIR/src/.gitkeep" "$DIR/locales/.gitkeep" "$DIR/templates/.gitkeep" "$DIR/tests/.gitkeep"

export KEY KEYUPPER LABEL DESC STRATEGY

# Sustitución restringida SOLO a nuestros tokens ${KEY}, ${KEYUPPER}, ${LABEL},
# ${DESC}, ${STRATEGY}. El '$' de PHP ($PLUGIN_HOOKS, $DB, $migration...) queda
# intacto porque nunca coincide con esos patrones literales.
render() {
  perl -pe '
    s/\$\{KEYUPPER\}/$ENV{KEYUPPER}/g;
    s/\$\{KEY\}/$ENV{KEY}/g;
    s/\$\{LABEL\}/$ENV{LABEL}/g;
    s/\$\{DESC\}/$ENV{DESC}/g;
    s/\$\{STRATEGY\}/$ENV{STRATEGY}/g;
  '
}

# ---------------------------- setup.php ------------------------------
render > "$DIR/setup.php" <<'TPL'
<?php
/**
 * ${LABEL} — plugin propio de la Plataforma GLPI Modular.
 *
 * Estrategia (Configure -> Existing -> Extend -> Integrate -> Build): ${STRATEGY}
 * Propósito: ${DESC}
 *
 * -------------------------------------------------------------------------
 *  REGLA 0: este plugin NO modifica el core de GLPI. Solo usa hooks/API
 *  oficiales. Ver ../../CLAUDE.md y docs/adr/ADR-0002-glpi-core-immutable.md.
 * -------------------------------------------------------------------------
 *
 * @license GPL-3.0-or-later
 * @glpi     11.0 (probado en 11.0.8)
 */

define('PLUGIN_${KEYUPPER}_VERSION', '0.1.0');

// Rango de versiones de GLPI soportadas (obligatorio por CLAUDE.md).
define('PLUGIN_${KEYUPPER}_GLPI_MIN_VERSION', '11.0');
define('PLUGIN_${KEYUPPER}_GLPI_MAX_VERSION', '12.0');

/**
 * Inicialización del plugin (se ejecuta en cada carga de GLPI).
 * Aquí se registran hooks oficiales. En Fase 0 solo se declara
 * el cumplimiento CSRF; la lógica funcional llegará en su fase.
 */
function plugin_init_${KEY}() {
    global $PLUGIN_HOOKS;

    // Cumplimiento CSRF exigido por GLPI para todo plugin.
    $PLUGIN_HOOKS['csrf_compliant']['${KEY}'] = true;

    // TODO(fase-modulo): registrar menús, clases y hooks de negocio.
    // Ejemplos de extensión SOPORTADA (nunca editar core):
    //   Plugin::registerClass(\GlpiPlugin\Xxx\MiClase::class);
    //   $PLUGIN_HOOKS['menu_toadd']['${KEY}'] = [...];
    //   $PLUGIN_HOOKS['item_add']['${KEY}']   = [...];
}

/**
 * Metadatos del plugin y requisitos de versión.
 * @return array
 */
function plugin_version_${KEY}() {
    return [
        'name'         => '${LABEL}',
        'version'      => PLUGIN_${KEYUPPER}_VERSION,
        'author'       => 'Plataforma Interna',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_${KEYUPPER}_GLPI_MIN_VERSION,
                'max' => PLUGIN_${KEYUPPER}_GLPI_MAX_VERSION,
            ],
            'php'  => [
                'min' => '8.2',
            ],
        ],
    ];
}

/**
 * Prerrequisitos antes de permitir la instalación.
 * @return boolean
 */
function plugin_${KEY}_check_prerequisites() {
    // El rango de versión lo valida GLPI a partir de 'requirements'.
    // Aquí irían chequeos adicionales propios del módulo.
    return true;
}

/**
 * Verificación de configuración.
 * @param boolean $verbose
 * @return boolean
 */
function plugin_${KEY}_check_config($verbose = false) {
    return true;
}
TPL

# ----------------------------- hook.php ------------------------------
render > "$DIR/hook.php" <<'TPL'
<?php
/**
 * Hooks de ciclo de vida de ${LABEL} (${KEY}).
 *
 * install()/uninstall() deben usar MIGRACIONES REVERSIBLES con prefijo
 * propio de tabla (glpi_plugin_${KEY}_*). NO se accede por SQL directo a
 * tablas del core (ver CLAUDE.md, sección Prohibiciones).
 *
 * @license GPL-3.0-or-later
 */

/**
 * Instalación: crear tablas propias, perfiles/permisos, tareas cron, etc.
 * @return boolean
 */
function plugin_${KEY}_install() {
    // global $DB;
    // $migration = new Migration(PLUGIN_${KEYUPPER}_VERSION);
    // TODO(fase-modulo): crear esquema propio con prefijo glpi_plugin_${KEY}_.
    // $migration->executeMigration();
    return true;
}

/**
 * Desinstalación: revertir de forma reversible lo creado en install().
 * @return boolean
 */
function plugin_${KEY}_uninstall() {
    // global $DB;
    // TODO(fase-modulo): DROP de tablas glpi_plugin_${KEY}_* y limpieza.
    return true;
}
TPL

# ----------------------------- README.md -----------------------------
render > "$DIR/README.md" <<'TPL'
# ${LABEL} (`${KEY}`)

Plugin propio de la **Plataforma GLPI Modular**.

- **Estrategia (matriz):** ${STRATEGY}
- **Propósito:** ${DESC}
- **GLPI soportado:** 11.0 – 12.0 (probado en 11.0.8)
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
TPL

# ---------------------------- CHANGELOG.md ---------------------------
render > "$DIR/CHANGELOG.md" <<'TPL'
# Changelog — ${LABEL} (`${KEY}`)

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y versionado [SemVer](https://semver.org/lang/es/).

## [Unreleased]
### Added
- Esqueleto inicial del plugin (Fase 0): `setup.php`, `hook.php` y estructura
  de carpetas (`src/`, `locales/`, `templates/`, `tests/`).
TPL

echo "Plugin generado: plugins/$KEY"
