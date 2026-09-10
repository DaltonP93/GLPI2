<?php
/**
 * Company Workflow — plugin propio de la Plataforma GLPI Modular.
 *
 * Estrategia (Configure -> Existing -> Extend -> Integrate -> Build): Build/Extend
 * Propósito: Motor configurable de estados y transiciones (delegacion, escalamiento) reutilizable por otros modulos.
 *
 * -------------------------------------------------------------------------
 *  REGLA 0: este plugin NO modifica el core de GLPI. Solo usa hooks/API
 *  oficiales. Ver ../../CLAUDE.md y docs/adr/ADR-0002-glpi-core-immutable.md.
 * -------------------------------------------------------------------------
 *
 * @license GPL-3.0-or-later
 * @glpi     11.0 (probado en 11.0.8)
 */

define('PLUGIN_COMPANYWORKFLOW_VERSION', '0.1.0');

// Rango de versiones GLPI: min <= GLPI < max (el limite superior es EXCLUYENTE).
// max='12.0' => GLPI 11.x soportado; 12.x NO hasta pasar la suite de regresion.
// Ver docs/architecture/glpi-version-compatibility.md
define('PLUGIN_COMPANYWORKFLOW_GLPI_MIN_VERSION', '11.0');
define('PLUGIN_COMPANYWORKFLOW_GLPI_MAX_VERSION', '12.0');

/**
 * Inicialización del plugin (se ejecuta en cada carga de GLPI).
 * Aquí se registran hooks oficiales. En Fase 0 solo se declara
 * el cumplimiento CSRF; la lógica funcional llegará en su fase.
 */
function plugin_init_companyworkflow() {
    global $PLUGIN_HOOKS;

    // Cumplimiento CSRF exigido por GLPI para todo plugin.
    $PLUGIN_HOOKS['csrf_compliant']['companyworkflow'] = true;

    // TODO(fase-modulo): registrar menús, clases y hooks de negocio.
    // Ejemplos de extensión SOPORTADA (nunca editar core):
    //   Plugin::registerClass(\GlpiPlugin\Xxx\MiClase::class);
    //   $PLUGIN_HOOKS['menu_toadd']['companyworkflow'] = [...];
    //   $PLUGIN_HOOKS['item_add']['companyworkflow']   = [...];
}

/**
 * Metadatos del plugin y requisitos de versión.
 * @return array
 */
function plugin_version_companyworkflow() {
    return [
        'name'         => 'Company Workflow',
        'version'      => PLUGIN_COMPANYWORKFLOW_VERSION,
        'author'       => 'Plataforma Interna',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_COMPANYWORKFLOW_GLPI_MIN_VERSION,
                'max' => PLUGIN_COMPANYWORKFLOW_GLPI_MAX_VERSION,
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
function plugin_companyworkflow_check_prerequisites() {
    // El rango de versión lo valida GLPI a partir de 'requirements'.
    // Aquí irían chequeos adicionales propios del módulo.
    return true;
}

/**
 * Verificación de configuración.
 * @param boolean $verbose
 * @return boolean
 */
function plugin_companyworkflow_check_config($verbose = false) {
    return true;
}
