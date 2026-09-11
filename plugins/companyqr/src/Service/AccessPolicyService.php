<?php

/**
 * Política de acceso: decide el resultado de resolver un token, aplicando SIEMPRE
 * la ACL nativa de GLPI en el flujo autenticado. Centraliza la regla
 * "el QR identifica; GLPI autoriza".
 *
 * Devuelve una estructura uniforme para que controladores y tests decidan igual.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Service;

use CommonDBTM;
use GlpiPlugin\Companyqr\Model\Code;
use GlpiPlugin\Companyqr\Model\Scan;

final class AccessPolicyService
{
    public function __construct(private AssetResolver $resolver = new AssetResolver())
    {
    }

    public function resolver(): AssetResolver
    {
        return $this->resolver;
    }

    /**
     * Flujo AUTENTICADO (ruta estándar /scan/{token}).
     * El firewall ya garantizó la sesión; aquí se aplica la ACL del activo.
     *
     * @return array{result:string, code?:Code, item?:CommonDBTM, view?:array<string,string>}
     */
    public function resolveAuthenticated(string $token): array
    {
        $code = $this->resolver->findByToken($token);
        if ($code === null) {
            return ['result' => Scan::RESULT_NOT_FOUND];
        }
        if (($code->fields['status'] ?? '') === Code::STATUS_REVOKED) {
            return ['result' => Scan::RESULT_REVOKED, 'code' => $code];
        }

        $item = $this->resolver->loadAsset($code);
        if ($item === null) {
            return ['result' => Scan::RESULT_ASSET_GONE, 'code' => $code];
        }

        // *** ACL NATIVA: aquí se autoriza (o no). El token NO autoriza. ***
        if (!$this->resolver->canView($item)) {
            return ['result' => Scan::RESULT_DENIED, 'code' => $code, 'item' => $item];
        }

        return [
            'result' => Scan::RESULT_RESOLVED,
            'code'   => $code,
            'item'   => $item,
            'view'   => $this->resolver->buildSafeView($code, $item),
        ];
    }

    /**
     * Flujo ANÓNIMO (ruta separada /public/{token}), sólo si `anonymous_enabled=1`.
     * Devuelve un subset MÍNIMO (código + tipo). Nunca datos técnicos.
     *
     * @return array{result:string, code?:Code, item?:CommonDBTM, view?:array<string,string>}
     */
    public function resolveAnonymous(string $token): array
    {
        if (!PluginConfig::anonymousEnabled()) {
            // Modo anónimo apagado: no se filtra NADA; se exige login.
            return ['result' => Scan::RESULT_LOGIN_REQUIRED];
        }

        $code = $this->resolver->findByToken($token);
        if ($code === null) {
            return ['result' => Scan::RESULT_NOT_FOUND];
        }
        if (($code->fields['status'] ?? '') === Code::STATUS_REVOKED) {
            return ['result' => Scan::RESULT_REVOKED, 'code' => $code];
        }

        $item = $this->resolver->loadAsset($code);
        if ($item === null) {
            return ['result' => Scan::RESULT_ASSET_GONE, 'code' => $code];
        }

        return [
            'result' => Scan::RESULT_RESOLVED,
            'code'   => $code,
            'item'   => $item,
            'view'   => $this->resolver->buildMinimalView($code, $item),
        ];
    }
}
