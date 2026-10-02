<?php

/**
 * Utilidades COMUNES de los controladores delgados de la UI (P2D-4; ADR-0023 §8): URLs internas, contexto del layout
 * NATIVO (título + menú), PRG (POST → redirect → GET) con mensajes nativos (`Session::addMessageAfterRedirect`).
 *
 * Los controladores sólo traducen HTTP ↔ servicios de dominio: no validan reglas de negocio ni deciden estados.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Controller;

use Session;
use GlpiPlugin\Companypurchasing\Menu;
use GlpiPlugin\Companypurchasing\Service\SafeError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

trait UiSupport
{
    /** URL interna del plugin (siempre construida en el servidor; nunca a partir de parámetros del navegador). */
    protected static function url(string $path = ''): string
    {
        global $CFG_GLPI;
        return (string) ($CFG_GLPI['root_doc'] ?? '') . Menu::BASE . $path;
    }

    /**
     * Parámetros comunes del layout nativo (`layout/page_without_tabs.html.twig` ⇒ `Html::header()`).
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    protected static function page(string $title, string $option, array $params = []): array
    {
        return $params + [
            'title'  => $title,
            'menu'   => ['management', strtolower(Menu::class), $option],
            'base'   => self::url(),
            'nav'    => Menu::pages(),
            'active' => $option,
            'lang'   => (string) ($_SESSION['glpilanguage'] ?? 'es_ES'),
        ];
    }

    /**
     * PRG: ejecuta la acción AUTORIZADA, deja el mensaje NATIVO (éxito o rechazo funcional seguro) y redirige con GET.
     * Un rechazo HTTP tipado (403/404, p. ej. el preflight de derecho) NUNCA se convierte en PRG: se propaga.
     */
    protected function act(string $redirectPath, callable $fn): Response
    {
        try {
            $result = $fn();
            if (is_array($result)) {
                [$message, $redirectPath] = [$result[0], $result[1] ?? $redirectPath];
            } else {
                $message = (string) $result;
            }
            if ($message !== '') {
                Session::addMessageAfterRedirect(htmlescape($message), false, INFO);
            }
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Toolbox::logInFile('companypurchasing', sprintf("UI action failed (%s): %s\n", get_class($e), \GlpiPlugin\Companypurchasing\Api\PurchasingIntegrationApi::sanitizeError($e->getMessage())));
            Session::addMessageAfterRedirect(htmlescape(SafeError::userMessage($e)), false, ERROR);
        }
        return new RedirectResponse(self::url($redirectPath));
    }
}
