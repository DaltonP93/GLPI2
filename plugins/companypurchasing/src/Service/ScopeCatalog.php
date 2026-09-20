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
     * Lista de campos de un scope para una versión, leída de la tabla `scope_defs`. FAIL-CLOSED: en
     * RUNTIME NO cae silenciosamente a los defaults de código; si la definición pinneada no existe o
     * su JSON es inválido/vacío, LANZA. Los defaults viven en `defaultFields()` (seed + unit tests).
     *
     * @return array<int,string>
     * @throws \RuntimeException
     */
    public static function fields(int $version, string $scopeKey): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $raw = null;
        foreach ($DB->request([
            'SELECT' => 'fields_json',
            'FROM'   => ScopeDef::getTable(),
            'WHERE'  => ['scopes_version' => $version, 'scope_key' => $scopeKey],
            'LIMIT'  => 1,
        ]) as $row) {
            $raw = (string) $row['fields_json'];
        }
        if ($raw === null) {
            throw new \RuntimeException("scope '{$scopeKey}' v{$version} inexistente (fail-closed)");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            throw new \RuntimeException("scope '{$scopeKey}' v{$version} con definición inválida/vacía (fail-closed)");
        }
        return array_values(array_map('strval', $decoded));
    }

    /**
     * Verifica que una versión de scopes esté COMPLETA y consistente para poder usarse en una
     * aprobación (gate §Scope pinning). Lanza si falta cualquiera de los scopes baseline, su JSON es
     * inválido/vacío, o si `REQUEST_SCOPE` quedó contaminado con claves comerciales.
     *
     * @throws \RuntimeException
     */
    public static function assertVersionComplete(int $version): void
    {
        if ($version <= 0) {
            throw new \RuntimeException('scopes_version inválida (fail-closed)');
        }
        $req = self::fields($version, self::SCOPE_REQUEST);               // lanza si falta/corrupto
        $com = self::fields($version, self::SCOPE_COMMERCIAL_FINANCIAL);  // idem
        if (!self::isFreeOfCommercialKeys($req)) {
            throw new \RuntimeException("REQUEST_SCOPE v{$version} contaminado con claves comerciales (fail-closed)");
        }
        if (array_intersect(self::COMMERCIAL_ONLY_KEYS, $com) === []) {
            throw new \RuntimeException("COMMERCIAL_FINANCIAL_SCOPE v{$version} incompleto (sin claves comerciales) (fail-closed)");
        }
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
