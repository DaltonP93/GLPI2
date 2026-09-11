<?php

/**
 * Controlador de reporte de problema -> ticket vinculado al activo.
 *
 *  - POST /plugins/companyqr/scan/{token}/report    -> AUTHENTICATED (solicitante = sesión).
 *  - POST /plugins/companyqr/public/{token}/report   -> NO_CHECK (anónimo), sólo si
 *      anonymous_enabled=1, con rate limit + Altcha (ambos). Si está apagado -> 404.
 *
 * v1 usa formulario mínimo propio (ver companyqr-forms-spike.md); el gate de Forms
 * nativo corre como test de integración en CI.
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
use GlpiPlugin\Companyqr\Service\PluginConfig;
use GlpiPlugin\Companyqr\Service\RateLimiter;
use GlpiPlugin\Companyqr\Service\TicketCreator;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ReportController extends AbstractController
{
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/scan/{token}/report', name: 'companyqr_report', methods: ['POST'])]
    public function authenticated(string $token, Request $request): Response
    {
        // CSRF: lo valida AUTOMÁTICAMENTE el kernel de GLPI 11 (CheckCsrfListener) para
        // los POST; NO se revalida aquí (hacerlo consumía el token dos veces -> 403).
        // La ficha incluye el campo _glpi_csrf_token que el listener verifica.
        $policy = new AccessPolicyService();
        $outcome = $policy->resolveAuthenticated($token);
        if ($outcome['result'] !== Scan::RESULT_RESOLVED) {
            (new AuditService())->record($outcome['result'], $outcome['code'] ?? null);
            return new Response('', 404);
        }

        $ticketId = (new TicketCreator())->createForAsset($outcome['item'], [
            'title'              => $this->title($request, $outcome['view']['public_code'] ?? ''),
            'content'            => (string) $request->request->get('content', ''),
            'requester_users_id' => (int) (Session::getLoginUserID() ?: 0),
        ]);

        (new AuditService())->record(
            $ticketId > 0 ? Scan::RESULT_REPORT_CREATED : Scan::RESULT_DENIED,
            $outcome['code'] ?? null,
            ['tickets_id' => $ticketId]
        );

        return new RedirectResponse($this->scanUrl($token) . '?reported=' . ($ticketId > 0 ? '1' : '0'));
    }

    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    #[Route('/public/{token}/report', name: 'companyqr_public_report', methods: ['POST'])]
    public function anonymous(string $token, Request $request): Response
    {
        if (!PluginConfig::anonymousEnabled()) {
            return new Response('', 404);
        }

        // Anti-abuso: rate limit + Altcha (ambos son obligatorios en anónimo).
        // Bucket POR ACTOR (no global por activo): HMAC(ip|token) SOLO en cache (no persiste IP).
        // Un atacante no puede así agotar el cupo de un activo para el resto de usuarios.
        $limiter = new RateLimiter();
        $max = (int) PluginConfig::get('anon_rate_max', '5');
        $ttl = (int) PluginConfig::get('anon_rate_window_seconds', '900');
        $actor = hash('sha256', ($request->getClientIp() ?? '') . '|' . $token . '|companyqr');
        // Fail-open documentado: si no hay backend de cache, el rate limit no bloquea;
        // el anti-bot primario (Altcha) sigue siendo obligatorio (ver RateLimiter).
        if (!$limiter->allow('report:' . $actor, $max, $ttl)) {
            return new Response('', 429);
        }
        if (!$this->altchaValid((string) $request->request->get('altcha', ''))) {
            return new Response('', 400);
        }

        $policy = new AccessPolicyService();
        $outcome = $policy->resolveAnonymous($token);
        if ($outcome['result'] !== Scan::RESULT_RESOLVED) {
            return new Response('', 404);
        }

        $ticketId = (new TicketCreator())->createForAsset($outcome['item'], [
            'title'     => $this->title($request, $outcome['view']['public_code'] ?? ''),
            'content'   => (string) $request->request->get('content', ''),
            'anonymous' => true,
        ]);

        (new AuditService())->record(
            $ticketId > 0 ? Scan::RESULT_REPORT_CREATED : Scan::RESULT_DENIED,
            $outcome['code'] ?? null,
            ['is_anonymous' => true, 'tickets_id' => $ticketId]
        );

        return new RedirectResponse($this->publicUrl($token) . '?reported=' . ($ticketId > 0 ? '1' : '0'));
    }

    private function title(Request $request, string $publicCode): string
    {
        $t = trim((string) $request->request->get('title', ''));
        if ($t !== '') {
            return $t;
        }
        return sprintf(__('Problem reported via QR (%s)', 'companyqr'), $publicCode);
    }

    private function altchaValid(string $payload): bool
    {
        return (new \GlpiPlugin\Companyqr\Service\AltchaVerifier())->isValid($payload);
    }

    private function scanUrl(string $token): string
    {
        global $CFG_GLPI;
        return ($CFG_GLPI['root_doc'] ?? '') . '/plugins/companyqr/scan/' . rawurlencode($token);
    }

    private function publicUrl(string $token): string
    {
        global $CFG_GLPI;
        return ($CFG_GLPI['root_doc'] ?? '') . '/plugins/companyqr/public/' . rawurlencode($token);
    }
}
