<?php

/**
 * Controlador de etiqueta PDF.
 *
 *  - GET /plugins/companyqr/label/{code_id} -> AUTHENTICATED + derecho `print`.
 *    Genera el PDF individual 70,75 × 24 mm de un código.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Companyqr\Model\Code;
use GlpiPlugin\Companyqr\Service\LabelRenderer;
use GlpiPlugin\Companyqr\Service\PluginConfig;
use Session;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

final class LabelController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/label/{code_id}', name: 'companyqr_label', methods: ['GET'], requirements: ['code_id' => '\d+'])]
    public function pdf(int $code_id): Response
    {
        if (!Session::haveRight('plugin_companyqr', Code::RIGHT_PRINT)) {
            throw new AccessDeniedHttpException();
        }

        $code = new Code();
        if (!$code->getFromDB($code_id)) {
            throw new NotFoundHttpException();
        }

        // El activo referenciado debe ser visible por ACL para poder imprimir.
        $itemtype = (string) $code->fields['itemtype'];
        $item = new $itemtype();
        if (!$item->getFromDB((int) $code->fields['items_id']) || !$item->canViewItem()) {
            throw new AccessDeniedHttpException();
        }

        $pdf = (new LabelRenderer())->pdf([
            'public_code' => (string) $code->fields['public_code'],
            'type'        => $item->getTypeName(1),
            'qr_data'     => $this->scanUrl((string) $code->fields['token']),
            'header'      => (string) PluginConfig::get('label_header', 'TI • ACTIVOS'),
            'org'         => (int) PluginConfig::get('label_show_org', '0') === 1
                ? \Dropdown::getDropdownName('glpi_entities', (int) $code->fields['entities_id'])
                : null,
        ]);

        return new Response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="companyqr-' . $code->fields['public_code'] . '.pdf"',
        ]);
    }

    private function scanUrl(string $token): string
    {
        global $CFG_GLPI;
        $base = (string) ($CFG_GLPI['url_base'] ?? '');
        return $base . '/plugins/companyqr/scan/' . rawurlencode($token);
    }
}
