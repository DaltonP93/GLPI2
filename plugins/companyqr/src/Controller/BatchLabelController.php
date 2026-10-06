<?php

/**
 * Controlador del PDF de un LOTE de etiquetas (impresión masiva, ADR-0024).
 *
 *  - GET /plugins/companyqr/labels/{batch} -> AUTHENTICATED + derecho `print`.
 *    El lote lo arma la Acción masiva nativa "Imprimir etiquetas QR" (`Code::processMassiveActionsForOneItemtype`)
 *    en la sesión del usuario. Cada código se REVALIDA al imprimir (`BulkLabelService::render`).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Companyqr\Model\Code;
use GlpiPlugin\Companyqr\Service\BulkLabelService;
use GlpiPlugin\Companyqr\Service\LabelBatch;
use Session;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

final class BatchLabelController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/labels/{batch}', name: 'companyqr_labels_batch', methods: ['GET'], requirements: ['batch' => '[a-f0-9]{32}'])]
    public function pdf(string $batch): Response
    {
        if (!Session::haveRight(Code::$rightname, Code::RIGHT_PRINT)) {
            throw new AccessDeniedHttpException();
        }

        $store = $_SESSION[LabelBatch::SESSION_KEY] ?? [];
        $codeIds = LabelBatch::get(is_array($store) ? $store : [], $batch, (int) (Session::getLoginUserID() ?: 0), time());
        if ($codeIds === null) {
            // Inexistente, vencido o de otro usuario: misma respuesta (no se distingue).
            throw new NotFoundHttpException();
        }

        $result = (new BulkLabelService())->render($codeIds);
        if ($result['pdf'] === null) {
            // Ningún código sigue siendo imprimible (revocado, suspendido o sin acceso desde que se armó el lote).
            throw new NotFoundHttpException();
        }

        return new Response($result['pdf'], 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="companyqr-labels-' . date('Ymd-Hi') . '.pdf"',
            'Cache-Control'       => 'no-store',
        ]);
    }
}
