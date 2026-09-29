<?php

/**
 * Payload VERSIONADO e INMUTABLE del handoff de inventario a SI-4 (P2D-3; gate §7). PURO (sin GLPI).
 *
 * Contiene SÓLO el hecho de negocio recibido (identidad canónica de la unidad, línea, entidad, serial,
 * proveedor, costo exacto, fecha y correlación). Sin secretos ni datos de infraestructura. `receipt_unit_uuid`
 * es la identidad de idempotencia del consumidor (buscar-primero antes de crear en Snipe-IT/GLPI).
 *
 * AUTOSUFICIENTE respecto del dinero: `currency` + `currency_scale` (la escala PINNEADA en la política de costo
 * de la compra) + `unit_cost` como string exacto con EXACTAMENTE esa escala. Validar un payload histórico no
 * consulta la configuración vigente de Compras; la única regla externa es la dura: PYG ⇒ escala 0.
 *
 * `canonical()` (claves ordenadas, importes como string exacto, jamás float) + `hash()` fijan su identidad;
 * `verify()` detecta cualquier alteración del JSON almacenado y `validatedPayload()` además lo ata a su fila del
 * outbox y a su unidad (fail-closed): ÚNICA validación que usan `claimPending()` y `getHandoff()`.
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
        'serial', 'description', 'category', 'supplier_id', 'currency', 'currency_scale', 'unit_cost',
        'received_at', 'correlation_id',
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
            'currency_scale'    => $in['currency_scale'] ?? null,
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
        foreach (['receipt_unit_uuid', 'description', 'category', 'correlation_id'] as $k) {
            if (!is_string($p[$k])) {
                throw new \InvalidArgumentException("{$k} inválido en el handoff (fail-closed)");
            }
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
        if (!is_string($p['currency']) || !CurrencyPolicy::isWellFormed($p['currency'])) {
            throw new \InvalidArgumentException('moneda inválida en el handoff (fail-closed)');
        }
        // Escala EXPLÍCITA del payload (la pinneada de la compra), nunca la configuración vigente. PYG ⇒ 0.
        if (!is_int($p['currency_scale']) || !CurrencyPolicy::allows($p['currency'], $p['currency_scale'])) {
            throw new \InvalidArgumentException('currency_scale inválida en el handoff (PYG ⇒ 0) (fail-closed)');
        }
        // Importe como STRING exacto y CANÓNICO con exactamente `currency_scale` decimales (nunca float).
        if (!is_string($p['unit_cost']) || !self::isCanonicalAmount($p['unit_cost'], $p['currency_scale'])) {
            throw new \InvalidArgumentException('unit_cost debe ser string exacto a la escala declarada (fail-closed)');
        }
        if (!is_string($p['request_number']) || !is_string($p['received_at']) || $p['request_number'] === '' || $p['received_at'] === '') {
            throw new \InvalidArgumentException('handoff sin número de solicitud o fecha de recepción (fail-closed)');
        }
    }

    /** ¿`$amount` es el decimal no negativo canónico con EXACTAMENTE `$scale` decimales? (p. ej. "3.667" a escala 3). */
    public static function isCanonicalAmount(string $amount, int $scale): bool
    {
        if (!Decimal::isValid($amount, $scale)) {
            return false;
        }
        try {
            return Decimal::formatMicro(Decimal::toMicro($amount), $scale) === $amount;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Validación ÚNICA de un handoff almacenado (la usan `claimPending()` y `getHandoff()`). PURA.
     *
     * Exige: `payload_version` de la fila = SCHEMA_VERSION; JSON canónico que reproduce `payload_sha256`; payload
     * v1 válido; identidad redundante coherente con la FILA (uuid, solicitud, entidad) y con la UNIDAD recibida
     * que referencia (`receipt_units_id`: uuid, solicitud, entidad, línea, moneda, costo exacto, serial).
     * Recalcular el hash tras editar el JSON NO alcanza: la identidad no coincide con la fila/unidad.
     *
     * @param array<string,mixed>      $row  fila del outbox
     * @param array<string,mixed>|null $unit fila de `receipt_units` con id = `$row['receipt_units_id']`
     * @return array<string,mixed> payload decodificado
     * @throws \RuntimeException con el motivo (fail-closed)
     */
    public static function validatedPayload(array $row, ?array $unit): array
    {
        if ((int) ($row['payload_version'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('payload_version del handoff desconocida (fail-closed)');
        }
        $p = self::verify((string) ($row['payload_json'] ?? ''), (string) ($row['payload_sha256'] ?? ''));
        if ($p === null) {
            throw new \RuntimeException('payload del handoff alterado, no canónico o inválido (fail-closed)');
        }
        $uuid = (string) ($row['receipt_unit_uuid'] ?? '');
        if ($p['receipt_unit_uuid'] !== $uuid || $p['request_id'] !== (int) ($row['requests_id'] ?? 0)
            || $p['entity_id'] !== (int) ($row['entities_id'] ?? -1)) {
            throw new \RuntimeException('identidad del payload distinta a la de su fila del outbox (fail-closed)');
        }
        if ($unit === null || (int) ($unit['id'] ?? 0) <= 0 || (int) $unit['id'] !== (int) ($row['receipt_units_id'] ?? 0)) {
            throw new \RuntimeException('handoff sin su unidad recibida (fail-closed)');
        }
        $unitCost = (string) ($unit['unit_cost'] ?? '');
        $sameCost = Decimal::isValid($unitCost, Decimal::SCALE) && Decimal::toMicro($unitCost) === Decimal::toMicro($p['unit_cost']);
        $unitSerial = $unit['serial'] ?? null;
        if ((string) ($unit['receipt_unit_uuid'] ?? '') !== $uuid || (int) ($unit['requests_id'] ?? 0) !== $p['request_id']
            || (int) ($unit['entities_id'] ?? -1) !== $p['entity_id'] || (int) ($unit['items_id'] ?? 0) !== $p['item_id']
            || (string) ($unit['currency_code'] ?? '') !== $p['currency'] || !$sameCost
            || ($unitSerial === null ? null : (string) $unitSerial) !== $p['serial']) {
            throw new \RuntimeException('payload del handoff incoherente con la unidad recibida (fail-closed)');
        }
        return $p;
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
