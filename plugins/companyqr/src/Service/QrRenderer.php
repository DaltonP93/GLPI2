<?php

/**
 * Adaptador de QR: envuelve la librería NATIVA de GLPI `tecnickcom/tc-lib-barcode`
 * (la misma que usa BarcodeManager del core). Codifica una URL/dato ARBITRARIO —
 * a diferencia de BarcodeManager::renderQRCode(), que sólo codifica la URL del ítem.
 *
 * REGLA 0: usa (no modifica) una dependencia ya incluida en GLPI.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Service;

use Com\Tecnick\Barcode\Barcode;

final class QrRenderer
{
    /** Devuelve el PNG (binario) de un QR que codifica $data. */
    public function png(string $data, int $moduleSizePx = 6): string
    {
        return $this->barcode($data, $moduleSizePx)->getPngData();
    }

    /** Devuelve un data URI base64 del PNG (útil en HTML/Twig). */
    public function pngDataUri(string $data, int $moduleSizePx = 6): string
    {
        return 'data:image/png;base64,' . base64_encode($this->png($data, $moduleSizePx));
    }

    /** Devuelve el SVG (texto) de un QR que codifica $data. */
    public function svg(string $data, int $moduleSizePx = 6): string
    {
        return $this->barcode($data, $moduleSizePx)->getSvgCode();
    }

    private function barcode(string $data, int $moduleSizePx): \Com\Tecnick\Barcode\Type
    {
        $barcode = new Barcode();
        // Anchos/altos negativos = tamaño de módulo en píxeles. Nivel de corrección H.
        return $barcode->getBarcodeObj(
            'QRCODE,H',
            $data,
            -$moduleSizePx,
            -$moduleSizePx,
            'black',
            [0, 0, 0, 0]
        );
    }
}
