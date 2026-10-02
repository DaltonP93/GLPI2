<?php

/**
 * Guarda de MÉTODO HTTP para las acciones de la UI de Compras (P2D-4; ADR-0023 §8).
 *
 * Todas las acciones (`ActionController`) son POST-only. En GLPI 11.0.8 el router de plugins
 * (`PluginsRouterListener`) sólo captura `ResourceNotFoundException`: un GET sobre una ruta POST-only deja escapar
 * `MethodNotAllowedException` y GLPI responde **500** (con un log CRITICAL), aunque el controlador nunca se ejecuta.
 * Sin tocar el core, esta ruta GET/HEAD sobre EXACTAMENTE las mismas rutas responde el **405** nativo de Symfony
 * (`Allow: POST`). No lee ni muta nada.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class MethodGuardController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/{path}', name: 'cpur_method_guard', methods: ['GET', 'HEAD'], requirements: ['path' => ActionController::POST_ONLY_PATHS])]
    public function __invoke(): Response
    {
        throw new MethodNotAllowedHttpException(['POST']);
    }
}
