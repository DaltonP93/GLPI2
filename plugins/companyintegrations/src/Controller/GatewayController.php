<?php

/**
 * Gateway QR por asset tag (SI-1).
 *
 *  GET /plugins/companyintegrations/asset/{asset_tag}  -> AUTHENTICATED.
 *  El firewall de GLPI exige login; luego la ACL NATIVA del activo GLPI decide (multi-entidad).
 *  "el asset tag identifica; GLPI autoriza" — el tag NO otorga acceso por sí mismo.
 *
 *  Resuelve tag (actual o histórico) → puente → activo GLPI, verifica canViewItem() y entrega la
 *  referencia (integración con la ficha de companyqr como follow-up). NO escribe nada.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Controller;

use CommonDBTM;
use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Companyintegrations\Service\AssetResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class GatewayController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/asset/{asset_tag}', name: 'companyintegrations_gateway', methods: ['GET'])]
    public function asset(string $asset_tag): Response
    {
        $bridge = (new AssetResolver())->resolveByTag($asset_tag);
        if ($bridge === null) {
            return new JsonResponse(['ok' => false, 'reason' => 'unknown_tag'], 404);
        }

        $itemtype = (string) ($bridge->fields['glpi_itemtype'] ?? '');
        $itemsId  = (int) ($bridge->fields['glpi_items_id'] ?? 0);

        if ($itemtype === '' || !class_exists($itemtype) || !is_subclass_of($itemtype, CommonDBTM::class)) {
            return new JsonResponse(['ok' => false, 'reason' => 'unresolved'], 404);
        }

        /** @var CommonDBTM $item */
        $item = new $itemtype();
        if (!$item->getFromDB($itemsId)) {
            return new JsonResponse(['ok' => false, 'reason' => 'not_found'], 404);
        }

        // AUTORIZACIÓN NATIVA (multi-entidad): el tag no autoriza; GLPI decide.
        if (!$item->canViewItem()) {
            return new JsonResponse(['ok' => false, 'reason' => 'forbidden'], 403);
        }

        // Entrega la referencia resuelta (la ficha segura companyqr es el paso de integración).
        return new JsonResponse([
            'ok'        => true,
            'itemtype'  => $itemtype,
            'items_id'  => $itemsId,
            'entity'    => (int) ($bridge->fields['glpi_entity_id'] ?? 0),
            'asset_tag' => (string) ($bridge->fields['snipe_asset_tag'] ?? ''),
        ], 200);
    }
}
