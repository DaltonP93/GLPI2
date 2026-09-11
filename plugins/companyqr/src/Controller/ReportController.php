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
        // CSRF nativo (si el método existe en esta versión de GLPI).
        if (method_exists(Session::class, 'checkCSRF')) {
            Session::checkCSRF($request->request->all());
        }

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
        $limiter = new RateLimiter();
        $max = (int) PluginConfig::get('anon_rate_max', '5');
        $ttl = (int) PluginConfig::get('anon_rate_window_seconds', '900');
        if (!$limiter->allow('report:' . $token, $max, $ttl)) {
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
        if ($payload === '') {
            return false;
        }
        // Verificación con el AltchaManager nativo si está disponible.
        if (class_exists(\Glpi\Altcha\AltchaManager::class)
            && method_exists(\Glpi\Altcha\AltchaManager::class, 'verifySolution')) {
            try {
                return (bool) \Glpi\Altcha\AltchaManager::verifySolution($payload);
            } catch (\Throwable) {
                return false;
            }
        }
        return false;
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
