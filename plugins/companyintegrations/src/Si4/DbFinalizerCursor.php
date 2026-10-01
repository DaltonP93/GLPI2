<?php

/**
 * `FinalizerCursor` de PRODUCCIÓN: fila `si4_finalizer_cursor` de la tabla PROPIA `glpi_plugin_companyintegrations_si4_runtime`
 * (estado operativo del plugin, no configuración ni datos de Compras). Compare-and-set con un UPDATE condicionado al
 * valor leído; si la fila falta (instalación vieja o borrada) se crea en el primer avance.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class DbFinalizerCursor implements FinalizerCursor
{
    public const TABLE = 'glpi_plugin_companyintegrations_si4_runtime';
    public const NAME  = 'si4_finalizer_cursor';

    public function get(): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request(['SELECT' => ['int_value'], 'FROM' => self::TABLE, 'WHERE' => ['name' => self::NAME], 'LIMIT' => 1]) as $row) {
            return max(0, (int) $row['int_value']);
        }
        return 0;
    }

    public function advance(int $expected, int $next): bool
    {
        /** @var \DBmysql $DB */
        global $DB;
        $expected = max(0, $expected);
        $next = max(0, $next);
        if ($expected === $next) {
            return $this->get() === $expected;
        }
        $DB->doQuery('UPDATE `' . self::TABLE . '` SET `int_value` = ' . $next . ', `date_mod` = NOW()'
            . " WHERE `name` = '" . self::NAME . "' AND `int_value` = " . $expected);
        if ($DB->affectedRows() === 1) {
            return true;
        }
        if ($expected !== 0 || $this->exists()) {
            return false; // otra corrida lo movió: no se pisa
        }
        try {
            $DB->doQuery('INSERT INTO `' . self::TABLE . "` (`name`, `int_value`, `date_mod`) VALUES ('" . self::NAME . "', " . $next . ', NOW())');
            return true;
        } catch (\RuntimeException) {
            return false; // otra corrida creó la fila en paralelo
        }
    }

    private function exists(): bool
    {
        return countElementsInTable(self::TABLE, ['name' => self::NAME]) > 0;
    }
}
