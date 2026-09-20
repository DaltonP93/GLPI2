<?php

/**
 * Controlador DELGADO de verificación de evidencia.
 *
 *  GET /plugins/companysignature/verify/{token}  -> AUTHENTICATED.
 *  El firewall de GLPI exige login de forma nativa. La autorización real (ACL `RIGHT_VERIFY` +
 *  multi-entidad + no fuga de datos) la aplica `VerificationService` (fail-closed). NO hay
 *  verificación anónima/pública en v1 (gate §7/§13).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Companysignature\Service\VerificationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class VerifyController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/verify/{token}', name: 'companysignature_verify', methods: ['GET'], requirements: ['token' => '[a-z2-7]+'])]
    public function verify(string $token, Request $request): Response
    {
        $result = (new VerificationService())->verify($token);

        $status = match ($result['status']) {
            VerificationService::STATUS_VALID       => 200,
            VerificationService::STATUS_INVALIDATED => 200,
            VerificationService::STATUS_TAMPERED    => 409,
            VerificationService::STATUS_DENIED      => 403,
            default                                 => 404, // not_found (no filtra existencia)
        };

        return new JsonResponse([
            'status'   => $result['status'],
            'evidence' => $result['evidence'],
        ], $status);
    }
}
