<?php

/**
 * Controlador de administración de códigos: generar / rotar / revocar.
 *
 *  - POST /plugins/companyqr/admin/{action} -> AUTHENTICATED + derecho `generate` + CSRF.
 *    action ∈ {generate, rotate, revoke}.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Controller;

use CommonDBTM;
use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Companyqr\Model\Code;
use GlpiPlugin\Companyqr\Service\AccessPolicyService;
use GlpiPlugin\Companyqr\Service\CodeManager;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Annotation\Route;

final class AdminController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/admin/{action}', name: 'companyqr_admin', methods: ['POST'], requirements: ['action' => 'generate|rotate|revoke'])]
    public function __invoke(string $action, Request $request): Response
    {
        if (!Session::haveRight('plugin_companyqr', Code::RIGHT_GENERATE)) {
            throw new AccessDeniedHttpException();
        }
        if (method_exists(Session::class, 'checkCSRF')) {
            Session::checkCSRF($request->request->all());
        }

        $manager = new CodeManager();
        $back = (string) $request->request->get('_back', $this->home());

        switch ($action) {
            case 'generate':
                $itemtype = (string) $request->request->get('itemtype', '');
                $itemsId  = (int) $request->request->get('items_id', 0);
                if ($itemtype !== '' && is_a($itemtype, CommonDBTM::class, true) && $itemsId > 0) {
                    /** @var CommonDBTM $item */
                    $item = new $itemtype();
                    if ($item->getFromDB($itemsId) && $item->canViewItem()) {
                        $manager->getOrCreateForItem($item);
                    }
                }
                break;

            case 'rotate':
            case 'revoke':
                $codeId = (int) $request->request->get('code_id', 0);
                $code = new Code();
                if ($codeId <= 0 || !$code->getFromDB($codeId)) {
                    break;
                }
                // *** ACL NATIVA sobre el ACTIVO referenciado (no basta el bit generate). ***
                // Un técnico de la entidad A no puede rotar/revocar un código de la entidad B.
                if (!(new AccessPolicyService())->canMutateCode($code)) {
                    throw new AccessDeniedHttpException();
                }
                if ($action === 'rotate') {
                    $manager->rotate($code);
                } else {
                    $manager->revoke($code, (string) $request->request->get('reason', ''));
                }
                break;
        }

        return new RedirectResponse($back);
    }

    private function home(): string
    {
        global $CFG_GLPI;
        return (string) ($CFG_GLPI['root_doc'] ?? '/') . '/front/central.php';
    }
}
