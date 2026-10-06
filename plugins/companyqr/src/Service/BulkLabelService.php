<?php

/**
 * Impresión MASIVA de etiquetas (ADR-0024).
 *
 *   plan(itemtype, ids, generate, queued) → qué activos entran al lote y por qué no entran los demás
 *   render(codeIds)                       → PDF de varias páginas (una etiqueta por página), REVALIDANDO cada código
 *
 * Principio: el QR identifica; GLPI autoriza. Cada activo debe ser visible para la sesión (`canViewItem`); el código
 * debe estar ACTIVO; nunca se rota, revoca ni reactiva. Sólo se generan códigos faltantes si el usuario lo pidió y
 * tiene `generate`. La decisión por activo es la función PURA `decide()` (tests unitarios).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Service;

use CommonDBTM;
use GlpiPlugin\Companyqr\Model\Code;
use GlpiPlugin\Companyqr\Model\Scan;
use Session;

final class BulkLabelService
{
    /** Resultados de `decide()` / `plan()`. */
    public const OK        = 'ok';         // entra al lote con su código actual
    public const GENERATE  = 'generate';   // entra al lote generando el código que falta
    public const NO_RIGHT  = 'no_right';   // activo inexistente o no visible para la sesión
    public const NO_CODE   = 'no_code';    // sin código y no se pidió (o no se puede) generarlo
    public const INACTIVE  = 'inactive';   // código suspendido o revocado
    public const OVER_MAX  = 'over_limit'; // pasado el tope del lote

    public function __construct(
        private CodeManager $codes = new CodeManager(),
        private LabelRenderer $labels = new LabelRenderer(),
        private AuditService $audit = new AuditService(),
    ) {
    }

    /**
     * Decisión PURA para un activo.
     *
     * @param bool        $visible      el activo existe y la sesión lo ve (`canViewItem`)
     * @param string|null $codeStatus   estado del código, o null si el activo no tiene
     * @param bool        $wantGenerate el usuario marcó "generar los que falten"
     * @param bool        $canGenerate  la sesión tiene `plugin_companyqr` → generate
     * @param int         $queued       etiquetas ya aceptadas en el lote
     */
    public static function decide(bool $visible, ?string $codeStatus, bool $wantGenerate, bool $canGenerate, int $queued, int $limit): string
    {
        if (!$visible) {
            return self::NO_RIGHT;
        }
        if ($codeStatus === null && !($wantGenerate && $canGenerate)) {
            return self::NO_CODE;
        }
        if ($codeStatus !== null && $codeStatus !== Code::STATUS_ACTIVE) {
            return self::INACTIVE;
        }
        if ($queued >= $limit) {
            return self::OVER_MAX;
        }
        return $codeStatus === null ? self::GENERATE : self::OK;
    }

    /**
     * Recorre los activos seleccionados y decide cuáles entran al lote.
     *
     * @param list<int|string> $ids
     * @param list<int>        $queued códigos que YA están en el lote (recarga del proceso masivo de GLPI): se vuelven a
     *                                 aceptar sin consumir el tope
     * @return array{codes:list<int>, outcomes:array<int,string>} `outcomes`: id del activo → resultado
     */
    public function plan(string $itemtype, array $ids, bool $wantGenerate, array $queued, int $limit): array
    {
        $codes = [];
        $outcomes = [];
        $canGenerate = Session::haveRight(Code::$rightname, Code::RIGHT_GENERATE);
        $isType = $itemtype !== '' && is_a($itemtype, CommonDBTM::class, true);

        foreach (LabelBatch::uniqueIds($ids) as $id) {
            $item = null;
            $visible = false;
            if ($isType) {
                /** @var CommonDBTM $item */
                $item = new $itemtype();
                $visible = $item->getFromDB($id) && $item->canViewItem();
            }
            $code = $visible ? $this->codes->findForItem($item) : null;
            $status = $code !== null ? (string) $code->fields['status'] : null;

            $already = $code !== null && in_array((int) $code->getID(), $queued, true);
            $outcome = self::decide($visible, $status, $wantGenerate, $canGenerate, $already ? 0 : count($queued) + count($codes), $limit);
            if ($outcome === self::GENERATE) {
                $code = $this->codes->getOrCreateForItem($item);
                // Otro proceso pudo crearlo entre la búsqueda y el alta: se respeta su estado real.
                if (!$code->isActive()) {
                    $outcome = self::INACTIVE;
                }
            }
            if (($outcome === self::OK || $outcome === self::GENERATE) && !$already) {
                $codes[] = (int) $code->getID();
            }
            $outcomes[$id] = $outcome;
        }

        return ['codes' => LabelBatch::uniqueIds($codes), 'outcomes' => $outcomes];
    }

    /**
     * PDF del lote. REVALIDA cada código al imprimir (existe, ACTIVO, activo visible): lo que dejó de cumplir desde
     * que se armó el lote se omite. Registra una fila de auditoría por etiqueta impresa.
     *
     * @param list<int> $codeIds
     * @return array{pdf:?string, printed:int, skipped:int}
     */
    public function render(array $codeIds): array
    {
        $specs = [];
        $printed = [];
        $skipped = 0;
        foreach (LabelBatch::uniqueIds($codeIds) as $codeId) {
            $code = new Code();
            if (!$code->getFromDB($codeId) || !$code->isActive()) {
                $skipped++;
                continue;
            }
            $itemtype = (string) $code->fields['itemtype'];
            if (!is_a($itemtype, CommonDBTM::class, true)) {
                $skipped++;
                continue;
            }
            /** @var CommonDBTM $item */
            $item = new $itemtype();
            if (!$item->getFromDB((int) $code->fields['items_id']) || !$item->canViewItem()) {
                $skipped++;
                continue;
            }
            $specs[] = LabelComposer::spec($code, $item);
            $printed[] = $code;
        }

        if ($specs === []) {
            return ['pdf' => null, 'printed' => 0, 'skipped' => $skipped];
        }

        $pdf = $this->labels->pdfMany($specs);
        foreach ($printed as $code) {
            $this->audit->record(Scan::RESULT_LABEL_PRINTED, $code, ['channel' => Scan::CHANNEL_BATCH]);
        }
        \Toolbox::logInFile('companyqr', sprintf(
            "label_batch printed=%d skipped=%d user=%d\n",
            count($printed),
            $skipped,
            (int) (Session::getLoginUserID() ?: 0)
        ));

        return ['pdf' => $pdf, 'printed' => count($printed), 'skipped' => $skipped];
    }
}
