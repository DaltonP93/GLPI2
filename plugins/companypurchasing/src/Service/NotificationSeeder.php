<?php

/**
 * Siembra (install/upgrade) y retira (uninstall) las notificaciones NATIVAS de la solicitud de compra (P2D-4).
 *
 * Usa EXCLUSIVAMENTE la API nativa de GLPI 11.0.8 (`Notification`, `NotificationTemplate`,
 * `NotificationTemplateTranslation`, `Notification_NotificationTemplate`, `NotificationTarget` vía CommonDBTM): sin
 * SQL contra tablas core. Idempotente y upgrade-safe: sólo crea lo que FALTA por (itemtype, evento); nunca pisa lo
 * que el administrador ajustó (destinos, plantilla, activación).
 *
 * La plantilla no contiene textos de negocio hardcodeados: sólo etiquetas (`##lang.…##` / `##purchasing.…##`) que GLPI
 * resuelve en el idioma de cada destinatario con las traducciones del plugin.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use GlpiPlugin\Companypurchasing\Model\Request;

final class NotificationSeeder
{
    public const TEMPLATE_SUBJECT = '##purchasing.action##: ##purchasing.number##';
    public const TEMPLATE_TEXT = "##lang.purchasing.action##: ##purchasing.action##\n"
        . "##lang.purchasing.number##: ##purchasing.number##\n"
        . "##lang.purchasing.state##: ##purchasing.state##\n"
        . "##lang.purchasing.entity##: ##purchasing.entity##\n"
        . "##lang.purchasing.requester##: ##purchasing.requester##\n"
        . "##lang.purchasing.category##: ##purchasing.category##\n"
        . "##lang.purchasing.amount##: ##purchasing.amount## ##purchasing.currency##\n"
        . "##lang.purchasing.actor##: ##purchasing.actor##\n"
        . "##lang.purchasing.url##: ##purchasing.url##\n";

    /** @return int notificaciones creadas ahora */
    public static function seed(): int
    {
        if (!class_exists(\Notification::class) || !class_exists(\NotificationTemplate::class)) {
            return 0;
        }
        $templateId = self::templateId();
        if ($templateId <= 0) {
            return 0;
        }
        $created = 0;
        foreach (NotificationRules::EVENTS as $event) {
            if (countElementsInTable(\Notification::getTable(), ['itemtype' => Request::class, 'event' => $event]) > 0) {
                continue; // ya existe (quizá ajustada por el administrador): no se toca
            }
            $nid = (int) (new \Notification())->add([
                'name'         => Labels::notificationEvent($event),
                'entities_id'  => 0,
                'is_recursive' => 1,
                'is_active'    => 1,
                'itemtype'     => Request::class,
                'event'        => $event,
            ]);
            if ($nid <= 0) {
                continue;
            }
            (new \Notification_NotificationTemplate())->add([
                'notifications_id'         => $nid,
                'mode'                     => \Notification_NotificationTemplate::MODE_MAIL,
                'notificationtemplates_id' => $templateId,
            ]);
            foreach (NotificationRules::DEFAULT_TARGETS[$event] ?? [] as $target) {
                (new \NotificationTarget())->add([
                    'notifications_id' => $nid,
                    'type'             => \Notification::USER_TYPE,
                    'items_id'         => $target,
                ]);
            }
            $created++;
        }
        return $created;
    }

    /** Retira notificaciones y plantillas del tipo (purga nativa: destinos, relaciones, traducciones y cola). */
    public static function purge(): void
    {
        if (!class_exists(\Notification::class)) {
            return;
        }
        foreach ((new \Notification())->find(['itemtype' => Request::class]) as $row) {
            (new \Notification())->delete(['id' => (int) $row['id']], true);
        }
        foreach ((new \NotificationTemplate())->find(['itemtype' => Request::class]) as $row) {
            (new \NotificationTemplate())->delete(['id' => (int) $row['id']], true);
        }
    }

    /** Plantilla del tipo (la existente o una nueva con su traducción por defecto). */
    private static function templateId(): int
    {
        foreach ((new \NotificationTemplate())->find(['itemtype' => Request::class], ['id ASC'], 1) as $row) {
            return (int) $row['id'];
        }
        $tid = (int) (new \NotificationTemplate())->add([
            'name'     => __('Purchase requests', 'companypurchasing'),
            'itemtype' => Request::class,
        ]);
        if ($tid > 0) {
            (new \NotificationTemplateTranslation())->add([
                'notificationtemplates_id' => $tid,
                'language'                 => '',
                'subject'                  => self::TEMPLATE_SUBJECT,
                'content_text'             => self::TEMPLATE_TEXT,
                'content_html'             => nl2br(self::TEMPLATE_TEXT),
            ]);
        }
        return $tid;
    }
}
