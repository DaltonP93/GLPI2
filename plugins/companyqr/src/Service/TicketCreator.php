<?php

/**
 * Adaptador de creación de tickets: crea un `Ticket` NATIVO y lo VINCULA al activo
 * con `Item_Ticket` (API soportada). Categoría/urgencia/entidad configurables.
 *
 * REGLA 0: usa las clases del core (Ticket, Item_Ticket) por su API pública.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Service;

use CommonDBTM;
use Item_Ticket;
use Ticket;

final class TicketCreator
{
    /**
     * @param array{
     *   title:string, content:string,
     *   requester_users_id?:int, category?:int, urgency?:int, anonymous?:bool
     * } $opts
     * @return int id del ticket creado (0 si falló)
     */
    public function createForAsset(CommonDBTM $asset, array $opts): int
    {
        $input = [
            'name'              => $opts['title'],
            'content'           => $opts['content'],
            'entities_id'       => (int) ($asset->fields['entities_id'] ?? 0),
            'itilcategories_id' => (int) ($opts['category'] ?? (int) PluginConfig::get('default_itilcategories_id', '0')),
            'urgency'           => (int) ($opts['urgency'] ?? (int) PluginConfig::get('default_urgency', '3')),
        ];

        // Solicitante: usuario autenticado si lo hay (el anónimo se crea sin requester).
        if (!empty($opts['requester_users_id'])) {
            $input['_users_id_requester'] = (int) $opts['requester_users_id'];
        }

        $ticket = new Ticket();
        $ticketId = (int) $ticket->add($input);
        if ($ticketId <= 0) {
            return 0;
        }

        // Vínculo ticket <-> activo (INVARIANTE: un ticket por QR SIN activo = operación fallida).
        $link = new Item_Ticket();
        $linkId = (int) $link->add([
            'tickets_id' => $ticketId,
            'itemtype'   => $asset->getType(),
            'items_id'   => $asset->getID(),
        ]);

        if ($linkId <= 0) {
            // Compensación fail-closed: si no se pudo vincular, se deshace el ticket
            // (purga) para no dejar un ticket huérfano reportado como "éxito".
            $ticket->delete(['id' => $ticketId], true);
            return 0;
        }

        return $ticketId;
    }
}
