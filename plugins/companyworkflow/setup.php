<?php
/**
 * Company Workflow — plugin propio de la Plataforma GLPI Modular.
 *
 * Estrategia (Configure -> Existing -> Extend -> Integrate -> Build): Build/Extend.
 * Propósito: MOTOR GENÉRICO y REUSABLE de máquina de estados (definiciones versionadas,
 * estados/transiciones/condiciones configurables, quórum, delegación, SLA, escalamiento,
 * notificaciones, auditoría append-only, multi-entidad, ACL). NO conoce el dominio de
 * Compras: los estados/aprobadores/montos los aporta quien lo consuma (companypurchasing).
 * Ver docs/adr/ADR-0012-companyworkflow.md y docs/architecture/companyworkflow-technical-design.md.
 *
 * -------------------------------------------------------------------------
 *  REGLA 0: este plugin NO modifica el core de GLPI. Solo usa hooks/API
 *  oficiales. Ver ../../CLAUDE.md y docs/adr/ADR-0002-glpi-core-immutable.md.
 * -------------------------------------------------------------------------
 *
 * @license GPL-3.0-or-later
 * @glpi     11.0 (probado en 11.0.8)
 */

define('PLUGIN_COMPANYWORKFLOW_VERSION', '0.2.0');

// Rango de versiones GLPI: min <= GLPI < max (el limite superior es EXCLUYENTE).
define('PLUGIN_COMPANYWORKFLOW_GLPI_MIN_VERSION', '11.0');
define('PLUGIN_COMPANYWORKFLOW_GLPI_MAX_VERSION', '12.0');

/**
 * Inicialización del plugin (se ejecuta en cada carga de GLPI).
 * Registra hooks oficiales (nunca edita core).
 */
function plugin_init_companyworkflow() {
    global $PLUGIN_HOOKS;

    // Cumplimiento CSRF exigido por GLPI para todo plugin.
    $PLUGIN_HOOKS['csrf_compliant']['companyworkflow'] = true;

    // Registrar clases del motor (para derechos/perfiles). Sólo si el autoload las ve.
    $classes = [
        \GlpiPlugin\Companyworkflow\Model\WorkflowDef::class,
        \GlpiPlugin\Companyworkflow\Model\Instance::class,
    ];
    foreach ($classes as $class) {
        if (class_exists($class)) {
            Plugin::registerClass($class);
        }
    }
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
