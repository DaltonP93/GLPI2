<?php

/**
 * Modelo del código QR por activo (tabla propia glpi_plugin_companyqr_codes).
 *
 * REGLA 0: tabla PROPIA del plugin (prefijo glpi_plugin_companyqr_). No es una
 * tabla del core. La visibilidad de datos del activo NO depende de este modelo,
 * sino de la ACL nativa de GLPI sobre el activo referenciado.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Model;

use CommonDBTM;
use GlpiPlugin\Companyqr\Service\BulkLabelService;
use GlpiPlugin\Companyqr\Service\LabelBatch;
use GlpiPlugin\Companyqr\Service\PluginConfig;
use Html;
use MassiveAction;
use Session;

class Code extends CommonDBTM
{
    /** Derecho de plugin (bits definidos abajo). */
    public static $rightname = 'plugin_companyqr';

    /** Estados posibles del código. */
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REVOKED   = 'revoked';

    /**
     * Bits del derecho `plugin_companyqr`. READ es el bit estándar (1).
     * Los demás son bits propios del plugin (no colisionan porque el rightname es nuestro).
     */
    public const RIGHT_GENERATE = 2; // crear / rotar / revocar códigos
    public const RIGHT_PRINT    = 4; // imprimir etiquetas
    public const RIGHT_CONFIG   = 8; // configurar el plugin

    /** Acción masiva "Imprimir etiquetas QR" (ADR-0024). Clave completa: `Code::class . ':' . MA_PRINT_LABELS`. */
    public const MA_PRINT_LABELS = 'print_labels';

    /** Campo del formulario de la acción: generar los códigos que falten. */
    public const MA_GENERATE_FIELD = 'companyqr_generate_missing';

    /** Clave del lote en `$ma->POST`: sobrevive a las recargas del proceso masivo de GLPI. */
    private const MA_BATCH_FIELD = 'companyqr_batch';

    /**
     * Nombre de tabla EXPLÍCITO (evita depender de la derivación de nombres de
     * GLPI para clases con namespace de plugin).
     */
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyqr_codes';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('QR code', 'QR codes', $nb, 'companyqr');
    }

    /**
     * Bits de derecho disponibles para el formulario de perfiles de GLPI.
     *
     * @param bool $interface
     * @return array<int,string|array>
     */
    public function getRights($interface = 'central')
    {
        return [
            READ                 => __('Read'),
            self::RIGHT_GENERATE => __('Generate / rotate / revoke codes', 'companyqr'),
            self::RIGHT_PRINT    => __('Print labels', 'companyqr'),
            self::RIGHT_CONFIG   => __('Configure plugin', 'companyqr'),
        ];
    }

    /** ¿El código resuelve a un activo (está activo)? */
    public function isActive(): bool
    {
        return ($this->fields['status'] ?? null) === self::STATUS_ACTIVE;
    }

    /**
     * Formulario de la acción masiva (hook nativo de GLPI). Sólo un botón y, con `generate`, la casilla para generar
     * los códigos que falten (apagada por defecto).
     */
    public static function showMassiveActionsSubForm(MassiveAction $ma)
    {
        if ($ma->getAction() !== self::MA_PRINT_LABELS) {
            return parent::showMassiveActionsSubForm($ma);
        }
        $limit = LabelBatch::clampLimit(PluginConfig::get('label_batch_max', (string) LabelBatch::DEFAULT_MAX));
        echo '<p class="text-muted">' . htmlspecialchars(
            sprintf(__('One label per page, up to %d labels per batch.', 'companyqr'), $limit),
            ENT_QUOTES
        ) . '</p>';
        if (Session::haveRight(self::$rightname, self::RIGHT_GENERATE)) {
            echo '<label class="form-check mb-2">'
                . '<input type="checkbox" class="form-check-input" name="' . self::MA_GENERATE_FIELD . '" value="1"> '
                . '<span class="form-check-label">' . htmlspecialchars(__('Generate missing QR codes', 'companyqr'), ENT_QUOTES) . '</span>'
                . '</label><br>';
        }
        echo Html::submit(__('Print QR labels', 'companyqr'), ['name' => 'massiveaction', 'class' => 'btn btn-primary']);
        return true;
    }

    /**
     * Proceso de la acción masiva: arma (o completa) el lote en la sesión y redirige al PDF.
     * Fail-closed: sin `print` nada entra; cada activo pasa por `BulkLabelService::decide()`.
     */
    public static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids)
    {
        if ($ma->getAction() !== self::MA_PRINT_LABELS) {
            parent::processMassiveActionsForOneItemtype($ma, $item, $ids);
            return;
        }
        $itemtype = $item->getType();
        if (!Session::haveRight(self::$rightname, self::RIGHT_PRINT)) {
            $ma->itemDone($itemtype, $ids, MassiveAction::ACTION_NORIGHT);
            return;
        }

        if (!isset($_SESSION[LabelBatch::SESSION_KEY]) || !is_array($_SESSION[LabelBatch::SESSION_KEY])) {
            $_SESSION[LabelBatch::SESSION_KEY] = [];
        }
        $store = &$_SESSION[LabelBatch::SESSION_KEY];
        $key = isset($ma->POST[self::MA_BATCH_FIELD]) ? (string) $ma->POST[self::MA_BATCH_FIELD] : null;
        $userId = (int) (Session::getLoginUserID() ?: 0);
        if ($key !== null && LabelBatch::get($store, $key, $userId, time()) === null) {
            $key = null; // vencido o ajeno: se arma uno nuevo
        }
        $input = $ma->getInput();
        $plan = (new BulkLabelService())->plan(
            $itemtype,
            $ids,
            !empty($input[self::MA_GENERATE_FIELD]),
            $key !== null ? (LabelBatch::get($store, $key, $userId, time()) ?? []) : [],
            LabelBatch::clampLimit(PluginConfig::get('label_batch_max', (string) LabelBatch::DEFAULT_MAX))
        );

        // El lote se guarda ANTES de marcar ítems: itemDone() puede recargar la página (GLPI) y volver a procesar los
        // pendientes; con la clave en $ma->POST se agregan al MISMO lote, sin duplicados.
        if ($plan['codes'] !== []) {
            $key = LabelBatch::append($store, $key, $userId, $plan['codes'], time());
            $ma->POST[self::MA_BATCH_FIELD] = $key;
            global $CFG_GLPI;
            $ma->setRedirect(($CFG_GLPI['root_doc'] ?? '') . '/plugins/companyqr/labels/' . $key);
        }

        $groups = [];
        foreach ($plan['outcomes'] as $id => $outcome) {
            $groups[$outcome][] = $id;
        }
        $messages = [
            BulkLabelService::NO_CODE  => __('%d asset(s) without a QR code were skipped.', 'companyqr'),
            BulkLabelService::INACTIVE => __('%d asset(s) with a suspended or revoked QR code were skipped.', 'companyqr'),
            BulkLabelService::OVER_MAX => __('%d asset(s) exceeded the batch limit and were skipped.', 'companyqr'),
        ];
        // Los aceptados se marcan AL FINAL: si GLPI recarga antes, se reprocesan y el lote no los duplica.
        foreach ([BulkLabelService::NO_RIGHT, BulkLabelService::NO_CODE, BulkLabelService::INACTIVE, BulkLabelService::OVER_MAX, BulkLabelService::OK, BulkLabelService::GENERATE] as $outcome) {
            if (empty($groups[$outcome])) {
                continue;
            }
            if (isset($messages[$outcome])) {
                $ma->addMessage(htmlspecialchars(sprintf($messages[$outcome], count($groups[$outcome])), ENT_QUOTES));
            }
            $result = match ($outcome) {
                BulkLabelService::OK, BulkLabelService::GENERATE => MassiveAction::ACTION_OK,
                BulkLabelService::NO_RIGHT => MassiveAction::ACTION_NORIGHT,
                default => MassiveAction::ACTION_KO,
            };
            $ma->itemDone($itemtype, $groups[$outcome], $result);
        }
    }
}
