<?php

/**
 * Allocator de `document_version` de DOMINIO (P2D-2; gate §4 "Compras numera, Firma valida/inmoviliza").
 *
 * Propiedades:
 *   - MONOTÓNICO POR SOLICITUD: todas las versiones de todos los scopes de una solicitud comparten una
 *     secuencia (companysignature exige `UNIQUE(sujeto, versión)`); cada fila del ledger queda
 *     etiquetada con su scope/checkpoint.
 *   - CONCURRENCY-SAFE: incremento atómico bajo bloqueo de fila (`..._docseq`), y la decisión
 *     "reutilizar o asignar" se serializa con un lock con nombre por solicitud (`docver_<id>`).
 *   - NO REUTILIZABLE: el contador sólo crece; si algo falla tras asignar queda un hueco (aceptado).
 *   - IDEMPOTENTE ante reintentos: si la ÚLTIMA versión del scope tiene el MISMO hash de contenido
 *     semántico, se REUTILIZA (un reintento tras caída no crea una versión nueva). Nunca se reutiliza una
 *     versión anterior a la última del scope (el contenido "vuelto atrás" obtiene una versión nueva).
 *   - NUNCA se hardcodea `document_version = 1` ni se infiere por timestamp.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use GlpiPlugin\Companypurchasing\Model\DocSequence;
use GlpiPlugin\Companypurchasing\Model\DocVersion;

class DocumentVersionAllocator
{
    public const PDF_READY = 'ready';
    public const PDF_ERROR = 'error';
    public const PDF_PENDING = 'pending';

    private const MAX_RETRIES = 5;

    private AdvisoryLock $lock;

    public function __construct(?AdvisoryLock $lock = null)
    {
        $this->lock = $lock ?? new AdvisoryLock();
    }

    /**
     * Hash DETERMINISTA del snapshot SEMÁNTICO (sin `document_version`): JSON canónico (claves de objeto
     * ordenadas recursivamente; listas en su orden) → sha256. FAIL-CLOSED ante `float`. Puro.
     *
     * Sólo sirve para decidir "¿cambió el contenido del scope?"; el hash PROBATORIO es el
     * `content_sha256` que calcula companysignature.
     *
     * @param array<string,mixed> $semantic
     */
    public static function payloadHash(array $semantic): string
    {
        unset($semantic['document_version']);
        $json = json_encode(self::canon($semantic), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return hash('sha256', $json);
    }

    private static function canon(mixed $v): mixed
    {
        if (is_float($v)) {
            throw new \InvalidArgumentException('snapshot con float (fail-closed): los importes van como string exacto');
        }
        if (!is_array($v)) {
            return $v;
        }
        if (array_is_list($v)) {
            return array_map([self::class, 'canon'], $v);
        }
        ksort($v, SORT_STRING);
        $out = [];
        foreach ($v as $k => $item) {
            $out[(string) $k] = self::canon($item);
        }
        return $out;
    }

    /**
     * Reutiliza la última versión del scope si el contenido no cambió; si cambió, asigna la SIGUIENTE
     * (monotónica por solicitud) y la registra en el ledger — ambas escrituras en UNA transacción local.
     *
     * @return array{id:int, document_version:int, reused:bool}
     * @throws \RuntimeException
     */
    public function allocateOrReuse(int $requestId, string $scopeKey, string $payloadSha, string $correlationId = ''): array
    {
        if ($requestId <= 0 || preg_match('/^[A-Z_]{1,60}$/', $scopeKey) !== 1 || preg_match('/^[0-9a-f]{64}$/', $payloadSha) !== 1) {
            throw new \InvalidArgumentException('allocateOrReuse: parámetros inválidos');
        }
        $name = AdvisoryLock::name('docver_' . $requestId);
        if (!$this->lock->acquire($name, 10)) {
            throw new \RuntimeException('no se pudo obtener el lock de versiones documentales (reintente)');
        }
        try {
            $latest = $this->latestForScope($requestId, $scopeKey);
            if ($latest !== null && hash_equals((string) $latest['payload_sha256'], $payloadSha)) {
                return ['id' => (int) $latest['id'], 'document_version' => (int) $latest['document_version'], 'reused' => true];
            }
            return $this->allocate($requestId, $scopeKey, $payloadSha, $correlationId);
        } finally {
            $this->lock->release($name);
        }
    }

    /** @return array{id:int, document_version:int, reused:bool} */
    private function allocate(int $requestId, string $scopeKey, string $payloadSha, string $correlationId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $seqTable = DocSequence::getTable();
        $now = $this->now();

        // (1) Fila de secuencia (idempotente). next_version = 1 = "próxima a asignar".
        $DB->doQuery(
            "INSERT IGNORE INTO `{$seqTable}` (`requests_id`, `next_version`, `date_mod`) VALUES ({$requestId}, 1, '{$now}')"
        );

        $last = null;
        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            try {
                $DB->beginTransaction();
                // (2) Incremento atómico bajo bloqueo de fila + lectura del nuevo valor.
                $DB->doQuery(
                    "UPDATE `{$seqTable}` SET `next_version` = `next_version` + 1, `date_mod` = '{$now}' WHERE `requests_id` = {$requestId}"
                );
                $next = 0;
                foreach ($DB->request(['SELECT' => 'next_version', 'FROM' => $seqTable, 'WHERE' => ['requests_id' => $requestId], 'LIMIT' => 1]) as $row) {
                    $next = (int) $row['next_version'];
                }
                if ($next < 2) {
                    throw new \RuntimeException('secuencia de versiones inconsistente');
                }
                $version = $next - 1;
                // (3) Ledger en la MISMA transacción: versión asignada ⇔ fila del ledger.
                $id = (int) (new DocVersion())->add([
                    'requests_id'      => $requestId,
                    'scope_key'        => $scopeKey,
                    'document_version' => $version,
                    'payload_sha256'   => $payloadSha,
                    'correlation_id'   => substr($correlationId, 0, 64),
                    'date_creation'    => $now,
                    'date_mod'         => $now,
                ]);
                if ($id <= 0) {
                    throw new \RuntimeException('no se pudo registrar la versión documental en el ledger');
                }
                $DB->commit();
                return ['id' => $id, 'document_version' => $version, 'reused' => false];
            } catch (\Throwable $e) {
                $this->safeRollback($DB);
                $last = $e;
                usleep(1000 * (1 << $attempt));
            }
        }
        throw new \RuntimeException('no se pudo asignar document_version' . ($last ? ': ' . $last->getMessage() : ''));
    }

    /** Guarda la identidad devuelta por Firma (idempotente; comprueba el write). */
    public function markRecorded(int $ledgerId, int $documentVersionsId, string $contentSha256): void
    {
        $row = new DocVersion();
        if (!$row->getFromDB($ledgerId)) {
            throw new \RuntimeException('fila de versión documental inexistente');
        }
        if ((int) $row->fields['document_versions_id'] === $documentVersionsId && (string) $row->fields['content_sha256'] === $contentSha256) {
            return;
        }
        if ((int) $row->fields['document_versions_id'] > 0 && (int) $row->fields['document_versions_id'] !== $documentVersionsId) {
            throw new \RuntimeException('la versión ya estaba registrada con otra identidad de Firma (fail-closed)');
        }
        $ok = $row->update([
            'id'                   => $ledgerId,
            'document_versions_id' => $documentVersionsId,
            'content_sha256'       => $contentSha256,
            'date_mod'             => $this->now(),
        ]);
        if (!$ok) {
            throw new \RuntimeException('no se pudo registrar la identidad de Firma en el ledger');
        }
    }

    public function markPdf(int $ledgerId, string $status): bool
    {
        $row = new DocVersion();
        if (!$row->getFromDB($ledgerId)) {
            return false;
        }
        return (bool) $row->update(['id' => $ledgerId, 'pdf_status' => $status, 'date_mod' => $this->now()]);
    }

    /** @return array<string,mixed>|null */
    public function latestForScope(int $requestId, string $scopeKey): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request([
            'FROM'  => DocVersion::getTable(),
            'WHERE' => ['requests_id' => $requestId, 'scope_key' => $scopeKey],
            'ORDER' => 'document_version DESC',
            'LIMIT' => 1,
        ]) as $row) {
            return $row;
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public function findByVersion(int $requestId, int $version): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request([
            'FROM'  => DocVersion::getTable(),
            'WHERE' => ['requests_id' => $requestId, 'document_version' => $version],
            'LIMIT' => 1,
        ]) as $row) {
            return $row;
        }
        return null;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(int $requestId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => DocVersion::getTable(), 'WHERE' => ['requests_id' => $requestId], 'ORDER' => 'document_version ASC']) as $row) {
            $out[] = $row;
        }
        return $out;
    }

    private function now(): string
    {
        $ts = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        return preg_match('/^[0-9 :\-]{1,25}$/', $ts) === 1 ? $ts : date('Y-m-d H:i:s');
    }

    private function safeRollback(\DBmysql $DB): void
    {
        try {
            if (!method_exists($DB, 'inTransaction') || $DB->inTransaction()) {
                $DB->rollBack();
            }
        } catch (\Throwable) {
            // best-effort
        }
    }
}
