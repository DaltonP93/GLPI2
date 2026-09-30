<?php

/**
 * Parser PURO del sobre de respuesta de la API de Snipe-IT (contrato verificado en v8.7.2, ADR-0020).
 *
 * Snipe responde HTTP 200 tanto en éxito como en error de negocio:
 *   - éxito:      {"status":"success","messages":…,"payload":{…}}
 *   - error:      {"status":"error","messages":{campo:[…]}|"texto","payload":null}   (validación, "no existe")
 *   - listado:    {"total":N,"rows":[…]}                                             (sin "status")
 *   - un activo:  {…campos del activo…}                                              (sin "status")
 * Por eso el código HTTP NO alcanza para decidir: hay que mirar el cuerpo.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Client;

final class SnipeEnvelope
{
    /** ¿Es un sobre `status:"error"`? */
    public static function isError(?array $json): bool
    {
        return is_array($json) && ($json['status'] ?? null) === 'error';
    }

    /** ¿Es un sobre `status:"success"`? */
    public static function isSuccess(?array $json): bool
    {
        return is_array($json) && ($json['status'] ?? null) === 'success';
    }

    /**
     * Campos señalados por un error de validación (`messages` como objeto campo → lista). Vacío si `messages`
     * es texto (p. ej. "no existe") o no es un error.
     *
     * @return array<int,string>
     */
    public static function errorFields(?array $json): array
    {
        if (!self::isError($json) || !is_array($json['messages'] ?? null)) {
            return [];
        }
        $out = [];
        foreach (array_keys($json['messages']) as $k) {
            if (is_string($k) && $k !== '') {
                $out[] = $k;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Filas de un listado `{total, rows}`; null si el cuerpo no es un listado.
     *
     * @return array<int,array<string,mixed>>|null
     */
    public static function rows(?array $json): ?array
    {
        if (!is_array($json) || !array_key_exists('rows', $json) || !is_array($json['rows'])) {
            return null;
        }
        $out = [];
        foreach ($json['rows'] as $r) {
            if (is_array($r)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** Payload de un `status:"success"` (array) o null. @return array<string,mixed>|null */
    public static function payload(?array $json): ?array
    {
        if (!self::isSuccess($json) || !is_array($json['payload'] ?? null)) {
            return null;
        }
        return $json['payload'];
    }

    /**
     * Valor de texto tal como lo expone `AssetsTransformer` (que aplica `e()` = htmlspecialchars): se decodifica
     * para comparar con el valor original.
     */
    public static function text(mixed $v): string
    {
        return html_entity_decode(trim((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
