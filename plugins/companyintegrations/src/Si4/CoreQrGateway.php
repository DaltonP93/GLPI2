<?php

/**
 * `QrGateway` de PRODUCCIÓN: delega 1:1 en la API pública de companyqr (`CompanyQrApi`), con la sesión REAL del
 * usuario técnico (la ACL la aplica companyqr: RIGHT_GENERATE / RIGHT_PRINT + `canViewItem` del activo). Sin SQL ni
 * acceso a tablas o servicios internos de companyqr; si el plugin no está activo ⇒ `unavailable` (fail-closed).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

use GlpiPlugin\Companyqr\Api\CompanyQrApi;
use GlpiPlugin\Companyqr\Api\CompanyQrException;
use GlpiPlugin\Companyqr\Model\Code;
use Session;

final class CoreQrGateway implements QrGateway
{
    private ?CompanyQrApi $api = null;

    public function rightsProblem(): ?string
    {
        if (!self::available()) {
            return 'companyqr no está disponible (plugin inactivo o sin CompanyQrApi)';
        }
        if (!Session::haveRight(Code::$rightname, Code::RIGHT_GENERATE)) {
            return 'permiso denegado (companyqr RIGHT_GENERATE)';
        }
        if (!Session::haveRight(Code::$rightname, Code::RIGHT_PRINT)) {
            return 'permiso denegado (companyqr RIGHT_PRINT)';
        }
        return null;
    }

    public function ensureForItem(string $itemtype, int $itemsId): array
    {
        try {
            return $this->api()->ensureForItem($itemtype, $itemsId);
        } catch (CompanyQrException $e) {
            throw new QrGatewayException($e->kind, $e->getMessage());
        }
    }

    public function getCode(int $codeId): ?array
    {
        try {
            return $this->api()->getCode($codeId);
        } catch (CompanyQrException $e) {
            throw new QrGatewayException($e->kind, $e->getMessage());
        }
    }

    public function renderLabelPdf(int $codeId): string
    {
        try {
            return $this->api()->renderLabelPdf($codeId);
        } catch (CompanyQrException $e) {
            throw new QrGatewayException($e->kind, $e->getMessage());
        }
    }

    private function api(): CompanyQrApi
    {
        if (!self::available()) {
            throw new QrGatewayException(QrGatewayException::UNAVAILABLE, 'companyqr no está disponible (plugin inactivo o sin CompanyQrApi)');
        }
        return $this->api ??= new CompanyQrApi();
    }

    private static function available(): bool
    {
        return class_exists(CompanyQrApi::class) && class_exists(Code::class) && \Plugin::isPluginActive('companyqr');
    }
}
