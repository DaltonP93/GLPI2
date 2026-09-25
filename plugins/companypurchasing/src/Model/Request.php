<?php

/**
 * Solicitud de compra (tabla propia `glpi_plugin_companypurchasing_requests`).
 *
 * P2D-1 (núcleo): borrador editable + numeración al abandonar DRAFT + snapshot de estado de dominio.
 * `companyworkflow` será la AUTORIDAD de estados en P2D-2; `domain_state`/`current_state_code` es un
 * **snapshot/cache** para listados y NO un segundo motor de workflow.
 *
 * También define el derecho de plugin `plugin_companypurchasing` y sus bits (ACL separada por acción).
 *
 * REGLA 0: tabla PROPIA del plugin (prefijo `glpi_plugin_companypurchasing_`), no del core.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonDBTM;

class Request extends CommonDBTM
{
    /** Derecho de plugin (bits definidos abajo). */
    public static $rightname = 'plugin_companypurchasing';

    // --- Bits del derecho `plugin_companypurchasing` (no colisionan: el rightname es propio). ---
    // Activos desde P2D-1:
    public const RIGHT_CREATE_REQUEST = 2;   // crear una solicitud (BORRADOR)
    public const RIGHT_VIEW_OWN       = 4;   // ver las propias
    public const RIGHT_VIEW_ENTITY    = 8;   // ver todas las de su entidad
    public const RIGHT_EDIT_DRAFT     = 16;  // editar mientras es borrador
    public const RIGHT_MANAGE_CONFIG  = 32;  // administrar configuración (categorías, scopes, umbrales)
    // Incrementos posteriores (P2D-2…P2D-4):
    public const RIGHT_MANAGE_PURCHASING = 64;  // gestionar compras (cotizar/seleccionar/iniciar compra) — P2D-2/3
    public const RIGHT_RECEIVE           = 128; // registrar recepción física — ACTIVO desde P2D-3
    public const RIGHT_DELIVER           = 256; // registrar entrega — reservado (P2D-4)
    public const RIGHT_VIEW_METRICS      = 512; // ver métricas/tableros — reservado (P2D-4)
    // P2D-3: consumidor de INTEGRACIÓN del handoff (SI-4, vía `PurchasingIntegrationApi`). Mínimo privilegio:
    // un perfil técnico dedicado sólo necesita este bit (+ entidades); NO es Super-Admin ni ve solicitudes.
    public const RIGHT_INTEGRATION       = 1024;

    /**
     * Estado de DOMINIO (snapshot/cache). La fuente de verdad de estados será `companyworkflow`
     * (P2D-2). En P2D-1 sólo existen el inicial DRAFT y un marcador PENDING tras enviar el borrador.
     */
    public const STATE_DRAFT   = 'DRAFT';   // editable
    public const STATE_PENDING = 'PENDING'; // ya numerada; en P2D-2 la instancia del motor toma la autoridad

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_requests';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Purchase request', 'Purchase requests', $nb, 'companypurchasing');
    }

    /** ¿La solicitud es editable como borrador? */
    public function isDraft(): bool
    {
        return (string) ($this->fields['domain_state'] ?? self::STATE_DRAFT) === self::STATE_DRAFT;
    }

    /**
     * Bits de derecho disponibles para el formulario de perfiles de GLPI.
     *
     * @param bool $interface
     * @return array<int,string>
     */
    public function getRights($interface = 'central')
    {
        return [
            READ                         => __('Read'),
            self::RIGHT_CREATE_REQUEST   => __('Create purchase request', 'companypurchasing'),
            self::RIGHT_VIEW_OWN         => __('View own requests', 'companypurchasing'),
            self::RIGHT_VIEW_ENTITY      => __('View entity requests', 'companypurchasing'),
            self::RIGHT_EDIT_DRAFT       => __('Edit draft request', 'companypurchasing'),
            self::RIGHT_MANAGE_CONFIG    => __('Manage purchasing configuration', 'companypurchasing'),
            self::RIGHT_MANAGE_PURCHASING => __('Manage purchasing (quotes/selection)', 'companypurchasing'),
            self::RIGHT_RECEIVE          => __('Receive goods', 'companypurchasing'),
            self::RIGHT_DELIVER          => __('Deliver goods', 'companypurchasing'),
            self::RIGHT_VIEW_METRICS     => __('View purchasing metrics', 'companypurchasing'),
            self::RIGHT_INTEGRATION      => __('Consume inventory handoff (integration)', 'companypurchasing'),
        ];
    }
}
