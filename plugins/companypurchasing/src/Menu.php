<?php

/**
 * Entrada de menú NATIVA "Compras" (P2D-4) bajo *Gestión*, registrada con `$PLUGIN_HOOKS['menu_toadd']`
 * (`Html::generateMenuSession()` llama `getMenuContent()`). Cada sub-entrada sólo aparece con su derecho; la
 * autorización REAL se vuelve a aplicar en cada controlador/servicio (el menú no es un control de acceso).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing;

use CommonGLPI;
use Session;
use GlpiPlugin\Companypurchasing\Model\Request;

final class Menu extends CommonGLPI
{
    public const BASE = '/plugins/companypurchasing';

    public static function getTypeName($nb = 0)
    {
        return __('Purchasing', 'companypurchasing');
    }

    public static function getMenuName()
    {
        return __('Purchasing', 'companypurchasing');
    }

    public static function getIcon()
    {
        return 'ti ti-shopping-cart';
    }

    public static function canView(): bool
    {
        return self::pages() !== [];
    }

    /** Sub-páginas visibles según los derechos de la sesión. @return array<string,array{title:string, page:string, icon:string}> */
    public static function pages(): array
    {
        $has = static fn (int $bit): bool => (bool) Session::haveRight(Request::$rightname, $bit);
        $out = [];
        if ($has(Request::RIGHT_VIEW_OWN) || $has(Request::RIGHT_VIEW_ENTITY) || $has(Request::RIGHT_CREATE_REQUEST)) {
            $out['requests'] = ['title' => __('My requests', 'companypurchasing'), 'page' => self::BASE . '/requests', 'icon' => 'ti ti-list'];
        }
        $out['inbox'] = ['title' => __('Approvals inbox', 'companypurchasing'), 'page' => self::BASE . '/inbox/approvals', 'icon' => 'ti ti-inbox'];
        if ($has(Request::RIGHT_MANAGE_PURCHASING)) {
            $out['purchasing'] = ['title' => __('Purchasing management', 'companypurchasing'), 'page' => self::BASE . '/inbox/purchasing', 'icon' => 'ti ti-file-invoice'];
        }
        if ($has(Request::RIGHT_RECEIVE)) {
            $out['receiving'] = ['title' => __('Reception', 'companypurchasing'), 'page' => self::BASE . '/inbox/receiving', 'icon' => 'ti ti-package-import'];
        }
        if ($has(Request::RIGHT_DELIVER)) {
            $out['delivery'] = ['title' => __('Delivery', 'companypurchasing'), 'page' => self::BASE . '/inbox/delivery', 'icon' => 'ti ti-truck-delivery'];
        }
        if ($has(Request::RIGHT_VIEW_METRICS)) {
            $out['metrics'] = ['title' => __('Metrics', 'companypurchasing'), 'page' => self::BASE . '/metrics', 'icon' => 'ti ti-chart-bar'];
        }
        if ($has(Request::RIGHT_MANAGE_CONFIG)) {
            $out['config'] = ['title' => __('Configuration', 'companypurchasing'), 'page' => self::BASE . '/config', 'icon' => 'ti ti-settings'];
        }
        // Sin ningún derecho de Compras: sólo la bandeja (que el motor deja vacía) NO justifica mostrar el menú.
        return count($out) === 1 && isset($out['inbox']) && !Session::haveRight('plugin_companyworkflow', 2) ? [] : $out;
    }

    public static function getMenuContent()
    {
        $pages = self::pages();
        if ($pages === []) {
            return false;
        }
        $first = reset($pages);
        $menu = [
            'title' => self::getMenuName(),
            'page'  => $first['page'],
            'icon'  => self::getIcon(),
            'options' => [],
        ];
        foreach ($pages as $key => $p) {
            $menu['options'][$key] = ['title' => $p['title'], 'page' => $p['page'], 'icon' => $p['icon'], 'links' => ['search' => $p['page']]];
        }
        return $menu;
    }
}
