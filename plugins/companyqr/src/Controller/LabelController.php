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
use GlpiPlugin\Companyqr\Service\LabelComposer;
use GlpiPlugin\Companyqr\Service\LabelRenderer;
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

        // Misma etiqueta que `CompanyQrApi::renderLabelPdf()`: el contenido (y la URL del QR) lo arma `LabelComposer`.
        $pdf = (new LabelRenderer())->pdf(LabelComposer::spec($code, $item));

        return new Response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="companyqr-' . $code->fields['public_code'] . '.pdf"',
        ]);
    }
}
