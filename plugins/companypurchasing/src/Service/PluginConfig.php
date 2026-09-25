<?php

/**
 * Acceso a la configuración del plugin (contexto Config `plugin:companypurchasing`).
 *
 * Usa la API soportada de GLPI (`Config::getConfigurationValues`), sin SQL directo ni secretos.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use Config;

final class PluginConfig
{
    public const CONTEXT = 'plugin:companypurchasing';

    /** Valores por defecto (se persisten en la instalación). */
    public const DEFAULTS = [
        // Moneda por defecto de nuevas solicitudes.
        'default_currency'      => 'PYG',
        // Versión vigente del catálogo de scopes de aprobación (§approval scopes). Se pinnea en la
        // solicitud al abandonar DRAFT para que una edición administrativa posterior NO cambie su
        // semántica retroactivamente.
        'current_scopes_version' => '1',
        // Overrides de escala por moneda (JSON {"USD":2,...}); PYG es SIEMPRE 0 (no overridable).
        'currency_scale_overrides' => '{}',

        // --- P2D-2: circuito de aprobación sobre companyworkflow (NADA de aprobadores hardcodeados) ---
        // Código de la definición publicada en companyworkflow.
        'workflow_code'              => 'companypurchasing_request',
        // Grupo aprobador por etapa (0 = sin configurar ⇒ publicar la definición falla cerrado).
        'approver_group_area_head'   => '0',
        'approver_group_purchasing'  => '0',
        'approver_group_finance'     => '0',
        // Quórum (cantidad de aprobaciones) por etapa.
        'quorum_area_head'           => '1',
        'quorum_purchasing'          => '1',
        'quorum_finance'             => '1',
        // SLA (horas) por etapa; vacío = sin SLA.
        'sla_hours_area_head'        => '',
        'sla_hours_purchasing'       => '',
        'sla_hours_finance'          => '',
        // --- Política de aprobación: se PINNEA por solicitud al abandonar DRAFT (ver PolicyStore); un cambio
        //     aquí sólo afecta a solicitudes NUEVAS. ---
        // Scope que protege cada etapa de aprobación y checkpoint (estado) que reabre cada scope.
        'stage_scopes'               => '{"PENDING_AREA_HEAD":"REQUEST_SCOPE","PURCHASING":"COMMERCIAL_FINANCIAL_SCOPE","PENDING_FINANCE":"COMMERCIAL_FINANCIAL_SCOPE"}',
        'scope_checkpoints'          => '{"REQUEST_SCOPE":"PENDING_AREA_HEAD","COMMERCIAL_FINANCIAL_SCOPE":"PURCHASING"}',
        // Etapas cuya aprobación compone el PDF aprobado (composePdf explícito; no depende de Cron).
        'pdf_stages'                 => '["PENDING_AREA_HEAD","PENDING_FINANCE"]',
        // Estados donde Compras gestiona cotizaciones / enmienda cantidades (tras la aprobación del jefe).
        'quote_states'               => '["PURCHASING","PENDING_FINANCE","APPROVED"]',
        'amend_states'               => '["PURCHASING","PENDING_FINANCE","APPROVED"]',
        // Proyección inmediata de domain_state al escuchar eventos del motor (operacional, NO pinneada).
        'sync_on_workflow_events'    => '1',
        // Estado operacional de la reconciliación por lotes: último id revisado (cursor con wrap-around).
        'reconcile_cursor'           => '0',
    ];

    /** Sufijo de configuración por etapa de aprobación (clave estable, no dato de negocio). */
    public const STAGE_CONFIG_KEYS = [
        'PENDING_AREA_HEAD' => 'area_head',
        'PURCHASING'        => 'purchasing',
        'PENDING_FINANCE'   => 'finance',
    ];

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return Config::getConfigurationValues(self::CONTEXT);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $conf = self::all();
        return $conf[$key] ?? self::DEFAULTS[$key] ?? $default;
    }

    public static function defaultCurrency(): string
    {
        $c = strtoupper((string) self::get('default_currency', 'PYG'));
        return CurrencyPolicy::isWellFormed($c) ? $c : 'PYG';
    }

    public static function currentScopesVersion(): int
    {
        return max(1, (int) self::get('current_scopes_version', '1'));
    }

    public static function workflowCode(): string
    {
        return (string) self::get('workflow_code', 'companypurchasing_request');
    }

    /**
     * Configuración de etapas para `PurchasingWorkflow::spec()` (grupos/quórum/SLA por etapa).
     * @return array{groups:array<string,int>, quorum:array<string,int>, sla:array<string,?int>}
     */
    public static function stageConfig(): array
    {
        $out = ['groups' => [], 'quorum' => [], 'sla' => []];
        foreach (self::STAGE_CONFIG_KEYS as $stage => $suffix) {
            $out['groups'][$stage] = (int) self::get('approver_group_' . $suffix, '0');
            $out['quorum'][$stage] = (int) self::get('quorum_' . $suffix, '1');
            $sla = (string) self::get('sla_hours_' . $suffix, '');
            $out['sla'][$stage] = ($sla === '' || (int) $sla <= 0) ? null : (int) $sla;
        }
        return $out;
    }

    /** @return array<string,string> etapa → scope (fail-closed si el JSON es inválido) */
    public static function stageScopes(): array
    {
        return self::jsonMap('stage_scopes');
    }

    /** @return array<string,string> scope → checkpoint (estado que reabre) */
    public static function scopeCheckpoints(): array
    {
        return self::jsonMap('scope_checkpoints');
    }

    /** @return array<int,string> */
    public static function pdfStages(): array
    {
        return self::jsonList('pdf_stages');
    }

    /** @return array<int,string> */
    public static function quoteStates(): array
    {
        return self::jsonList('quote_states');
    }

    /** @return array<int,string> */
    public static function amendStates(): array
    {
        return self::jsonList('amend_states');
    }

    public static function syncOnWorkflowEvents(): bool
    {
        return (string) self::get('sync_on_workflow_events', '1') === '1';
    }

    /** @return array<string,string> */
    private static function jsonMap(string $key): array
    {
        $decoded = json_decode((string) self::get($key, ''), true);
        if (!is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            throw new \RuntimeException("configuración '{$key}' inválida (fail-closed)");
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            $out[(string) $k] = (string) $v;
        }
        return $out;
    }

    /** @return array<int,string> */
    private static function jsonList(string $key): array
    {
        $decoded = json_decode((string) self::get($key, ''), true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \RuntimeException("configuración '{$key}' inválida (fail-closed)");
        }
        return array_values(array_map('strval', $decoded));
    }

    /** @return array<string,int> */
    public static function currencyScaleOverrides(): array
    {
        $raw = (string) self::get('currency_scale_overrides', '{}');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $cur => $scale) {
            if (is_string($cur) && CurrencyPolicy::isWellFormed(strtoupper($cur))) {
                $out[strtoupper($cur)] = (int) $scale;
            }
        }
        return $out;
    }
}
