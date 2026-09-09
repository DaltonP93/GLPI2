<?php
/**
 * Hooks de ciclo de vida de Company QR (companyqr).
 *
 * install()/uninstall() deben usar MIGRACIONES REVERSIBLES con prefijo
 * propio de tabla (glpi_plugin_companyqr_*). NO se accede por SQL directo a
 * tablas del core (ver CLAUDE.md, sección Prohibiciones).
 *
 * @license GPL-3.0-or-later
 */

/**
 * Instalación: crear tablas propias, perfiles/permisos, tareas cron, etc.
 * @return boolean
 */
function plugin_companyqr_install() {
    // global $DB;
    // $migration = new Migration(PLUGIN_COMPANYQR_VERSION);
    // TODO(fase-modulo): crear esquema propio con prefijo glpi_plugin_companyqr_.
    // $migration->executeMigration();
    return true;
}

/**
 * Desinstalación: revertir de forma reversible lo creado en install().
 * @return boolean
 */
function plugin_companyqr_uninstall() {
    // global $DB;
    // TODO(fase-modulo): DROP de tablas glpi_plugin_companyqr_* y limpieza.
    return true;
}
