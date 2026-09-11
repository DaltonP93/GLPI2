<?php
/**
 * Company QR — plugin propio de la Plataforma GLPI Modular.
 *
 * Estrategia (Configure -> Existing -> Extend -> Integrate -> Build): Build sobre
 * capacidades nativas (QR/PDF/rutas/hooks/tickets/Altcha). Ver docs/adr/ADR-0011-companyqr.md.
 * Principio rector: "el QR identifica; GLPI autoriza".
 *
 * -------------------------------------------------------------------------
 *  REGLA 0: este plugin NO modifica el core de GLPI. Solo usa hooks/API
 *  oficiales. Ver ../../CLAUDE.md y docs/adr/ADR-0002-glpi-core-immutable.md.
 * -------------------------------------------------------------------------
 *
 * @license GPL-3.0-or-later
 * @glpi     11.0 (probado en 11.0.8)
 */

define('PLUGIN_COMPANYQR_VERSION', '0.2.0');

// Rango de versiones GLPI: min <= GLPI < max (el limite superior es EXCLUYENTE).
// max='12.0' => GLPI 11.x soportado; 12.x NO hasta pasar la suite de regresion.
// Ver docs/architecture/glpi-version-compatibility.md
define('PLUGIN_COMPANYQR_GLPI_MIN_VERSION', '11.0');
define('PLUGIN_COMPANYQR_GLPI_MAX_VERSION', '12.0');

/**
 * Inicialización del plugin (se ejecuta en cada carga de GLPI).
 * Registra hooks oficiales (nunca edita core).
 */
function plugin_init_companyqr() {
    global $PLUGIN_HOOKS;

    // Cumplimiento CSRF exigido por GLPI para todo plugin.
    $PLUGIN_HOOKS['csrf_compliant']['companyqr'] = true;

    // Registrar la clase del código (para derechos/perfiles y futuras pestañas).
    if (class_exists(\GlpiPlugin\Companyqr\Model\Code::class)) {
        Plugin::registerClass(\GlpiPlugin\Companyqr\Model\Code::class);
    }

    // Botón "QR / etiqueta" en el formulario de los activos (hook soportado).
    $PLUGIN_HOOKS['post_item_form']['companyqr'] = 'plugin_companyqr_post_item_form';

    // Sincronización del ciclo de vida del activo con el estado del código.
    $PLUGIN_HOOKS['item_update']['companyqr']   = 'plugin_companyqr_item_update';
    $PLUGIN_HOOKS['item_delete']['companyqr']   = 'plugin_companyqr_item_delete';
    $PLUGIN_HOOKS['item_restore']['companyqr']  = 'plugin_companyqr_item_restore';
    $PLUGIN_HOOKS['item_purge']['companyqr']    = 'plugin_companyqr_item_purge';
}

/**
 * Metadatos del plugin y requisitos de versión.
 * @return array
 */
function plugin_version_companyqr() {
    return [
        'name'         => 'Company QR',
        'version'      => PLUGIN_COMPANYQR_VERSION,
        'author'       => 'Plataforma Interna',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_COMPANYQR_GLPI_MIN_VERSION,
                'max' => PLUGIN_COMPANYQR_GLPI_MAX_VERSION,
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
function plugin_companyqr_check_prerequisites() {
    // El rango de versión lo valida GLPI a partir de 'requirements'.
    return true;
}

/**
 * Verificación de configuración.
 * @param boolean $verbose
 * @return boolean
 */
function plugin_companyqr_check_config($verbose = false) {
    return true;
}
