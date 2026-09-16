<?php

/**
 * Detección de cambio SUSTANTIVO por comparación de hashes de contenido (gate §6/§14).
 *
 * Domain-agnostic (D4/D5): `companysignature` **no** conoce qué campos son sustantivos — eso lo
 * declarará `companypurchasing` (Fase 2D) al marcar `is_substantive` del snapshot. Aquí sólo se
 * ofrece la primitiva reproducible: *dos representaciones canónicas con distinto hash reflejan un
 * cambio de contenido*; igual hash ⇒ contenido equivalente (no invalida).
 *
 * Clase PURA (sin dependencias de GLPI) para poder testearla sin bootstrap.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

final class SubstantiveChange
{
    /** ¿Cambió el contenido? (comparación exacta de `content_sha256`). */
    public function differs(string $previousContentHash, string $newContentHash): bool
    {
        return !hash_equals($previousContentHash, $newContentHash);
    }
}
