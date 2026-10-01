<?php

/**
 * API PÚBLICA mínima de companyqr para otros plugins (SI4-3, ADR-0022). Agnóstica del dominio: no sabe de compras,
 * sagas ni Snipe-IT; sólo "código de un activo" y "su etiqueta".
 *
 *   ensureForItem(itemtype, itemsId) → metadatos del código del activo (get-or-create IDEMPOTENTE)
 *   findForItem(itemtype, itemsId)   → metadatos o null (sin crear)
 *   getCode(codeId)                  → metadatos o null
 *   renderLabelPdf(codeId)           → PDF de la etiqueta (la MISMA de GET /label/{code_id})
 *
 * Principio: el QR identifica; GLPI autoriza. Cada llamada exige el derecho del plugin del usuario de la SESIÓN
 * (generate / print / read) y que el activo sea VISIBLE por la ACL nativa (`canViewItem`: perfil + entidad), igual que
 * `AdminController` y `LabelController`.
 *
 * Los metadatos son NO sensibles (`META_KEYS`): el TOKEN OPACO nunca sale de companyqr. La URL del QR que lo contiene
 * se arma sólo aquí adentro (`ScanUrl` vía `LabelComposer`); el consumidor recibe el PDF ya hecho y no lo guarda.
 * Nunca rota, revoca ni reactiva un código: un código no ACTIVO se informa tal cual (`status`) y el consumidor decide.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Api;

use CommonDBTM;
use GlpiPlugin\Companyqr\Model\Code;
use GlpiPlugin\Companyqr\Service\CodeManager;
use GlpiPlugin\Companyqr\Service\LabelComposer;
use GlpiPlugin\Companyqr\Service\LabelRenderer;
use Session;

final class CompanyQrApi
{
    /** Metadatos devueltos (whitelist): NUNCA el token. */
    public const META_KEYS = ['code_id', 'public_code', 'status', 'itemtype', 'items_id', 'entities_id'];

    public const OUTCOME_CREATED  = 'created';
    public const OUTCOME_EXISTING = 'existing';

    private CodeManager $codes;
    private LabelRenderer $labels;

    public function __construct(?CodeManager $codes = null, ?LabelRenderer $labels = null)
    {
        $this->codes = $codes ?? new CodeManager();
        $this->labels = $labels ?? new LabelRenderer();
    }

    /**
     * Código del activo, creándolo si no existe (`CodeManager::getOrCreateForItem`; UNIQUE(itemtype, items_id): un
     * solo código por activo aun con llamadas concurrentes o repetidas tras un crash).
     *
     * @return array{code_id:int, public_code:string, status:string, itemtype:string, items_id:int, entities_id:int, outcome:string}
     * @throws CompanyQrException
     */
    public function ensureForItem(string $itemtype, int $itemsId): array
    {
        self::assertRight(Code::RIGHT_GENERATE, 'generate');
        $item = self::visibleItem($itemtype, $itemsId);
        $existed = $this->codes->findForItem($item) !== null;
        $code = $this->codes->getOrCreateForItem($item);
        return self::meta($code) + ['outcome' => $existed ? self::OUTCOME_EXISTING : self::OUTCOME_CREATED];
    }

    /**
     * @return array{code_id:int, public_code:string, status:string, itemtype:string, items_id:int, entities_id:int}|null
     * @throws CompanyQrException
     */
    public function findForItem(string $itemtype, int $itemsId): ?array
    {
        self::assertAnyRight();
        $code = $this->codes->findForItem(self::visibleItem($itemtype, $itemsId));
        return $code !== null ? self::meta($code) : null;
    }

    /**
     * @return array{code_id:int, public_code:string, status:string, itemtype:string, items_id:int, entities_id:int}|null
     * @throws CompanyQrException el activo referenciado no existe o no es visible
     */
    public function getCode(int $codeId): ?array
    {
        self::assertAnyRight();
        $code = new Code();
        if ($codeId <= 0 || !$code->getFromDB($codeId)) {
            return null;
        }
        self::visibleItem((string) $code->fields['itemtype'], (int) $code->fields['items_id']);
        return self::meta($code);
    }

    /**
     * PDF de la etiqueta (70,75 × 24 mm por defecto; medidas/colores de la configuración del plugin) con el renderer
     * EXISTENTE (`LabelRenderer::pdf`). Sólo para códigos ACTIVOS. No se persiste.
     *
     * @throws CompanyQrException
     */
    public function renderLabelPdf(int $codeId): string
    {
        self::assertRight(Code::RIGHT_PRINT, 'print');
        $code = new Code();
        if ($codeId <= 0 || !$code->getFromDB($codeId)) {
            throw new CompanyQrException(CompanyQrException::NOT_FOUND, 'código #' . $codeId . ' inexistente');
        }
        $item = self::visibleItem((string) $code->fields['itemtype'], (int) $code->fields['items_id']);
        if (!$code->isActive()) {
            throw new CompanyQrException(CompanyQrException::INACTIVE, 'código #' . $codeId . ' no activo (' . (string) $code->fields['status'] . ')');
        }
        try {
            $pdf = $this->labels->pdf(LabelComposer::spec($code, $item));
        } catch (\Throwable $e) {
            throw new CompanyQrException(CompanyQrException::RENDER, 'no se pudo generar la etiqueta del código #' . $codeId . ' (' . get_class($e) . ')');
        }
        if (!str_starts_with($pdf, '%PDF-')) {
            throw new CompanyQrException(CompanyQrException::RENDER, 'la etiqueta del código #' . $codeId . ' no es un PDF');
        }
        return $pdf;
    }

    /**
     * Metadatos NO sensibles del código (sin token).
     *
     * @return array{code_id:int, public_code:string, status:string, itemtype:string, items_id:int, entities_id:int}
     */
    public static function meta(Code $code): array
    {
        return [
            'code_id'     => (int) $code->getID(),
            'public_code' => (string) $code->fields['public_code'],
            'status'      => (string) $code->fields['status'],
            'itemtype'    => (string) $code->fields['itemtype'],
            'items_id'    => (int) $code->fields['items_id'],
            'entities_id' => (int) $code->fields['entities_id'],
        ];
    }

    private static function assertRight(int $bit, string $label): void
    {
        if (!Session::haveRight(Code::$rightname, $bit)) {
            throw new CompanyQrException(CompanyQrException::ACL, 'permiso denegado (companyqr ' . $label . ')');
        }
    }

    private static function assertAnyRight(): void
    {
        if (!Session::haveRightsOr(Code::$rightname, [READ, Code::RIGHT_GENERATE, Code::RIGHT_PRINT])) {
            throw new CompanyQrException(CompanyQrException::ACL, 'permiso denegado (companyqr read)');
        }
    }

    /** Activo existente y VISIBLE para la sesión (ACL nativa: perfil + entidad + reglas del itemtype). */
    private static function visibleItem(string $itemtype, int $itemsId): CommonDBTM
    {
        if ($itemsId <= 0 || $itemtype === '' || !is_a($itemtype, CommonDBTM::class, true)) {
            throw new CompanyQrException(CompanyQrException::INVALID, 'activo inválido');
        }
        /** @var CommonDBTM $item */
        $item = new $itemtype();
        if (!$item->getFromDB($itemsId)) {
            throw new CompanyQrException(CompanyQrException::NOT_FOUND, $itemtype . ' #' . $itemsId . ' inexistente');
        }
        if (!$item->canViewItem()) {
            throw new CompanyQrException(CompanyQrException::ACL, 'el usuario no ve ' . $itemtype . ' #' . $itemsId);
        }
        return $item;
    }
}
