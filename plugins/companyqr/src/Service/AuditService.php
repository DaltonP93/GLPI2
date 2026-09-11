<?php

/**
 * Auditoría mínima SIN PII: registra eventos de escaneo/acción en la tabla propia
 * glpi_plugin_companyqr_scans. No guarda IP ni User-Agent (ver ADR-0011).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Service;

use Session;
use GlpiPlugin\Companyqr\Model\Code;
use GlpiPlugin\Companyqr\Model\Scan;

final class AuditService
{
    /**
     * Registra un evento de escaneo/acción.
     *
     * @param string   $result   uno de Scan::RESULT_*
     * @param Code|null $code     código involucrado (si se resolvió)
     * @param array{is_anonymous?:bool, tickets_id?:int, channel?:string} $extra
     */
    public function record(string $result, ?Code $code = null, array $extra = []): void
    {
        $input = [
            'date'         => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'result'       => $result,
            'channel'      => (string) ($extra['channel'] ?? 'qr'),
            'is_anonymous' => (int) (bool) ($extra['is_anonymous'] ?? false),
        ];

        if ($code !== null && $code->getID() > 0) {
            $input['plugin_companyqr_codes_id'] = $code->getID();
            $input['itemtype']    = (string) ($code->fields['itemtype'] ?? '');
            $input['items_id']    = (int) ($code->fields['items_id'] ?? 0);
            $input['public_code'] = (string) ($code->fields['public_code'] ?? '');
        }

        $actor = (int) (Session::getLoginUserID() ?: 0);
        if ($actor > 0 && empty($input['is_anonymous'])) {
            $input['actor_users_id'] = $actor;
        }

        if (!empty($extra['tickets_id'])) {
            $input['tickets_id'] = (int) $extra['tickets_id'];
        }

        (new Scan())->add($input);
    }
}
