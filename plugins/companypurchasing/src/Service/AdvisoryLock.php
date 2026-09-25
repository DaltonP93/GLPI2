<?php

/**
 * Lock CON NOMBRE de MySQL (`GET_LOCK`/`RELEASE_LOCK`) para serializar secciones críticas por clave
 * (p. ej. el envío de una solicitud) entre procesos/conexiones — INDEPENDIENTE de transacciones.
 *
 * El nombre se compone sólo de caracteres seguros (prefijo por BD + sufijo saneado, ≤64) ⇒ literal SQL
 * sin inyección. Mismo patrón validado en `companysignature/ApprovedPdfComposer`.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class AdvisoryLock
{
    /** Nombre de lock estable y seguro, acotado a 64 chars y aislado por BD. */
    public static function name(string $suffix): string
    {
        /** @var \DBmysql $DB */
        global $DB;
        $db = preg_replace('/[^A-Za-z0-9_]/', '_', (string) ($DB->dbdefault ?? 'glpi'));
        $suf = preg_replace('/[^A-Za-z0-9_]/', '_', $suffix);
        return substr('cpur_' . $db . '_' . $suf, 0, 64);
    }

    /** Adquiere el lock (`GET_LOCK`). Devuelve true sólo si se obtuvo. */
    public function acquire(string $name, int $timeoutSeconds): bool
    {
        /** @var \DBmysql $DB */
        global $DB;
        try {
            $res = $DB->doQuery("SELECT GET_LOCK('" . $name . "', " . max(0, $timeoutSeconds) . ") AS l");
            if ($res === false) {
                return false;
            }
            $row = $DB->fetchAssoc($res);
            return isset($row['l']) && (int) $row['l'] === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ejecuta `$fn` bajo el LOCK COMÚN de una solicitud (`request_<id>`): el MISMO lock que serializa las
     * mutaciones de `RequestManager` (P2D-1), las de cotizaciones y los pasos de la saga de aprobación
     * (P2D-2). No anidar: quien lo sostiene no llama a otro método que lo adquiera.
     *
     * @return mixed lo que devuelva `$fn`
     */
    public function withRequestLock(int $requestId, \Closure $fn, int $timeoutSeconds = 10): mixed
    {
        if ($requestId <= 0) {
            throw new \RuntimeException('solicitud inválida');
        }
        $name = self::name('request_' . $requestId);
        if (!$this->acquire($name, $timeoutSeconds)) {
            throw new \RuntimeException('no se pudo obtener el lock de la solicitud (reintente)');
        }
        try {
            return $fn();
        } finally {
            $this->release($name);
        }
    }

    /** Libera el lock (`RELEASE_LOCK`). Best-effort. */
    public function release(string $name): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        try {
            $DB->doQuery("SELECT RELEASE_LOCK('" . $name . "')");
        } catch (\Throwable) {
            // best-effort
        }
    }
}
