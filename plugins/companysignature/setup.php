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

define('PLUGIN_COMPANYSIGNATURE_VERSION', '0.4.0');

// Rango de versiones GLPI: min <= GLPI < max (el limite superior es EXCLUYENTE).
// max='12.0' => GLPI 11.x soportado; 12.x NO hasta pasar la suite de regresion.
// Ver docs/architecture/glpi-version-compatibility.md
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

    // Registrar la clase de evidencia (para derechos/perfiles y futuras pestañas).
    if (class_exists(\GlpiPlugin\Companysignature\Model\ApprovalEvidence::class)) {
        Plugin::registerClass(\GlpiPlugin\Companysignature\Model\ApprovalEvidence::class);
    }

    // Escuchar los eventos de dominio de companyworkflow (hooks soportados; nunca editan core).
    // La evidencia se registra de forma IDEMPOTENTE ante retry/duplicado/restart y, si el listener
    // se pierde (proceso caído tras el COMMIT), se recupera con `plugins:companysignature:reconcile`.
    $PLUGIN_HOOKS['companyworkflow:decision_recorded']['companysignature']    = 'plugin_companysignature_on_decision_recorded';
    $PLUGIN_HOOKS['companyworkflow:transitioned']['companysignature']         = 'plugin_companysignature_on_transitioned';
    $PLUGIN_HOOKS['companyworkflow:approval_invalidated']['companysignature'] = 'plugin_companysignature_on_approval_invalidated';
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
