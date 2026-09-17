<?php

/**
 * Canonicalizador DETERMINISTA del contenido aprobado (decisión D4, domain-agnostic).
 *
 * `companysignature` NO decide campos de negocio: recibe un *snapshot canónico* con el contrato
 *   { schema, subject_type, subject_id, entity_id, document_version, payload }
 * donde el DOMINIO (p. ej. companypurchasing, aún no construido) suministra `payload`.
 *
 * Reglas de canonicalización (deterministas y demostrables — ver gate §12 D4 y §14):
 *   - UTF-8; **orden determinista de claves** (recursivo, por clave string);
 *   - los arrays de lista **preservan su orden semántico** (no se reordenan);
 *   - `schema` y `document_version` participan del hash (van dentro del snapshot);
 *   - valores monetarios/decimales deben venir como **representación exacta** (string/int),
 *     **NUNCA floats binarios**: un float en el snapshot es una violación de contrato y se
 *     RECHAZA (fail-closed) para no fijar un hash sobre una representación no reproducible;
 *   - `null`, boolean y strings se serializan normalizados (JSON, sin escapes de Unicode/slash).
 *
 * Clase PURA (sin dependencias de GLPI) para poder testearla sin bootstrap.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

final class Canonicalizer
{
    /** Claves obligatorias del sobre (envelope) del snapshot. */
    private const REQUIRED = ['schema', 'subject_type', 'subject_id', 'entity_id', 'document_version', 'payload'];

    /**
     * Devuelve la representación canónica (JSON determinista) del snapshot completo.
     *
     * @param array<string,mixed> $snapshot
     * @throws \InvalidArgumentException  contrato inválido o valor no canonicalizable (p. ej. float)
     */
    public function canonical(array $snapshot): string
    {
        foreach (self::REQUIRED as $k) {
            if (!array_key_exists($k, $snapshot)) {
                throw new \InvalidArgumentException("snapshot canónico inválido: falta la clave '{$k}'");
            }
        }
        if ((string) $snapshot['schema'] === '') {
            throw new \InvalidArgumentException('snapshot canónico inválido: `schema` vacío');
        }
        if (!is_array($snapshot['payload'])) {
            throw new \InvalidArgumentException('snapshot canónico inválido: `payload` debe ser un array/objeto');
        }

        // Sólo canonicalizamos las claves del contrato (ignoramos ruido externo), en orden fijo.
        $envelope = [
            'schema'           => (string) $snapshot['schema'],
            'subject_type'     => (string) $snapshot['subject_type'],
            'subject_id'       => (int) $snapshot['subject_id'],
            'entity_id'        => (int) $snapshot['entity_id'],
            'document_version' => (int) $snapshot['document_version'],
            'payload'          => $snapshot['payload'],
        ];

        $normalized = $this->normalize($envelope);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \InvalidArgumentException('no se pudo serializar el snapshot canónico: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * Normaliza recursivamente:
     *   - array asociativo → ordena claves por string (determinista);
     *   - array de lista    → preserva el orden;
     *   - float             → RECHAZA (fail-closed);
     *   - escalares         → tal cual (string/int/bool/null).
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new \InvalidArgumentException(
                'valor float en el snapshot canónico: los decimales/montos deben venir como string exacto, no como float'
            );
        }
        if (!is_array($value)) {
            // string, int, bool, null → representación JSON estable.
            return $value;
        }

        if ($this->isList($value)) {
            // Lista: preserva orden semántico; normaliza cada elemento.
            $out = [];
            foreach ($value as $item) {
                $out[] = $this->normalize($item);
            }
            return $out;
        }

        // Objeto asociativo: orden determinista de claves (string) + normalización recursiva.
        $keys = array_map('strval', array_keys($value));
        sort($keys, SORT_STRING);
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->normalize($value[$k]);
        }
        // Forzar objeto JSON aun si quedara vacío (json_encode([]) sería "[]", no "{}").
        return $out === [] ? new \stdClass() : $out;
    }

    /** ¿El array es una lista secuencial 0..n-1? (compat 8.0: no usar array_is_list del core). */
    private function isList(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        $i = 0;
        foreach ($arr as $k => $_) {
            if ($k !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }
}
