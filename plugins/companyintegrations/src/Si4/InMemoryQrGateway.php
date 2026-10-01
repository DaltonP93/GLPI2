<?php

/**
 * DOBLE DE PRUEBA de `QrGateway` con la semántica documentada de `CompanyQrApi` (tests de contrato/crash sin GLPI):
 * un código por activo (UNIQUE item, también en carrera), `public_code` = número de inventario si está libre, ACL
 * (derechos generate/print + entidad visible), nada de rotar/reactivar, PDF sólo de códigos ACTIVOS. El token vive SÓLO
 * aquí (como en la tabla de companyqr). La implementación real se prueba en el selftest de GLPI.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class InMemoryQrGateway implements QrGateway
{
    /** @var array<int,array{code_id:int, public_code:string, status:string, itemtype:string, items_id:int, entities_id:int, token:string}> */
    public array $codes = [];
    public bool $available = true;
    public bool $canGenerate = true;
    public bool $canPrint = true;
    public bool $renderFails = false;
    public ?string $renderOutput = null;
    /** Perilla de prueba: emula una API NO conforme que devuelve el token (la guarda de `QrCodeRules` debe frenarla). */
    public bool $leakToken = false;
    /** @var callable(string,int):void|null se ejecuta entre "buscar" y "crear" (emula otro proceso en carrera) */
    public $beforeCreate = null;
    public int $ensureCalls = 0;
    public int $createCalls = 0;
    public int $renderCalls = 0;
    private int $seq = 900;

    public function __construct(private InMemoryGlpiAssets $glpi)
    {
    }

    public function rightsProblem(): ?string
    {
        if (!$this->available) {
            return 'companyqr no está disponible (plugin inactivo o sin CompanyQrApi)';
        }
        if (!$this->canGenerate) {
            return 'permiso denegado (companyqr RIGHT_GENERATE)';
        }
        if (!$this->canPrint) {
            return 'permiso denegado (companyqr RIGHT_PRINT)';
        }
        return null;
    }

    public function ensureForItem(string $itemtype, int $itemsId): array
    {
        $this->assertAvailable();
        if (!$this->canGenerate) {
            throw new QrGatewayException(QrGatewayException::ACL, 'permiso denegado (companyqr generate)');
        }
        $item = $this->visibleItem($itemtype, $itemsId);
        $this->ensureCalls++;
        $existed = $this->find($itemtype, $itemsId) !== null;
        if (!$existed && $this->beforeCreate !== null) {
            ($this->beforeCreate)($itemtype, $itemsId);
        }
        $row = $this->find($itemtype, $itemsId) ?? $this->create($itemtype, $itemsId, $item); // UNIQUE item: la carrera reutiliza
        return $this->meta($row) + ['outcome' => $existed ? SagaState::OUTCOME_EXISTING : SagaState::OUTCOME_CREATED];
    }

    public function getCode(int $codeId): ?array
    {
        $this->assertAvailable();
        $row = $this->codes[$codeId] ?? null;
        if ($row === null) {
            return null;
        }
        $this->visibleItem($row['itemtype'], $row['items_id']);
        return $this->meta($row);
    }

    public function renderLabelPdf(int $codeId): string
    {
        $this->assertAvailable();
        if (!$this->canPrint) {
            throw new QrGatewayException(QrGatewayException::ACL, 'permiso denegado (companyqr print)');
        }
        $row = $this->codes[$codeId] ?? null;
        if ($row === null) {
            throw new QrGatewayException(QrGatewayException::NOT_FOUND, 'código #' . $codeId . ' inexistente');
        }
        $this->visibleItem($row['itemtype'], $row['items_id']);
        if ($row['status'] !== QrCodeRules::STATUS_ACTIVE) {
            throw new QrGatewayException(QrGatewayException::INACTIVE, 'código #' . $codeId . ' no activo');
        }
        if ($this->renderFails) {
            throw new QrGatewayException(QrGatewayException::RENDER, 'no se pudo generar la etiqueta');
        }
        $this->renderCalls++;
        return $this->renderOutput ?? "%PDF-1.7\n% etiqueta " . $row['public_code'] . "\n" . str_repeat('0', 96) . "\n%%EOF\n";
    }

    /** Códigos del activo (debe ser 0 o 1). */
    public function countFor(string $itemtype, int $itemsId): int
    {
        return count(array_filter($this->codes, static fn (array $c): bool => $c['itemtype'] === $itemtype && $c['items_id'] === $itemsId));
    }

    /** @return array{code_id:int, public_code:string, status:string, itemtype:string, items_id:int, entities_id:int, token:string}|null */
    public function find(string $itemtype, int $itemsId): ?array
    {
        foreach ($this->codes as $c) {
            if ($c['itemtype'] === $itemtype && $c['items_id'] === $itemsId) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Alta directa (emula `CodeManager::createForItem`, también para la "otra" parte de una carrera o un código
     * preexistente creado por un humano).
     *
     * @param array<string,mixed>|null $item
     * @return array{code_id:int, public_code:string, status:string, itemtype:string, items_id:int, entities_id:int, token:string}
     */
    public function create(string $itemtype, int $itemsId, ?array $item = null, ?string $publicCode = null): array
    {
        if ($this->find($itemtype, $itemsId) !== null) {
            throw new \RuntimeException('UNIQUE item: el activo ya tiene código');
        }
        $item ??= $this->glpi->get($itemtype, $itemsId) ?? [];
        $other = trim((string) ($item['otherserial'] ?? ''));
        $taken = array_filter($this->codes, static fn (array $c): bool => $c['public_code'] === $other);
        $id = ++$this->seq;
        $this->createCalls++;
        return $this->codes[$id] = [
            'code_id' => $id, 'public_code' => $publicCode ?? ($other !== '' && $taken === [] ? $other : sprintf('PC-%06d', $id)),
            'status' => QrCodeRules::STATUS_ACTIVE, 'itemtype' => $itemtype, 'items_id' => $itemsId,
            'entities_id' => (int) ($item['entities_id'] ?? 0), 'token' => bin2hex(random_bytes(20)),
        ];
    }

    /** @param array{code_id:int, public_code:string, status:string, itemtype:string, items_id:int, entities_id:int, token:string} $row */
    private function meta(array $row): array
    {
        $meta = array_intersect_key($row, array_flip(QrCodeRules::META_KEYS));
        return $this->leakToken ? $meta + ['token' => $row['token']] : $meta;
    }

    /** @return array<string,mixed> */
    private function visibleItem(string $itemtype, int $itemsId): array
    {
        $item = $this->glpi->get($itemtype, $itemsId);
        if ($item === null) {
            throw new QrGatewayException(QrGatewayException::NOT_FOUND, $itemtype . ' #' . $itemsId . ' inexistente');
        }
        if (empty($this->glpi->entityAccess[(int) $item['entities_id']])) {
            throw new QrGatewayException(QrGatewayException::ACL, 'el usuario no ve ' . $itemtype . ' #' . $itemsId);
        }
        return $item;
    }

    private function assertAvailable(): void
    {
        if (!$this->available) {
            throw new QrGatewayException(QrGatewayException::UNAVAILABLE, 'companyqr no está disponible');
        }
    }
}
