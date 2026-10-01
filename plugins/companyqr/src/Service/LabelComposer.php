<?php

/**
 * Contenido de la etiqueta física de un código: la entrada de `LabelRenderer::pdf()` (medidas y colores los resuelve el
 * renderer desde la configuración). Compartido por `LabelController` (GET /label/{code_id}) y `CompanyQrApi`, así la
 * etiqueta es la MISMA venga de donde venga.
 *
 * Sólo lleva: código visible (`public_code`), tipo, encabezado configurado, organización opcional y, como dato del QR,
 * la URL de escaneo con el token opaco (`ScanUrl`). NUNCA IP, MAC, hostname, VLAN, serial ni datos técnicos.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Service;

use CommonDBTM;
use GlpiPlugin\Companyqr\Model\Code;

final class LabelComposer
{
    /** Claves PERMITIDAS en la especificación de la etiqueta (whitelist; test de no-fuga). */
    public const KEYS = ['public_code', 'type', 'qr_data', 'header', 'org'];

    /**
     * @return array{public_code:string, type:string, qr_data:string, header:string, org:?string}
     */
    public static function spec(Code $code, CommonDBTM $item): array
    {
        return [
            'public_code' => (string) $code->fields['public_code'],
            'type'        => $item->getTypeName(1),
            'qr_data'     => ScanUrl::forToken((string) $code->fields['token']),
            'header'      => (string) PluginConfig::get('label_header', 'TI • ACTIVOS'),
            'org'         => (int) PluginConfig::get('label_show_org', '0') === 1
                ? \Dropdown::getDropdownName('glpi_entities', (int) $code->fields['entities_id'])
                : null,
        ];
    }
}
