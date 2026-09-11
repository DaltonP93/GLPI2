<?php

/**
 * Resolución token -> activo + construcción de la vista SEGURA (whitelist).
 *
 * Principio (ADR-0011): el QR identifica; GLPI autoriza. Este servicio:
 *  - resuelve el token a la fila del código y al activo referenciado;
 *  - aplica la ACL NATIVA de GLPI (canViewItem) — nunca la evita;
 *  - expone SÓLO un conjunto seguro de campos (whitelist). Jamás IP/MAC/hostname/etc.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Service;

use CommonDBTM;
use Dropdown;
use GlpiPlugin\Companyqr\Model\Code;

final class AssetResolver
{
    /**
     * Campos SEGUROS que la ficha puede exponer (autenticada).
     * Son campos presentes en prácticamente todos los activos y no sensibles.
     */
    public const SAFE_FIELDS = ['name', 'type', 'public_code', 'status', 'location', 'entity'];

    /**
     * Campos PROHIBIDOS: nunca deben aparecer en ninguna vista (test de no-fuga).
     */
    public const FORBIDDEN_FIELDS = [
        'ip', 'mac', 'hostname', 'serial', 'uuid',
        'users_id', 'users_id_tech', 'groups_id_tech',
        'contact', 'contact_num', 'networkport', 'vlan',
    ];

    /** Busca el código por su token opaco. Devuelve null si no existe. */
    public function findByToken(string $token): ?Code
    {
        if (!TokenGenerator::isWellFormed($token)) {
            return null;
        }
        $code = new Code();
        if ($code->getFromDBByCrit(['token' => $token])) {
            return $code;
        }
        return null;
    }

    /** Carga el activo referenciado por el código. Null si la clase no existe o el activo se purgó. */
    public function loadAsset(Code $code): ?CommonDBTM
    {
        $itemtype = (string) ($code->fields['itemtype'] ?? '');
        if ($itemtype === '' || !is_a($itemtype, CommonDBTM::class, true)) {
            return null;
        }
        /** @var CommonDBTM $item */
        $item = new $itemtype();
        if ($item->getFromDB((int) $code->fields['items_id'])) {
            return $item;
        }
        return null;
    }

    /**
     * ACL NATIVA. No la evita nunca: delega en canViewItem() de GLPI
     * (perfil + entidad activa + reglas del propio itemtype).
     */
    public function canView(CommonDBTM $item): bool
    {
        return (bool) $item->canViewItem();
    }

    /**
     * Vista segura para usuario AUTENTICADO con permiso. Sólo whitelist.
     *
     * @return array<string,string>
     */
    public function buildSafeView(Code $code, CommonDBTM $item): array
    {
        $view = [
            'name'        => (string) ($item->fields['name'] ?? ''),
            'type'        => $item->getTypeName(1),
            'public_code' => (string) ($code->fields['public_code'] ?? ''),
        ];

        if (!empty($item->fields['states_id'])) {
            $view['status'] = Dropdown::getDropdownName('glpi_states', (int) $item->fields['states_id']);
        }
        if (!empty($item->fields['locations_id'])) {
            $view['location'] = Dropdown::getDropdownName('glpi_locations', (int) $item->fields['locations_id']);
        }
        if (isset($item->fields['entities_id'])) {
            $view['entity'] = Dropdown::getDropdownName('glpi_entities', (int) $item->fields['entities_id']);
        }

        return self::stripForbidden($view);
    }

    /**
     * Vista MÍNIMA para modo anónimo: sólo código + tipo (config `anon_fields`).
     *
     * @return array<string,string>
     */
    public function buildMinimalView(Code $code, CommonDBTM $item): array
    {
        $view = [
            'public_code' => (string) ($code->fields['public_code'] ?? ''),
            'type'        => $item->getTypeName(1),
        ];
        return self::stripForbidden($view);
    }

    /**
     * Garantía defensiva: elimina cualquier clave prohibida que se hubiese colado.
     *
     * @param array<string,string> $view
     * @return array<string,string>
     */
    public static function stripForbidden(array $view): array
    {
        foreach (array_keys($view) as $key) {
            foreach (self::FORBIDDEN_FIELDS as $bad) {
                if (stripos((string) $key, $bad) !== false) {
                    unset($view[$key]);
                    break;
                }
            }
        }
        return $view;
    }
}
