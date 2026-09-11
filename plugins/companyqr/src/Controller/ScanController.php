<?php

/**
 * Controlador de escaneo.
 *
 *  - GET /plugins/companyqr/scan/{token}   -> AUTHENTICATED (ruta estándar, la del QR).
 *      El firewall de GLPI exige login y preserva el retorno. Con sesión, la ACL nativa
 *      decide qué se muestra.
 *  - GET /plugins/companyqr/public/{token} -> NO_CHECK (modo anónimo), sólo si
 *      anonymous_enabled=1. Devuelve subset mínimo. Si está apagado -> 404.
 *
 * Controlador DELGADO: parsea, llama a AccessPolicyService y devuelve la vista.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Companyqr\Model\Scan;
use GlpiPlugin\Companyqr\Service\AccessPolicyService;
use GlpiPlugin\Companyqr\Service\AuditService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ScanController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/scan/{token}', name: 'companyqr_scan', methods: ['GET'])]
    public function scan(string $token): Response
    {
        $policy = new AccessPolicyService();
        $outcome = $policy->resolveAuthenticated($token);

        (new AuditService())->record(
            $outcome['result'],
            $outcome['code'] ?? null,
            ['is_anonymous' => false]
        );

        $status = $outcome['result'] === Scan::RESULT_RESOLVED ? 200 : 404;

        $csrf = method_exists(\Session::class, 'getNewCSRFToken')
            ? \Session::getNewCSRFToken()
            : '';

        return $this->render('@companyqr/fiche.html.twig', [
            'anonymous' => false,
            'result'    => $outcome['result'],
            'view'      => $outcome['view'] ?? [],
            'token'     => $token,
            'csrf'      => $csrf,
            'lang'      => substr((string) ($_SESSION['glpilanguage'] ?? 'es_ES'), 0, 2),
        ], new Response('', $status));
    }

    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    #[Route('/public/{token}', name: 'companyqr_public', methods: ['GET'])]
    public function public(string $token): Response
    {
        $policy = new AccessPolicyService();
        $outcome = $policy->resolveAnonymous($token);

        // Modo anónimo apagado: no filtra nada, exige login.
        if ($outcome['result'] === Scan::RESULT_LOGIN_REQUIRED) {
            (new AuditService())->record(Scan::RESULT_LOGIN_REQUIRED, null, ['is_anonymous' => true]);
            return new Response('', 404);
        }

        (new AuditService())->record(
            $outcome['result'],
            $outcome['code'] ?? null,
            ['is_anonymous' => true]
        );

        $status = $outcome['result'] === Scan::RESULT_RESOLVED ? 200 : 404;

        return $this->render('@companyqr/fiche.html.twig', [
            'anonymous' => true,
            'result'    => $outcome['result'],
            'view'      => $outcome['view'] ?? [],
            'token'     => $token,
        ], new Response('', $status));
    }
}
