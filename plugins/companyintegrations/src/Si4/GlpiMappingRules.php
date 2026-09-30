<?php

/**
 * Regla PURA del mapeo categoría ⇒ destino GLPI (SI4-2, ADR-0021 §2): sin fila aprobada, itemtype vacío o modelo
 * negativo ⇒ bloqueado (BLOCKED_CONFIG). Que el itemtype esté SOPORTADO y el modelo EXISTA lo comprueba el gateway.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class GlpiMappingRules
{
    /**
     * @param array<int,array{glpi_itemtype:string, glpi_model_id:int}> $approvedRows filas APROBADAS para la categoría exacta
     * @return array{ok:bool, itemtype:string, model_id:int, reason:string}
     */
    public static function decide(string $category, array $approvedRows): array
    {
        if ($category === '') {
            return self::no('la unidad no trae categoría');
        }
        if ($approvedRows === []) {
            return self::no('mapping ausente: categoría sin tipo de activo GLPI aprobado (map_glpi_assettypes)');
        }
        if (count($approvedRows) > 1) {
            return self::no('mapping ambiguo: más de un tipo de activo GLPI aprobado para la categoría');
        }
        $row = $approvedRows[0];
        $itemtype = (string) $row['glpi_itemtype'];
        $model = (int) $row['glpi_model_id'];
        if (preg_match('/^[A-Za-z][A-Za-z0-9_\\\\]{0,99}$/', $itemtype) !== 1) {
            return self::no('mapping inválido: itemtype GLPI vacío o mal formado');
        }
        if ($model < 0) {
            return self::no('mapping inválido: modelo GLPI negativo');
        }
        return ['ok' => true, 'itemtype' => $itemtype, 'model_id' => $model, 'reason' => ''];
    }

    /** @return array{ok:bool, itemtype:string, model_id:int, reason:string} */
    private static function no(string $reason): array
    {
        return ['ok' => false, 'itemtype' => '', 'model_id' => 0, 'reason' => $reason];
    }
}
