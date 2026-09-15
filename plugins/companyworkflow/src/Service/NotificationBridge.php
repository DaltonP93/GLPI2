<?php

/**
 * Puente de notificaciones. Emite el evento de dominio `companyworkflow:transitioned` (hook
 * soportado) para que otros plugins (companypurchasing, companysignature, webhooks) reaccionen,
 * y calcula los destinatarios (siguientes aprobadores + solicitante).
 *
 * v1: el cableado de PLANTILLAS/targets de correo nativo se difiere a un follow-up (ver README,
 * "Limitaciones/deuda técnica"); aquí se emite el evento y se registran los destinatarios. No se
 * construye un motor de correo propio (Regla: usar el nativo).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Service;

final class NotificationBridge
{
    /**
     * Emite el evento de transición y devuelve los destinatarios calculados.
     *
     * @param array<string,mixed> $payload  from/to/actor/instance/recipients...
     * @return array<int,int> ids de usuarios destinatarios
     */
    public function notifyTransition(array $payload): array
    {
        $recipients = array_values(array_unique(array_map('intval', $payload['recipients'] ?? [])));

        if (!PluginConfig::boolean('notifications_enabled')) {
            return $recipients;
        }

        // Evento de dominio (hook soportado) — nunca edita el core.
        if (class_exists('Plugin') && method_exists('Plugin', 'doHookFunction')) {
            try {
                \Plugin::doHookFunction('companyworkflow:transitioned', $payload);
            } catch (\Throwable) {
                // best-effort: una notificación no debe tumbar la transición ya confirmada.
            }
        }

        return $recipients;
    }
}
