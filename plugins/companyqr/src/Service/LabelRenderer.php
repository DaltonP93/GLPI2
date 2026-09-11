<?php

/**
 * Adaptador de etiqueta: envuelve TCPDF (dependencia NATIVA de GLPI) para producir
 * una etiqueta 70,75 × 24 mm horizontal, amarilla por defecto, con:
 *   TI • ACTIVOS · QR · código de inventario (NB-/PC-…) · tipo · (organización opcional).
 *
 * Prohibido en la etiqueta: IP, MAC, hostname, VLAN, datos técnicos.
 * REGLA 0: usa (no modifica) TCPDF, ya incluido en GLPI.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Service;

use TCPDF;

final class LabelRenderer
{
    /**
     * @param array{
     *   public_code:string, type:string, qr_data:string,
     *   header?:string, org?:?string, width_mm?:float, height_mm?:float, bg?:string
     * } $label
     * @return string PDF binario
     */
    public function pdf(array $label): string
    {
        [$defW, $defH] = PluginConfig::labelSizeMm();
        $w = (float) ($label['width_mm'] ?? $defW);
        $h = (float) ($label['height_mm'] ?? $defH);
        $header = (string) ($label['header'] ?? PluginConfig::get('label_header', 'TI • ACTIVOS'));
        $bg = self::hexToRgb((string) ($label['bg'] ?? PluginConfig::get('label_bg', '#f7e300')));
        $org = $label['org'] ?? null;

        $pdf = new TCPDF('L', 'mm', [$w, $h], true, 'UTF-8', false);
        $pdf->SetCreator('companyqr');
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetMargins(1.5, 1.5, 1.5);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        // Fondo (amarillo por defecto).
        $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);
        $pdf->Rect(0, 0, $w, $h, 'F');

        // QR a la izquierda (cuadrado ~ alto - márgenes).
        $qrSize = $h - 3.0;
        $style = [
            'border' => false,
            'padding' => 0,
            'fgcolor' => [20, 20, 20],
            'bgcolor' => false,
        ];
        $pdf->write2DBarcode((string) $label['qr_data'], 'QRCODE,H', 1.5, 1.5, $qrSize, $qrSize, $style, 'N');

        // Bloque de texto a la derecha del QR.
        $tx = 1.5 + $qrSize + 2.0;
        $tw = $w - $tx - 1.5;

        $pdf->SetTextColor(70, 63, 0);
        $pdf->SetFont('helvetica', 'B', 6);
        $pdf->SetXY($tx, 2.0);
        $pdf->Cell($tw, 3.0, $header, 0, 2, 'L');

        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetXY($tx, 5.5);
        $pdf->Cell($tw, 5.0, (string) $label['public_code'], 0, 2, 'L');

        $pdf->SetTextColor(45, 45, 45);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetXY($tx, 11.5);
        $pdf->Cell($tw, 4.0, mb_strtoupper((string) $label['type']), 0, 2, 'L');

        if ($org !== null && $org !== '') {
            $pdf->SetTextColor(107, 99, 0);
            $pdf->SetFont('helvetica', '', 6);
            $pdf->SetXY($tx, 16.5);
            $pdf->Cell($tw, 3.0, (string) $org, 0, 2, 'L');
        }

        return $pdf->Output('label.pdf', 'S');
    }

    /** Convierte "#rrggbb" en [r,g,b]. */
    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return [247, 227, 0]; // amarillo por defecto
        }
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
