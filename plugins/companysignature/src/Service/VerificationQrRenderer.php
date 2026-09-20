<?php

/**
 * Adaptador de QR PROPIO de companysignature (decisión D3).
 *
 * Reutiliza la librería QR **ya disponible en GLPI** (`tecnickcom/tc-lib-barcode`, la misma que usa
 * `BarcodeManager` del core) para codificar la **URL del endpoint propio de verificación**. NO se
 * acopla a la clase de `companyqr`: es un adaptador mínimo e independiente (sólo si aparece un 3.º
 * consumidor real se extraería una librería compartida).
 *
 * REGLA 0: usa (no modifica) una dependencia ya incluida en GLPI.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

use Com\Tecnick\Barcode\Barcode;

final class VerificationQrRenderer
{
    /** PNG (binario) de un QR que codifica $data (p. ej. la URL de verificación). */
    public function png(string $data, int $moduleSizePx = 6): string
    {
        return $this->barcode($data, $moduleSizePx)->getPngData();
    }

    /** Data URI base64 del PNG (útil en HTML/Twig). */
    public function pngDataUri(string $data, int $moduleSizePx = 6): string
    {
        return 'data:image/png;base64,' . base64_encode($this->png($data, $moduleSizePx));
    }

    private function barcode(string $data, int $moduleSizePx): \Com\Tecnick\Barcode\Type
    {
        $barcode = new Barcode();
        // Anchos/altos negativos = tamaño de módulo en píxeles. Nivel de corrección H.
        return $barcode->getBarcodeObj('QRCODE,H', $data, -$moduleSizePx, -$moduleSizePx, 'black', [0, 0, 0, 0]);
    }
}
