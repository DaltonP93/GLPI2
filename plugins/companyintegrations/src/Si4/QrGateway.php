<?php

/**
 * Puerto de SI4-3 hacia companyqr (ADR-0022). La implementación de producción (`CoreQrGateway`) delega 1:1 en la API
 * pública `GlpiPlugin\Companyqr\Api\CompanyQrApi`: companyintegrations NO crea otro sistema de QR, NO lee ni escribe
 * tablas de companyqr y NO conoce el token opaco ni arma la URL del QR (todo eso vive en companyqr).
 *
 * Metadatos de un código (`QrCodeRules::META_KEYS`): code_id, public_code, status, itemtype, items_id, entities_id
 * (+ outcome en `ensureForItem`). Nunca el token.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

interface QrGateway
{
    /** null = la sesión puede generar (RIGHT_GENERATE) e imprimir (RIGHT_PRINT) códigos; si no, el motivo (⇒ BLOCKED_CONFIG). */
    public function rightsProblem(): ?string;

    /**
     * Código del activo, get-or-create IDEMPOTENTE (un solo código por activo). No rota ni reactiva.
     *
     * @return array{code_id:int, public_code:string, status:string, itemtype:string, items_id:int, entities_id:int, outcome:string}
     * @throws QrGatewayException
     */
    public function ensureForItem(string $itemtype, int $itemsId): array;

    /**
     * @return array{code_id:int, public_code:string, status:string, itemtype:string, items_id:int, entities_id:int}|null
     * @throws QrGatewayException
     */
    public function getCode(int $codeId): ?array;

    /**
     * PDF de la etiqueta del código ACTIVO con el renderer de companyqr. El llamador NO lo persiste.
     *
     * @throws QrGatewayException
     */
    public function renderLabelPdf(int $codeId): string;
}
