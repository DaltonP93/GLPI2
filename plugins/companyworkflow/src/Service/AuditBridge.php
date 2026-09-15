<?php

/**
 * Puente de auditoría append-only. Escribe una fila por evento en ..._history y, cuando el
 * core lo permite, deja además rastro en el Log nativo. NUNCA borra historial.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Service;

use Session;
use GlpiPlugin\Companyworkflow\Model\HistoryEvent;

final class AuditBridge
{
    /**
     * @param array<string,mixed> $meta
     */
    public function record(
        int $instancesId,
        string $event,
        string $fromCode = '',
        string $toCode = '',
        string $comment = '',
        bool $isSystem = false,
        array $meta = []
    ): int {
        $actor = (int) (Session::getLoginUserID() ?: 0);
        $input = [
            'instances_id'   => $instancesId,
            'event'          => $event,
            'from_code'      => $fromCode,
            'to_code'        => $toCode,
            'actor_users_id' => $isSystem ? 0 : $actor,
            'is_system'      => $isSystem ? 1 : 0,
            'comment'        => $comment !== '' ? $comment : null,
            'meta_json'      => $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'date'           => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ];
        return (int) (new HistoryEvent())->add($input);
    }
}
