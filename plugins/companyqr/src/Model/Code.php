<?php

/**
 * Modelo del código QR por activo (tabla propia glpi_plugin_companyqr_codes).
 *
 * REGLA 0: tabla PROPIA del plugin (prefijo glpi_plugin_companyqr_). No es una
 * tabla del core. La visibilidad de datos del activo NO depende de este modelo,
 * sino de la ACL nativa de GLPI sobre el activo referenciado.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Model;

use CommonDBTM;

class Code extends CommonDBTM
{
    /** Derecho de plugin (bits definidos abajo). */
    public static $rightname = 'plugin_companyqr';

    /** Estados posibles del código. */
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REVOKED   = 'revoked';

    /**
     * Bits del derecho `plugin_companyqr`. READ es el bit estándar (1).
     * Los demás son bits propios del plugin (no colisionan porque el rightname es nuestro).
     */
    public const RIGHT_GENERATE = 2; // crear / rotar / revocar códigos
    public const RIGHT_PRINT    = 4; // imprimir etiquetas
    public const RIGHT_CONFIG   = 8; // configurar el plugin

    /**
     * Nombre de tabla EXPLÍCITO (evita depender de la derivación de nombres de
     * GLPI para clases con namespace de plugin).
     */
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyqr_codes';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('QR code', 'QR codes', $nb, 'companyqr');
    }

    /**
     * Bits de derecho disponibles para el formulario de perfiles de GLPI.
     *
     * @param bool $interface
     * @return array<int,string|array>
     */
    public function getRights($interface = 'central')
    {
        return [
            READ                 => __('Read'),
            self::RIGHT_GENERATE => __('Generate / rotate / revoke codes', 'companyqr'),
            self::RIGHT_PRINT    => __('Print labels', 'companyqr'),
            self::RIGHT_CONFIG   => __('Configure plugin', 'companyqr'),
        ];
    }

    /** ¿El código resuelve a un activo (está activo)? */
    public function isActive(): bool
    {
        return ($this->fields['status'] ?? null) === self::STATUS_ACTIVE;
    }
}
