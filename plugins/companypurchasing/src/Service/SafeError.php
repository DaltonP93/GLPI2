<?php

/**
 * Mensaje de error SEGURO para el usuario (P2D-4; ADR-0023 §8): los rechazos controlados se traducen (i18n); los
 * errores técnicos (SQL, I/O, clases internas) NUNCA se muestran (mensaje genérico + registro en el log nativo).
 * PURO salvo `Labels` (traducción).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use GlpiPlugin\Companypurchasing\Api\PurchasingIntegrationApi;

final class SafeError
{
    /** Indicadores de un error TÉCNICO (no mostrable). */
    private const TECHNICAL = ['sql', 'mysql', 'mariadb', 'query', 'pdo', 'mysqli', 'stack trace', 'exception', '.php', 'syntax', 'deadlock', 'lock wait'];

    public static function isTechnical(string $message): bool
    {
        $m = strtolower($message);
        foreach (self::TECHNICAL as $needle) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** Mensaje para el usuario (escapado luego por Twig/GLPI): nunca SQL ni detalles internos. */
    public static function userMessage(\Throwable $e): string
    {
        if ($e instanceof DeliveryException) {
            return Labels::deliveryError($e->kind);
        }
        $msg = trim($e->getMessage());
        if ($msg === '' || self::isTechnical($msg) || !($e instanceof \RuntimeException || $e instanceof \InvalidArgumentException)) {
            return __('The action could not be completed', 'companypurchasing');
        }
        return sprintf(__('The action could not be completed: %s', 'companypurchasing'), PurchasingIntegrationApi::sanitizeError($msg));
    }
}
