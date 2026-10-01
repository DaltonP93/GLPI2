<?php

/**
 * Sesión GLPI REAL del worker SI-4 en CLI (ADR-0020 §7): se abre con la API de sesión soportada (`Session::init`,
 * `changeProfile`, `changeActiveEntities`) para un usuario TÉCNICO configurado por el operador, y se exigen los bits
 * de mínimo privilegio: `plugin_companyintegrations` RIGHT_SI4, `plugin_companypurchasing` RIGHT_INTEGRATION y (SI4-3)
 * `plugin_companyqr` RIGHT_GENERATE + RIGHT_PRINT. Se verifican ANTES de reclamar nada (fail-closed); la etapa QR los
 * vuelve a verificar por unidad (BLOCKED_CONFIG si faltan).
 * Nunca se ejecuta como cron "omnipotente": se sale del modo cron para que la ACL de Compras sea real.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

use GlpiPlugin\Companyintegrations\Model\AssetBridge;
use GlpiPlugin\Companypurchasing\Model\Request as PurchaseRequest;
use GlpiPlugin\Companyqr\Model\Code as QrCode;
use Session;

final class WorkerSession
{
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

    /**
     * Los bits de Compras y de companyqr se toman de sus contratos PÚBLICOS (`Request::RIGHT_INTEGRATION`,
     * `Code::RIGHT_GENERATE` / `Code::RIGHT_PRINT`), sin copiar números: si el plugin no está disponible ⇒ fail-closed.
     *
     * @throws \RuntimeException sin alguno de los bits de mínimo privilegio o sin companypurchasing/companyqr
     */
    public static function assertRights(): void
    {
        if (!empty($_SESSION['glpicronuserrunning'])) {
            throw new \RuntimeException('el worker SI-4 no corre en modo cron (la ACL debe ser real)');
        }
        if (!Session::haveRight(AssetBridge::$rightname, AssetBridge::RIGHT_SI4)) {
            throw new \RuntimeException('permiso denegado (companyintegrations RIGHT_SI4)');
        }
        if (!class_exists(PurchaseRequest::class)) {
            throw new \RuntimeException('companypurchasing no está disponible (fail-closed)');
        }
        if (!Session::haveRight(PurchaseRequest::$rightname, PurchaseRequest::RIGHT_INTEGRATION)) {
            throw new \RuntimeException('permiso denegado (companypurchasing RIGHT_INTEGRATION)');
        }
        if (!class_exists(QrCode::class) || !\Plugin::isPluginActive('companyqr')) {
            throw new \RuntimeException('companyqr no está disponible (fail-closed)');
        }
        if (!Session::haveRight(QrCode::$rightname, QrCode::RIGHT_GENERATE)) {
            throw new \RuntimeException('permiso denegado (companyqr RIGHT_GENERATE)');
        }
        if (!Session::haveRight(QrCode::$rightname, QrCode::RIGHT_PRINT)) {
            throw new \RuntimeException('permiso denegado (companyqr RIGHT_PRINT)');
        }
    }
}
