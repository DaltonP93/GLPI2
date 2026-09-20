<?php

/**
 * Catálogo VERSIONADO de scopes de aprobación (gate §Approval scopes).
 *
 * Cada versión define, por `scope_key`, la lista ORDENADA de claves de campo que ese scope protege.
 * Las definiciones viven en la tabla `scope_defs` (configurable/versionado); este servicio expone la
 * lectura con un FALLBACK determinista a los defaults en código (útil para pruebas puras sin BD y para
 * sembrar la versión 1 en la instalación).
 *
 * REGLA CLAVE: `REQUEST_SCOPE` (lo que aprueba el jefe) NO incluye proveedor/cotización/precio final;
 * esos son de `COMMERCIAL_FINANCIAL_SCOPE`.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use GlpiPlugin\Companypurchasing\Model\ScopeDef;

final class ScopeCatalog
{
    /**
     * Claves canónicas de scope (fuente única; `Model\ScopeDef` expone los mismos valores). Se definen
     * aquí como literales para que este catálogo sea PURO (unit-testable sin cargar el modelo CommonDBTM).
     */
    public const SCOPE_REQUEST              = 'REQUEST_SCOPE';
    public const SCOPE_COMMERCIAL_FINANCIAL = 'COMMERCIAL_FINANCIAL_SCOPE';

    /** Claves EXCLUSIVAMENTE comerciales/financieras: nunca deben aparecer en REQUEST_SCOPE. */
    public const COMMERCIAL_ONLY_KEYS = [
        'currency', 'suppliers_id_selected', 'selected_quote', 'final_prices',
        'discounts', 'taxes', 'freight', 'total',
    ];

    /**
     * Defaults en código (versión 1). El jefe aprueba REQUEST_SCOPE; Compras/Gerencia el superconjunto
     * comercial/financiero.
     *
     * @var array<int,array<string,array<int,string>>>
     */
    public const DEFAULTS = [
        1 => [
            self::SCOPE_REQUEST => [
                'requester', 'department', 'category', 'destination', 'reason', 'observations',
                'budget', 'lines',
            ],
            self::SCOPE_COMMERCIAL_FINANCIAL => [
                // superconjunto: lo protegido de REQUEST_SCOPE …
                'requester', 'department', 'category', 'destination', 'reason', 'observations',
                'budget', 'lines',
                // … más lo comercial/financiero
                'currency', 'suppliers_id_selected', 'selected_quote', 'final_prices',
                'discounts', 'taxes', 'freight', 'total',
            ],
        ],
    ];

    /** Lista de campos de un scope según los DEFAULTS de código (pura, sin BD). @return array<int,string> */
    public static function defaultFields(string $scopeKey, int $version = 1): array
    {
        return self::DEFAULTS[$version][$scopeKey] ?? [];
    }

    /** ¿La lista de campos NO contiene ninguna clave comercial? (invariante de REQUEST_SCOPE). */
    public static function isFreeOfCommercialKeys(array $fields): bool
    {
        return array_intersect($fields, self::COMMERCIAL_ONLY_KEYS) === [];
    }

    /**
     * Lista de campos de un scope para una versión, leída de la tabla `scope_defs`; si no hay filas
     * (BD sin sembrar o versión desconocida) cae al default de código. @return array<int,string>
     */
    public static function fields(int $version, string $scopeKey): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        if (!isset($DB) || !$DB->tableExists(ScopeDef::getTable())) {
            return self::defaultFields($scopeKey, $version);
        }
        foreach ($DB->request([
            'SELECT' => 'fields_json',
            'FROM'   => ScopeDef::getTable(),
            'WHERE'  => ['scopes_version' => $version, 'scope_key' => $scopeKey],
            'LIMIT'  => 1,
        ]) as $row) {
            $decoded = json_decode((string) $row['fields_json'], true);
            if (is_array($decoded)) {
                return array_values(array_map('strval', $decoded));
            }
        }
        return self::defaultFields($scopeKey, $version);
    }

    /** Siembra en BD la versión 1 (idempotente). Se llama en la instalación. */
    public static function seedVersion1(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $table = ScopeDef::getTable();
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        foreach (self::DEFAULTS[1] as $scopeKey => $fields) {
            $exists = false;
            foreach ($DB->request([
                'SELECT' => 'id',
                'FROM'   => $table,
                'WHERE'  => ['scopes_version' => 1, 'scope_key' => $scopeKey],
                'LIMIT'  => 1,
            ]) as $ignored) {
                $exists = true;
            }
            if (!$exists) {
                (new ScopeDef())->add([
                    'scopes_version' => 1,
                    'scope_key'      => $scopeKey,
                    'fields_json'    => json_encode(array_values($fields), JSON_UNESCAPED_UNICODE),
                    'date_creation'  => $now,
                ]);
            }
        }
    }
}
