<?php

/**
 * Destinos y datos de plantilla de las notificaciones NATIVAS de la solicitud de compra (P2D-4; ADR-0023 §9).
 *
 * GLPI 11.0.8 resuelve esta clase con `NotificationTarget::getInstanceClass()` (mismo namespace que el modelo +
 * prefijo `NotificationTarget`). El envío lo hace el motor NATIVO: `NotificationEvent::raiseEvent()` →
 * `QueuedNotification` → Acción automática `queuednotification` (sin mailer ni cola propios).
 *
 * Destinos específicos (además de los nativos de grupos/perfiles/administradores que configure el administrador):
 * solicitante, aprobadores EFECTIVOS de la etapa actual (motor), actor del hecho y destinatario de la entrega.
 * Nada hardcodeado a personas.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use NotificationTarget;
use User;
use GlpiPlugin\Companypurchasing\Service\Labels;
use GlpiPlugin\Companypurchasing\Service\Money;
use GlpiPlugin\Companypurchasing\Service\NotificationRules;
use GlpiPlugin\Companypurchasing\Service\PluginConfig;
use GlpiPlugin\Companypurchasing\Service\WorkflowGateway;

/**
 * @extends NotificationTarget<Request>
 */
class NotificationTargetRequest extends NotificationTarget
{
    public function getEvents()
    {
        $out = [];
        foreach (NotificationRules::EVENTS as $event) {
            $out[$event] = Labels::notificationEvent($event);
        }
        return $out;
    }

    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(NotificationRules::TARGET_REQUESTER, __('Requester', 'companypurchasing'));
        $this->addTarget(NotificationRules::TARGET_APPROVERS, __('Current stage approvers', 'companypurchasing'));
        $this->addTarget(NotificationRules::TARGET_ACTOR, __('User who performed the action', 'companypurchasing'));
        $this->addTarget(NotificationRules::TARGET_RECIPIENT, __('Delivery recipient', 'companypurchasing'));
    }

    public function addSpecificTargets($data, $options)
    {
        if (!$this->obj instanceof Request) {
            return;
        }
        $ids = match ((int) ($data['items_id'] ?? 0)) {
            NotificationRules::TARGET_REQUESTER => [(int) ($this->obj->fields['users_id_requester'] ?? 0)],
            NotificationRules::TARGET_APPROVERS => $this->approvers($options),
            NotificationRules::TARGET_ACTOR     => [(int) ($options['actor_users_id'] ?? 0)],
            NotificationRules::TARGET_RECIPIENT => $this->recipients($options),
            default                             => [],
        };
        foreach (array_unique(array_filter(array_map('intval', $ids))) as $userId) {
            $user = new User();
            if ($user->getFromDB($userId)) {
                $this->addToRecipientsList(['language' => $user->getField('language'), 'users_id' => $userId]);
            }
        }
    }

    public function addDataForTemplate($event, $options = [])
    {
        global $CFG_GLPI;
        $req = $this->obj;
        if (!$req instanceof Request) {
            return;
        }
        $currency = (string) ($req->fields['currency_code'] ?? 'PYG');
        $amount = '';
        try {
            $amount = Money::ofStored((string) ($req->fields['amount_estimated'] ?? '0'), $currency, PluginConfig::currencyScaleOverrides())->amount();
        } catch (\Throwable) {
            $amount = '';
        }
        $this->data['##purchasing.action##']      = Labels::notificationEvent((string) $event);
        $this->data['##purchasing.number##']      = (string) ($req->fields['number'] ?? '');
        $this->data['##purchasing.state##']       = Labels::state((string) ($req->fields['domain_state'] ?? ''));
        $this->data['##purchasing.category##']    = (string) ($req->fields['category'] ?? '');
        $this->data['##purchasing.amount##']      = $amount;
        $this->data['##purchasing.currency##']    = $currency;
        $this->data['##purchasing.entity##']      = \Dropdown::getDropdownName('glpi_entities', (int) ($req->fields['entities_id'] ?? 0));
        $this->data['##purchasing.requester##']   = $this->userName((int) ($req->fields['users_id_requester'] ?? 0));
        $this->data['##purchasing.actor##']       = $this->userName((int) ($options['actor_users_id'] ?? 0));
        $this->data['##purchasing.url##']         = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . '/plugins/companypurchasing/request/' . (int) $req->getID();

        foreach ($this->tagLabels() as $tag => $label) {
            $this->data['##lang.' . $tag . '##'] = $label;
        }
    }

    public function getTags()
    {
        foreach ($this->tagLabels() as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }
        asort($this->tag_descriptions);
    }

    /** @return array<string,string> */
    private function tagLabels(): array
    {
        return [
            'purchasing.action'    => __('Event', 'companypurchasing'),
            'purchasing.number'    => __('Request number', 'companypurchasing'),
            'purchasing.state'     => __('Current state', 'companypurchasing'),
            'purchasing.category'  => __('Category', 'companypurchasing'),
            'purchasing.amount'    => __('Estimated amount', 'companypurchasing'),
            'purchasing.currency'  => __('Currency', 'companypurchasing'),
            'purchasing.entity'    => __('Entity', 'companypurchasing'),
            'purchasing.requester' => __('Requester', 'companypurchasing'),
            'purchasing.actor'     => __('User who performed the action', 'companypurchasing'),
            'purchasing.url'       => __('Link to the request', 'companypurchasing'),
        ];
    }

    /** @param array<string,mixed> $options @return array<int,int> */
    private function approvers(array $options): array
    {
        if (isset($options['approvers']) && is_array($options['approvers'])) {
            return array_map('intval', $options['approvers']);
        }
        // Sin lista explícita (p. ej. envío de prueba desde la UI nativa): aprobadores vigentes según el MOTOR.
        try {
            $gw = new WorkflowGateway();
            $inst = $gw->loadInstance((int) ($this->obj->fields['workflow_instances_id'] ?? 0));
            return $inst !== null ? $gw->currentApprovers($inst) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $options @return array<int,int> */
    private function recipients(array $options): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        if (isset($options['recipient_users_id'])) {
            return [(int) $options['recipient_users_id']];
        }
        $out = [];
        foreach ($DB->request(['SELECT' => ['recipient_users_id'], 'DISTINCT' => true, 'FROM' => DeliveryBatch::getTable(),
                               'WHERE' => ['requests_id' => (int) $this->obj->getID()]]) as $row) {
            $out[] = (int) $row['recipient_users_id'];
        }
        return $out;
    }

    private function userName(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        $u = new User();
        return $u->getFromDB($userId) ? (string) $u->getFriendlyName() : '';
    }
}
