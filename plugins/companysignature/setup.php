<?php
/**
 * Company Signature — plugin propio de la Plataforma GLPI Modular.
 *
 * Estrategia (Configure -> Existing -> Extend -> Integrate -> Build): Build + Integrate
 * Propósito: Evidencia de aprobacion electronica (hash, timestamp, auditoria) y punto de integracion para firma digital certificada.
 *
 * -------------------------------------------------------------------------
 *  REGLA 0: este plugin NO modifica el core de GLPI. Solo usa hooks/API
 *  oficiales. Ver ../../CLAUDE.md y docs/adr/ADR-0002-glpi-core-immutable.md.
 * -------------------------------------------------------------------------
 *
 * @license GPL-3.0-or-later
 * @glpi     11.0 (probado en 11.0.8)
 */

define('PLUGIN_COMPANYSIGNATURE_VERSION', '0.1.0');

// Rango de versiones de GLPI soportadas (obligatorio por CLAUDE.md).
define('PLUGIN_COMPANYSIGNATURE_GLPI_MIN_VERSION', '11.0');
define('PLUGIN_COMPANYSIGNATURE_GLPI_MAX_VERSION', '12.0');

/**
 * Inicialización del plugin (se ejecuta en cada carga de GLPI).
 * Aquí se registran hooks oficiales. En Fase 0 solo se declara
 * el cumplimiento CSRF; la lógica funcional llegará en su fase.
 */
function plugin_init_companysignature() {
    global $PLUGIN_HOOKS;

    // Cumplimiento CSRF exigido por GLPI para todo plugin.
    $PLUGIN_HOOKS['csrf_compliant']['companysignature'] = true;

    // TODO(fase-modulo): registrar menús, clases y hooks de negocio.
    // Ejemplos de extensión SOPORTADA (nunca editar core):
    //   Plugin::registerClass(\GlpiPlugin\Xxx\MiClase::class);
    //   $PLUGIN_HOOKS['menu_toadd']['companysignature'] = [...];
    //   $PLUGIN_HOOKS['item_add']['companysignature']   = [...];
}

/**
 * Metadatos del plugin y requisitos de versión.
 * @return array
 */
function plugin_version_companysignature() {
    return [
        'name'         => 'Company Signature',
        'version'      => PLUGIN_COMPANYSIGNATURE_VERSION,
        'author'       => 'Plataforma Interna',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_COMPANYSIGNATURE_GLPI_MIN_VERSION,
                'max' => PLUGIN_COMPANYSIGNATURE_GLPI_MAX_VERSION,
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
function plugin_companysignature_check_prerequisites() {
    // El rango de versión lo valida GLPI a partir de 'requirements'.
    // Aquí irían chequeos adicionales propios del módulo.
    return true;
}

/**
 * Verificación de configuración.
 * @param boolean $verbose
 * @return boolean
 */
function plugin_companysignature_check_config($verbose = false) {
    return true;
}
