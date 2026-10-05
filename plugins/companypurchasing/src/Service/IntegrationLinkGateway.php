<?php

/**
 * Puerta de SÓLO LECTURA hacia la API pública `companyintegrations` (`InventoryLinkApi`) para mostrar en la UI la fase
 * de integración y —si la sesión puede verlo con la ACL nativa— el activo GLPI y su `public_code` (P2D-4; ADR-0023).
 * Compras NO lee tablas de companyintegrations. Sin el plugin (o sin la API) ⇒ sin datos (la UI muestra sólo el outbox
 * propio).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class IntegrationLinkGateway
{
    private const API = 'GlpiPlugin\\Companyintegrations\\Api\\InventoryLinkApi';

    public function available(): bool
    {
        return class_exists(self::API) && (!class_exists(\Plugin::class) || \Plugin::isPluginActive('companyintegrations'));
    }

    /**
     * @param array<int,string> $uuids
     * @return array<string,array{phase:string, itemtype:?string, items_id:?int, public_code:?string}>
     */
    public function forUnits(array $uuids): array
    {
        if ($uuids === [] || !$this->available()) {
            return [];
        }
        try {
            $api = self::API;
            return (array) (new $api())->forUnits(array_values($uuids));
        } catch (\Throwable) {
            return []; // la UI degrada a "sin datos de integración"; nunca rompe la página
        }
    }
}
