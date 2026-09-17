<?php

/**
 * Hasher SHA-256 del contenido canónico (la "prueba" de la evidencia).
 *
 * Propiedad clave (gate §5/§14): **reproducible** — recomputar la canónica del mismo contenido
 * lógico produce el mismo `content_sha256`, con independencia del orden de claves de entrada.
 *
 * Se separan dos hashes (decisión D1):
 *   - `content_sha256`: hash de la representación canónica aprobada (esta clase).
 *   - `pdf_sha256`     : integridad del artefacto PDF derivado (ver ApprovedPdfComposer).
 *
 * Clase PURA (sin dependencias de GLPI) para poder testearla sin bootstrap.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

final class Hasher
{
    private Canonicalizer $canonicalizer;

    public function __construct(?Canonicalizer $canonicalizer = null)
    {
        $this->canonicalizer = $canonicalizer ?? new Canonicalizer();
    }

    /** SHA-256 en hexadecimal (64 chars) de una cadena arbitraria. */
    public function sha256(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    /**
     * `content_sha256` de un snapshot canónico (canonicaliza y hashea).
     *
     * @param array<string,mixed> $snapshot
     * @throws \InvalidArgumentException  snapshot inválido (contrato/float) — propagada por el canonicalizador
     */
    public function contentHash(array $snapshot): string
    {
        return $this->sha256($this->canonicalizer->canonical($snapshot));
    }
}
