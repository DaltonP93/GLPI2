<?php

/**
 * Sesión GLPI REAL del worker SI-4 en CLI (ADR-0020 §7): se abre con la API de sesión soportada (`Session::init`,
 * `changeProfile`, `changeActiveEntities`) para un usuario TÉCNICO configurado por el operador, y se exigen los dos
 * bits de mínimo privilegio: `plugin_companyintegrations` RIGHT_SI4 y `plugin_companypurchasing` RIGHT_INTEGRATION.
 * Nunca se ejecuta como cron "omnipotente": se sale del modo cron para que la ACL de Compras sea real.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

use GlpiPlugin\Companyintegrations\Model\AssetBridge;
use Session;

final class WorkerSession
{
    /** Bit `RIGHT_INTEGRATION` de `plugin_companypurchasing` (ADR-0019 §5). */
    public const PURCHASING_RIGHT_INTEGRATION = 1024;

    /** @throws \RuntimeException usuario inexistente/inactivo, perfil no asignado o derechos insuficientes */
    public static function open(int $userId, int $profileId = 0): void
    {
        $user = new \User();
        if ($userId <= 0 || !$user->getFromDB($userId)) {
            throw new \RuntimeException('usuario técnico SI-4 inexistente');
        }
        $auth = new \Auth();
        $auth->user = $user;
        $auth->auth_succeded = true;
        Session::init($auth);
        if ((int) Session::getLoginUserID() !== $userId) {
            throw new \RuntimeException('usuario técnico SI-4 inactivo o sin perfil');
        }
        if ($profileId > 0) {
            if (!isset($_SESSION['glpiprofiles'][$profileId])) {
                throw new \RuntimeException('el usuario técnico no tiene el perfil indicado');
            }
            Session::changeProfile($profileId);
        }
        Session::changeActiveEntities('all');
        unset($_SESSION['glpicronuserrunning']);
        self::assertRights();
    }

    /** @throws \RuntimeException sin alguno de los dos bits de mínimo privilegio */
    public static function assertRights(): void
    {
        if (!empty($_SESSION['glpicronuserrunning'])) {
            throw new \RuntimeException('el worker SI-4 no corre en modo cron (la ACL debe ser real)');
        }
        if (!Session::haveRight(AssetBridge::$rightname, AssetBridge::RIGHT_SI4)) {
            throw new \RuntimeException('permiso denegado (companyintegrations RIGHT_SI4)');
        }
        if (!Session::haveRight('plugin_companypurchasing', self::PURCHASING_RIGHT_INTEGRATION)) {
            throw new \RuntimeException('permiso denegado (companypurchasing RIGHT_INTEGRATION)');
        }
    }
}
