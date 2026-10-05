<?php

/**
 * Dispara notificaciones NATIVAS (`NotificationEvent::raiseEvent`) como CONSECUENCIA de hechos DURABLES del ledger
 * de companyworkflow (P2D-4; ADR-0023 §9).
 *
 *   - En vivo: el listener de `companyworkflow:transitioned` (post-COMMIT del motor) llama `dispatchHistory()`.
 *   - Recuperación: la Acción automática recorre el ledger desde un cursor (`sweep()`); install() lo arranca en
 *     "ahora" para no notificar hechos históricos.
 *   - Deduplicación DURABLE: `notify:<workflow_history_id>:<evento>` en la auditoría append-only (UNIQUE) ⇒ como
 *     mucho UNA notificación por hecho y evento, aunque el listener y la Acción automática coincidan.
 *   - Un fallo del envío NUNCA revierte nada: se registra `notification.failed` y se sigue.
 *   - Sin mailer, cola ni SMTP propios: `raiseEvent` encola en `QueuedNotification` (nativo).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use Config;
use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;
use GlpiPlugin\Companypurchasing\Model\Request;

final class NotificationDispatcher
{
    private WorkflowGateway $wf;
    private Audit $audit;
    /** @var callable(string, Request, array<string,mixed>): bool */
    private $raiser;

    /** @param (callable(string, Request, array<string,mixed>): bool)|null $raiser  inyectable en tests */
    public function __construct(?WorkflowGateway $wf = null, ?Audit $audit = null, ?callable $raiser = null)
    {
        $this->wf     = $wf ?? new WorkflowGateway();
        $this->audit  = $audit ?? new Audit();
        $this->raiser = $raiser ?? static fn (string $event, Request $req, array $options): bool
            => (bool) \NotificationEvent::raiseEvent($event, $req, $options);
    }

    /** ¿Notificaciones habilitadas? (configuración NATIVA `use_notifications` + interruptor del plugin) */
    public static function enabled(): bool
    {
        global $CFG_GLPI;
        return PluginConfig::notificationsEnabled() && (bool) ($CFG_GLPI['use_notifications'] ?? false);
    }

    /**
     * Notifica UN hecho del ledger (idempotente).
     *
     * @param array<string,mixed> $payload  payload del hook en vivo (opcional; sólo se usa `action` si falta en el ledger)
     * @return array<int,string> eventos disparados AHORA
     */
    public function dispatchHistory(int $historyId, array $payload = []): array
    {
        if ($historyId <= 0 || !self::enabled()) {
            return [];
        }
        $row = $this->wf->historyById($historyId);
        if ($row === null || (string) ($row['event'] ?? '') !== NotificationRules::LEDGER_TRANSITIONED) {
            return [];
        }
        $inst = $this->wf->loadInstance((int) ($row['instances_id'] ?? 0));
        if ($inst === null || (string) $inst->fields['itemtype'] !== Request::class) {
            return [];
        }
        $req = new Request();
        if (!$req->getFromDB((int) $inst->fields['items_id'])) {
            return [];
        }
        $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
        $action = (string) (is_array($meta) ? ($meta['action'] ?? '') : '');
        if ($action === '') {
            $action = (string) ($payload['action'] ?? '');
        }
        $to = (string) ($row['to_code'] ?? '');
        $events = NotificationRules::eventsFor((string) $row['event'], (string) ($row['from_code'] ?? ''), $to, $action);
        if ($events === []) {
            return [];
        }
        $stillThere = $this->wf->isOpen($inst) && $this->wf->stateCode($inst) === $to;
        $options = [
            'entities_id'    => (int) $req->fields['entities_id'],
            'actor_users_id' => (int) ($row['actor_users_id'] ?? 0),
            // Aprobadores EFECTIVOS de la etapa según el MOTOR, sólo si la instancia sigue en ella.
            'approvers'      => $stillThere ? $this->wf->currentApprovers($inst) : [],
        ];
        $raised = [];
        foreach ($events as $event) {
            if ($event === NotificationRules::EV_APPROVAL_PENDING && !$stillThere) {
                continue; // la etapa ya cambió: "pendiente de aprobación" sería falso
            }
            $key = NotificationRules::idempotencyKey($historyId, $event);
            try {
                $first = $this->audit->recordOnce((int) $req->getID(), PurchasingEvent::EV_NOTIFICATION_RAISED, (int) $req->fields['entities_id'], [
                    'event' => $event, 'workflow_history_id' => $historyId, 'to' => $to,
                ], (string) ($req->fields['correlation_id'] ?? ''), $key);
            } catch (\Throwable) {
                continue; // sin marca durable no se envía (evita duplicados)
            }
            if (!$first) {
                continue; // ya notificado (listener + Acción automática / reintento)
            }
            try {
                ($this->raiser)($event, $req, $options);
                $raised[] = $event;
            } catch (\Throwable $e) {
                try {
                    $this->audit->record((int) $req->getID(), PurchasingEvent::EV_NOTIFICATION_FAILED, (int) $req->fields['entities_id'], [
                        'event' => $event, 'workflow_history_id' => $historyId, 'error' => get_class($e),
                    ], (string) ($req->fields['correlation_id'] ?? ''));
                } catch (\Throwable) {
                    // best-effort: el hecho de negocio ya está confirmado y no se toca.
                }
            }
        }
        return $raised;
    }

    /**
     * Recorre el ledger desde el cursor (Acción automática): recupera notificaciones de hechos cuyo listener se
     * perdió. Idempotente (la marca durable evita duplicados).
     *
     * @return array{scanned:int, raised:int, cursor:int}
     */
    public function sweep(int $limit = 200): array
    {
        $cursor = max(0, (int) PluginConfig::get('notify_cursor', '0'));
        if (!self::enabled()) {
            return ['scanned' => 0, 'raised' => 0, 'cursor' => $cursor];
        }
        $rows = $this->wf->historySince($cursor, [NotificationRules::LEDGER_TRANSITIONED], max(1, $limit));
        $raised = 0;
        $last = $cursor;
        foreach ($rows as $row) {
            $last = max($last, (int) $row['id']);
            try {
                $raised += count($this->dispatchHistory((int) $row['id']));
            } catch (\Throwable) {
                // best-effort por fila
            }
        }
        if ($last > $cursor) {
            Config::setConfigurationValues(PluginConfig::CONTEXT, ['notify_cursor' => (string) $last]);
        }
        return ['scanned' => count($rows), 'raised' => $raised, 'cursor' => $last];
    }
}
