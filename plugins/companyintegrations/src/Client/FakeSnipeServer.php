<?php

/**
 * DOBLE DE PRUEBA con estado de la API de Snipe-IT (contract tests de SI4-1). NUNCA se usa en producción
 * (`SnipeConfigFactory`/`Si4RunCommand` sólo instancian `CurlTransport`), igual que `ArrayTransport`.
 *
 * Emula el contrato VERIFICADO de Snipe-IT v8.7.2 (ADR-0020), no una API idealizada:
 *   - POST /api/v1/hardware  → HTTP 200 + {"status":"success","payload":{…}} o HTTP 200 + {"status":"error",
 *     "messages":{campo:[…]}}; `asset_tag` único entre activos VIVOS (comparación sin mayúsculas, como la
 *     colación de MySQL); `serial` único sólo si `uniqueSerial`; model/status/company deben existir.
 *   - GET /api/v1/hardware/bytag/{tag}[?deleted=true] → activo, listado `{total, rows}` o HTTP 200 + status:error.
 *   - GET /api/v1/statuslabels/{id} → fila o HTTP 200 + status:error.
 *   - 401 si falta/no coincide el Bearer; fallas inyectables: timeout/5xx ANTES o DESPUÉS de ejecutar, 401, 403,
 *     409, 429 (+Retry-After) y una creación "concurrente" del mismo tag justo antes de atender el POST.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Client;

final class FakeSnipeServer implements HttpTransport
{
    public const TIMEOUT_BEFORE = 'timeout_before'; // no llega a ejecutarse
    public const TIMEOUT_AFTER  = 'timeout_after';  // se ejecuta y la respuesta se pierde (INCIERTO)
    public const S500_BEFORE    = '500_before';
    public const S500_AFTER     = '500_after';      // se ejecuta y responde 500 (INCIERTO)
    public const S401           = '401';
    public const S403           = '403';
    public const S409           = '409';
    public const S429           = '429';
    public const RACE_CREATE    = 'race_create';    // otro proceso crea el mismo tag justo antes de este POST
    public const LEAKY_ERROR    = 'leaky_error';    // error de transporte cuyo mensaje incluye el header Authorization

    /** @var array<int,array<string,mixed>> id → activo */
    public array $assets = [];
    /** @var array<int,true> */
    public array $statusLabels = [];
    /** @var array<int,true> */
    public array $models = [];
    /** @var array<int,true> */
    public array $companies = [];
    public bool $uniqueSerial = true;
    /** @var array<int,array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $requests = [];
    public int $postCount = 0;

    private string $token;
    private int $nextId = 1000;
    /** @var array<int,array{method:string,prefix:string,fault:string,retryAfter:int}> */
    private array $faults = [];

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /** Programa una falla para el PRÓXIMO request que coincida (método + prefijo de path). */
    public function failNext(string $method, string $pathPrefix, string $fault, int $retryAfter = 1): void
    {
        $this->faults[] = ['method' => strtoupper($method), 'prefix' => $pathPrefix, 'fault' => $fault, 'retryAfter' => $retryAfter];
    }

    /** Activos vivos (no soft-deleted) con ese tag. @return array<int,array<string,mixed>> */
    public function liveByTag(string $tag): array
    {
        return array_values(array_filter($this->assets, static fn (array $a): bool => strcasecmp((string) $a['asset_tag'], $tag) === 0 && $a['deleted_at'] === null));
    }

    public function softDelete(int $id): void
    {
        if (isset($this->assets[$id])) {
            $this->assets[$id]['deleted_at'] = '2026-01-01 00:00:00';
        }
    }

    /** Inserta un activo directamente (fixture: "alguien" lo creó en Snipe). @param array<string,mixed> $a */
    public function seed(array $a): int
    {
        $id = $this->nextId++;
        $this->assets[$id] = $a + ['id' => $id, 'asset_tag' => '', 'serial' => null, 'company_id' => null, 'model_id' => 0, 'status_id' => 0, 'name' => '', 'deleted_at' => null];
        $this->assets[$id]['id'] = $id;
        return $id;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): HttpResponse
    {
        $method = strtoupper($method);
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $fault = $this->takeFault($method, $path);
        if ($fault !== null) {
            switch ($fault['fault']) {
                case self::TIMEOUT_BEFORE:
                    throw new HttpTransportException('Operation timed out (fake, antes de ejecutar)');
                case self::LEAKY_ERROR:
                    throw new HttpTransportException('proxy error for ' . $url . ' Authorization: ' . ($headers['Authorization'] ?? ''));
                case self::S500_BEFORE:
                    return $this->json(500, ['message' => 'Server Error']);
                case self::S401:
                    return $this->json(401, ['error' => 'Unauthorized.']);
                case self::S403:
                    return $this->json(403, ['message' => 'This action is unauthorized.']);
                case self::S409:
                    return $this->json(409, ['message' => 'Conflict']);
                case self::S429:
                    return $this->json(429, ['status' => 'error', 'messages' => 'Too many requests', 'status_code' => 429], ['Retry-After' => (string) $fault['retryAfter']]);
            }
        }
        if (($headers['Authorization'] ?? '') !== 'Bearer ' . $this->token) {
            return $this->json(401, ['error' => 'Unauthorized.']);
        }
        if ($fault !== null && $fault['fault'] === self::RACE_CREATE && $method === 'POST') {
            $in = json_decode((string) $body, true);
            if (is_array($in)) {
                $this->create($in); // "otro proceso" gana la carrera con el mismo tag
            }
        }

        $resp = $this->route($method, $path, $query, $body);

        if ($fault !== null && $fault['fault'] === self::TIMEOUT_AFTER) {
            throw new HttpTransportException('Operation timed out (fake, DESPUÉS de ejecutar)');
        }
        if ($fault !== null && $fault['fault'] === self::S500_AFTER) {
            return $this->json(500, ['message' => 'Server Error']);
        }
        return $resp;
    }

    // ------------------------------------------------------------------ rutas

    /** @param array<string,mixed> $query */
    private function route(string $method, string $path, array $query, ?string $body): HttpResponse
    {
        if ($method === 'POST' && $path === '/api/v1/hardware') {
            $this->postCount++;
            $in = json_decode((string) $body, true);
            return $this->create(is_array($in) ? $in : []);
        }
        if ($method === 'GET' && preg_match('#^/api/v1/hardware/bytag/(.+)$#', $path, $m) === 1) {
            return $this->byTag(rawurldecode($m[1]), ($query['deleted'] ?? 'false') === 'true');
        }
        if ($method === 'GET' && preg_match('#^/api/v1/statuslabels/(\d+)$#', $path, $m) === 1) {
            $id = (int) $m[1];
            return isset($this->statusLabels[$id])
                ? $this->json(200, ['id' => $id, 'name' => 'Status ' . $id, 'type' => 'pending'])
                : $this->json(200, ['status' => 'error', 'messages' => 'Statuslabel not found', 'payload' => null]);
        }
        return $this->json(404, ['status' => 'error', 'messages' => '404 endpoint not found', 'payload' => null]);
    }

    /** @param array<string,mixed> $in */
    private function create(array $in): HttpResponse
    {
        $errors = [];
        $tag = trim((string) ($in['asset_tag'] ?? ''));
        if ($tag === '') {
            $errors['asset_tag'] = ['The asset tag field is required.'];
        } elseif ($this->liveByTag($tag) !== []) {
            $errors['asset_tag'] = ['The asset tag must be unique.'];
        }
        if (!isset($this->models[(int) ($in['model_id'] ?? 0)])) {
            $errors['model_id'] = ['The selected model id is invalid.'];
        }
        if (!isset($this->statusLabels[(int) ($in['status_id'] ?? 0)])) {
            $errors['status_id'] = ['The selected status id is invalid.'];
        }
        if (isset($in['company_id']) && $in['company_id'] !== null && !isset($this->companies[(int) $in['company_id']])) {
            $errors['company_id'] = ['The selected company id is invalid.'];
        }
        $serial = isset($in['serial']) && $in['serial'] !== '' ? (string) $in['serial'] : null;
        if ($serial !== null && $this->uniqueSerial) {
            foreach ($this->assets as $a) {
                if ($a['deleted_at'] === null && (string) $a['serial'] === $serial) {
                    $errors['serial'] = ['The serial must be unique.'];
                }
            }
        }
        if ($errors !== []) {
            return $this->json(200, ['status' => 'error', 'messages' => $errors, 'payload' => null]);
        }
        $id = $this->seed([
            'asset_tag'  => $tag,
            'serial'     => $serial,
            'company_id' => isset($in['company_id']) ? (int) $in['company_id'] : null,
            'model_id'   => (int) $in['model_id'],
            'status_id'  => (int) $in['status_id'],
            'name'       => (string) ($in['name'] ?? ''),
            'notes'      => (string) ($in['notes'] ?? ''),
        ]);
        // Snipe devuelve el MODELO crudo (no el transformer) en el payload del store.
        return $this->json(200, ['status' => 'success', 'messages' => 'Asset created successfully. :)', 'payload' => $this->assets[$id]]);
    }

    private function byTag(string $tag, bool $withDeleted): HttpResponse
    {
        $rows = [];
        foreach ($this->assets as $a) {
            if (strcasecmp((string) $a['asset_tag'], $tag) === 0 && ($withDeleted || $a['deleted_at'] === null)) {
                $rows[] = $this->transform($a);
            }
        }
        if ($rows === []) {
            return $this->json(200, ['status' => 'error', 'messages' => 'Asset does not exist.', 'payload' => null]);
        }
        if (count($rows) === 1 && !$withDeleted) {
            return $this->json(200, $rows[0]);
        }
        return $this->json(200, ['total' => count($rows), 'rows' => $rows]);
    }

    /** Forma de `AssetsTransformer` (texto escapado con htmlspecialchars, como `e()`). @param array<string,mixed> $a @return array<string,mixed> */
    private function transform(array $a): array
    {
        return [
            'id'           => (int) $a['id'],
            'asset_tag'    => htmlspecialchars((string) $a['asset_tag'], ENT_QUOTES),
            'serial'       => htmlspecialchars((string) ($a['serial'] ?? ''), ENT_QUOTES),
            'name'         => htmlspecialchars((string) $a['name'], ENT_QUOTES),
            'model'        => ['id' => (int) $a['model_id'], 'name' => 'Model ' . $a['model_id']],
            'status_label' => ['id' => (int) $a['status_id'], 'name' => 'Status ' . $a['status_id']],
            'company'      => $a['company_id'] !== null ? ['id' => (int) $a['company_id'], 'name' => 'Company ' . $a['company_id']] : null,
            'deleted_at'   => $a['deleted_at'] !== null ? ['datetime' => $a['deleted_at'], 'formatted' => $a['deleted_at']] : null,
        ];
    }

    /** @return array{method:string,prefix:string,fault:string,retryAfter:int}|null */
    private function takeFault(string $method, string $path): ?array
    {
        foreach ($this->faults as $i => $f) {
            if ($f['method'] === $method && str_starts_with($path, $f['prefix'])) {
                unset($this->faults[$i]);
                $this->faults = array_values($this->faults);
                return $f;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $body @param array<string,string> $headers */
    private function json(int $status, array $body, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers + ['Content-Type' => 'application/json'], json_encode($body) ?: '');
    }
}
