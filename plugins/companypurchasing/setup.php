<?php
/**
 * Company Purchasing — plugin propio de la Plataforma GLPI Modular.
 *
 * Estrategia (Configure -> Existing -> Extend -> Integrate -> Build): Build (apoyado en Forms/Assets nativos)
 * Propósito: Solicitudes de compra, cotizaciones versionadas, aprobaciones, recepcion y alta/vinculo de activos GLPI.
 *
 * -------------------------------------------------------------------------
 *  REGLA 0: este plugin NO modifica el core de GLPI. Solo usa hooks/API
 *  oficiales. Ver ../../CLAUDE.md y docs/adr/ADR-0002-glpi-core-immutable.md.
 * -------------------------------------------------------------------------
 *
 * @license GPL-3.0-or-later
 * @glpi     11.0 (probado en 11.0.8)
 */

define('PLUGIN_COMPANYPURCHASING_VERSION', '0.3.0');

// Rango de versiones GLPI: min <= GLPI < max (el limite superior es EXCLUYENTE).
// max='12.0' => GLPI 11.x soportado; 12.x NO hasta pasar la suite de regresion.
// Ver docs/architecture/glpi-version-compatibility.md
define('PLUGIN_COMPANYPURCHASING_GLPI_MIN_VERSION', '11.0');
define('PLUGIN_COMPANYPURCHASING_GLPI_MAX_VERSION', '12.0');

/**
 * Inicialización del plugin (se ejecuta en cada carga de GLPI).
 * Aquí se registran hooks oficiales. En Fase 0 solo se declara
 * el cumplimiento CSRF; la lógica funcional llegará en su fase.
 */
function plugin_init_companypurchasing() {
    global $PLUGIN_HOOKS;

    // Cumplimiento CSRF exigido por GLPI para todo plugin.
    $PLUGIN_HOOKS['csrf_compliant']['companypurchasing'] = true;

    // Registrar la clase de solicitud (para derechos/perfiles y futuras pestañas). El derecho
    // `plugin_companypurchasing` y sus bits (ACL por acción) viven en este modelo. `document_types`: el PDF
    // aprobado (companysignature) se vincula a la solicitud como `Document_Item` NATIVO.
    if (class_exists(\GlpiPlugin\Companypurchasing\Model\Request::class)) {
        Plugin::registerClass(\GlpiPlugin\Companypurchasing\Model\Request::class, ['document_types' => true]);
    }
    // P2D-2: cotizaciones con N adjuntos vía `Document` + `Document_Item` NATIVOS (sin duplicar maestros).
    if (class_exists(\GlpiPlugin\Companypurchasing\Model\Quote::class)) {
        Plugin::registerClass(\GlpiPlugin\Companypurchasing\Model\Quote::class, ['document_types' => true]);
    }

    // P2D-2: proyección best-effort del estado del motor (companyworkflow es la AUTORIDAD; la
    // reconciliación garantiza la convergencia si el listener se pierde).
    $PLUGIN_HOOKS['companyworkflow:transitioned']['companypurchasing']         = 'plugin_companypurchasing_on_workflow_event';
    $PLUGIN_HOOKS['companyworkflow:approval_invalidated']['companypurchasing'] = 'plugin_companypurchasing_on_workflow_event';

    // P2D-3…P2D-4 (no implementado): recepción/outbox y UI (portal/formularios/bandejas/métricas).
}

/**
 * Metadatos del plugin y requisitos de versión.
 * @return array
 */
function plugin_version_companypurchasing() {
    return [
        'name'         => 'Company Purchasing',
        'version'      => PLUGIN_COMPANYPURCHASING_VERSION,
        'author'       => 'Plataforma Interna',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_COMPANYPURCHASING_GLPI_MIN_VERSION,
                'max' => PLUGIN_COMPANYPURCHASING_GLPI_MAX_VERSION,
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
function plugin_companypurchasing_check_prerequisites() {
    // El rango de versión lo valida GLPI a partir de 'requirements'.
    // Aquí irían chequeos adicionales propios del módulo.
    return true;
}

/**
 * Verificación de configuración.
 * @param boolean $verbose
 * @return boolean
 */
function plugin_companypurchasing_check_config($verbose = false) {
    return true;
}
