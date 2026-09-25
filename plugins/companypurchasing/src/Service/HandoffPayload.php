<?php

/**
 * Payload VERSIONADO e INMUTABLE del handoff de inventario a SI-4 (P2D-3; gate §7). PURO (sin GLPI).
 *
 * Contiene SÓLO el hecho de negocio recibido (identidad canónica de la unidad, línea, entidad, serial,
 * proveedor, costo exacto, fecha y correlación). Sin secretos ni datos de infraestructura. `receipt_unit_uuid`
 * es la identidad de idempotencia del consumidor (buscar-primero antes de crear en Snipe-IT/GLPI).
 *
 * `canonical()` (claves ordenadas, importes como string exacto, jamás float) + `hash()` fijan su identidad;
 * `verify()` detecta cualquier alteración del JSON almacenado (fail-closed).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class HandoffPayload
{
    public const SCHEMA_VERSION = 1;

    /** Claves EXACTAS del schema v1 (ni una más: nada de infraestructura ni secretos). */
    public const KEYS = [
        'schema_version', 'receipt_unit_uuid', 'request_id', 'request_number', 'item_id', 'entity_id',
        'serial', 'description', 'category', 'supplier_id', 'currency', 'unit_cost', 'received_at',
        'correlation_id',
    ];

    public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /**
     * Construye y VALIDA el payload v1 (fail-closed ante cualquier campo inválido o float).
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public static function build(array $in): array
    {
        $p = [
            'schema_version'    => self::SCHEMA_VERSION,
            'receipt_unit_uuid' => (string) ($in['receipt_unit_uuid'] ?? ''),
            'request_id'        => $in['request_id'] ?? 0,
            'request_number'    => (string) ($in['request_number'] ?? ''),
            'item_id'           => $in['item_id'] ?? 0,
            'entity_id'         => $in['entity_id'] ?? -1,
            'serial'            => $in['serial'] ?? null,
            'description'       => (string) ($in['description'] ?? ''),
            'category'          => (string) ($in['category'] ?? ''),
            'supplier_id'       => $in['supplier_id'] ?? 0,
            'currency'          => (string) ($in['currency'] ?? ''),
            'unit_cost'         => $in['unit_cost'] ?? null,
            'received_at'       => (string) ($in['received_at'] ?? ''),
            'correlation_id'    => (string) ($in['correlation_id'] ?? ''),
        ];
        self::validate($p);
        ksort($p, SORT_STRING);
        return $p;
    }

    /**
     * @param array<string,mixed> $p
     * @throws \InvalidArgumentException
     */
    public static function validate(array $p): void
    {
        $keys = array_keys($p);
        sort($keys, SORT_STRING);
        $expected = self::KEYS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException('payload de handoff con claves inesperadas (fail-closed)');
        }
        if ($p['schema_version'] !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException('schema_version de handoff desconocido (fail-closed)');
        }
        if (preg_match(self::UUID_PATTERN, $p['receipt_unit_uuid']) !== 1) {
            throw new \InvalidArgumentException('receipt_unit_uuid inválido (fail-closed)');
        }
        foreach (['request_id', 'item_id', 'supplier_id'] as $k) {
            if (!is_int($p[$k]) || $p[$k] <= 0) {
                throw new \InvalidArgumentException("{$k} inválido en el handoff (fail-closed)");
            }
        }
        if (!is_int($p['entity_id']) || $p['entity_id'] < 0) {
            throw new \InvalidArgumentException('entity_id inválido en el handoff (fail-closed)');
        }
        if ($p['serial'] !== null && (!is_string($p['serial']) || $p['serial'] === '')) {
            throw new \InvalidArgumentException('serial inválido en el handoff (fail-closed)');
        }
        if (!CurrencyPolicy::isWellFormed($p['currency'])) {
            throw new \InvalidArgumentException('moneda inválida en el handoff (fail-closed)');
        }
        // Importe como STRING exacto a la escala de la moneda (nunca float): Money::of lo valida.
        if (!is_string($p['unit_cost'])) {
            throw new \InvalidArgumentException('unit_cost debe ser string exacto (fail-closed)');
        }
        Money::of($p['unit_cost'], $p['currency']);
        if ($p['request_number'] === '' || $p['received_at'] === '') {
            throw new \InvalidArgumentException('handoff sin número de solicitud o fecha de recepción (fail-closed)');
        }
    }

    /** @param array<string,mixed> $payload ya construido con `build()` */
    public static function canonical(array $payload): string
    {
        ksort($payload, SORT_STRING);
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $payload */
    public static function hash(array $payload): string
    {
        return hash('sha256', self::canonical($payload));
    }

    /**
     * ¿El JSON almacenado es un payload v1 válido, en forma canónica y con el hash registrado? PURO.
     * Devuelve el payload decodificado o null (alterado/ilegible ⇒ fail-closed para el llamador).
     *
     * @return array<string,mixed>|null
     */
    public static function verify(string $json, string $sha256): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1 || !hash_equals($sha256, hash('sha256', $json))) {
            return null;
        }
        $p = json_decode($json, true);
        if (!is_array($p)) {
            return null;
        }
        try {
            self::validate($p);
        } catch (\Throwable) {
            return null;
        }
        return self::canonical($p) === $json ? $p : null;
    }
}
