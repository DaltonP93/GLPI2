<?php
/**
 * Company Integrations — plugin propio de la Plataforma GLPI Modular.
 *
 * Estrategia (Configure -> Existing -> Extend -> Integrate -> Build): Integrate.
 * Propósito (SI-1, ADR-0015): integración READ-ONLY con Snipe-IT por API — cliente HTTP resiliente
 * (timeout/retry/backoff/circuit-breaker/correlation-id/logs sanitizados), tablas propias
 * (`asset_bridge`, `map_companies`, `map_users`, alias de asset tag), reconciliación con detección
 * de conflictos (sin auto-corregir) y gateway de resolución estable por asset tag.
 *
 * SI-1 NO escribe en Snipe ni modifica activos core de GLPI. Sólo persiste en tablas propias.
 *
 * -------------------------------------------------------------------------
 *  REGLA 0: este plugin NO modifica el core de GLPI (ni el de Snipe-IT). Solo API/hooks
 *  oficiales. Snipe-IT es AGPL-3.0 → integración SÓLO por API, sin copiar código.
 * -------------------------------------------------------------------------
 *
 * @license GPL-3.0-or-later
 * @glpi     11.0 (probado en 11.0.8)
 */

define('PLUGIN_COMPANYINTEGRATIONS_VERSION', '0.2.0');

define('PLUGIN_COMPANYINTEGRATIONS_GLPI_MIN_VERSION', '11.0');
define('PLUGIN_COMPANYINTEGRATIONS_GLPI_MAX_VERSION', '12.0');

/**
 * Inicialización del plugin (se ejecuta en cada carga de GLPI).
 */
function plugin_init_companyintegrations() {
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['companyintegrations'] = true;

    $classes = [
        \GlpiPlugin\Companyintegrations\Model\AssetBridge::class,
        \GlpiPlugin\Companyintegrations\Model\MapCompany::class,
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
function plugin_version_companyintegrations() {
    return [
        'name'         => 'Company Integrations',
        'version'      => PLUGIN_COMPANYINTEGRATIONS_VERSION,
        'author'       => 'Plataforma Interna',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_COMPANYINTEGRATIONS_GLPI_MIN_VERSION,
                'max' => PLUGIN_COMPANYINTEGRATIONS_GLPI_MAX_VERSION,
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
function plugin_companyintegrations_check_prerequisites() {
    return true;
}

/**
 * Verificación de configuración.
 * @param boolean $verbose
 * @return boolean
 */
function plugin_companyintegrations_check_config($verbose = false) {
    return true;
}
