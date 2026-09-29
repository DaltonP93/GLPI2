<?php

/**
 * `MappingResolver` en memoria (tests de contrato). MISMAS reglas que `DbMappingResolver`: compañía = exactamente un
 * mapeo aprobado para la entidad; modelo = mapeo aprobado por `category` exacta; estado configurado (> 0).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class ArrayMappingResolver implements MappingResolver
{
    /** @var array<int,array<int,int>> entidad → compañías Snipe aprobadas */
    public array $companiesByEntity;
    /** @var array<string,int> category → modelo Snipe aprobado */
    public array $modelsByCategory;
    public int $statusId;

    /** @param array<int,array<int,int>> $companiesByEntity @param array<string,int> $modelsByCategory */
    public function __construct(array $companiesByEntity, array $modelsByCategory, int $statusId)
    {
        $this->companiesByEntity = $companiesByEntity;
        $this->modelsByCategory  = $modelsByCategory;
        $this->statusId          = $statusId;
    }

    public function resolve(array $payload): array
    {
        return MappingRules::decide(
            $this->companiesByEntity[(int) ($payload['entity_id'] ?? -1)] ?? [],
            $this->modelsByCategory[(string) ($payload['category'] ?? '')] ?? 0,
            $this->statusId
        );
    }
}
