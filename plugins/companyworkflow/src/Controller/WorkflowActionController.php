<?php

/**
 * Controlador DELGADO de acciones de transición.
 *
 *  POST /plugins/companyworkflow/instance/{id}/{action}  -> AUTHENTICATED.
 *  El firewall de GLPI exige login y valida CSRF de forma nativa (NO llamar a Session::checkCSRF
 *  aquí: en GLPI 11 el CheckCsrfListener ya lo valida y consumiría el token dos veces).
 *  La autorización real (ACL/entidad/quórum/versión) la aplica el Engine (fail-closed).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Companyworkflow\Api\WorkflowApi;
use GlpiPlugin\Companyworkflow\Model\Instance;
use GlpiPlugin\Companyworkflow\Service\TransitionResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class WorkflowActionController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/instance/{id}/{action}', name: 'companyworkflow_action', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function act(int $id, string $action, Request $request): Response
    {
        $instance = new Instance();
        if (!$instance->getFromDB($id)) {
            return new JsonResponse(['ok' => false, 'code' => 'not_found'], 404);
        }

        $ctx = [
            'comment' => (string) $request->request->get('comment', ''),
        ];
        if ($request->request->has('expected_lock_version')) {
            $ctx['expected_lock_version'] = (int) $request->request->get('expected_lock_version');
        }

        $result = (new WorkflowApi())->transition($instance, $action, $ctx);

        return new JsonResponse([
            'ok'      => $result->success,
            'code'    => $result->code,
            'message' => $result->message,
            'data'    => $result->data,
        ], $this->httpStatusFor($result));
    }

    private function httpStatusFor(TransitionResult $result): int
    {
        if ($result->success) {
            return 200;
        }
        return match ($result->code) {
            TransitionResult::DENIED_ACL, TransitionResult::DENIED_ENTITY => 403,
            TransitionResult::INVALID_ACTION, TransitionResult::CLOSED     => 404,
            TransitionResult::CONFLICT_VERSION, TransitionResult::DUPLICATE => 409,
            TransitionResult::COMMENT_REQUIRED, TransitionResult::CONDITION_FAILED => 422,
            default => 400,
        };
    }
}
