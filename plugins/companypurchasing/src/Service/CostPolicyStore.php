<?php

/**
 * Persistencia de las versiones INMUTABLES de la política de costo y su pinneo por solicitud (P2D-3).
 *
 * - `pinCurrent($currency)`: toma la política vigente en configuración + la moneda de la compra y la escala
 *   EFECTIVA de esa moneda EN ESE INSTANTE (PYG ⇒ 0 siempre), la valida (fail-closed) y devuelve el id de su
 *   versión (`UNIQUE(policy_hash)`: idempotente y concurrency-safe; una carrera se resuelve releyendo).
 * - `load()`/`forRequest()`: la política PINNEADA; FAIL-CLOSED si falta o si el JSON almacenado no reproduce su
 *   hash (manipulación).
 * - `scaleOverridesFor()`: escalas con las que se formatean los importes de UNA solicitud: iniciada la compra,
 *   SÓLO la pinneada (el costo, el handoff y la integridad de la aprobación no dependen de un cambio posterior de
 *   `currency_scale_overrides`); antes, la configuración vigente.
 *
 * Mismo patrón que `PolicyStore` (política de aprobación).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use GlpiPlugin\Companypurchasing\Model\CostPolicyVersion;
use GlpiPlugin\Companypurchasing\Model\Request;

class CostPolicyStore
{
    public function pinCurrent(string $currency): int
    {
        $cur = strtoupper(trim($currency));
        $policy = CostPolicy::fromArray(PluginConfig::costPolicyRaw() + [ // valida ANTES de escribir
            'currency'       => $cur,
            'currency_scale' => CurrencyPolicy::scale($cur, PluginConfig::currencyScaleOverrides()), // PYG ⇒ 0
        ]);
        $hash = $policy->hash();
        $existing = $this->idByHash($hash);
        if ($existing > 0) {
            return $existing;
        }
        $id = 0;
        try {
            $id = (int) (new CostPolicyVersion())->add([
                'policy_hash'   => $hash,
                'policy_json'   => $policy->canonical(),
                'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            $id = 0; // UNIQUE(policy_hash): otro proceso la insertó en paralelo
        }
        if ($id <= 0) {
            $id = $this->idByHash($hash);
        }
        if ($id <= 0) {
            throw new \RuntimeException('no se pudo registrar la versión de política de costo');
        }
        return $id;
    }

    public function load(int $id): CostPolicy
    {
        $row = new CostPolicyVersion();
        if ($id <= 0 || !$row->getFromDB($id)) {
            throw new \RuntimeException('versión de política de costo inexistente (fail-closed)');
        }
        $raw = json_decode((string) $row->fields['policy_json'], true);
        if (!is_array($raw)) {
            throw new \RuntimeException('versión de política de costo ilegible (fail-closed)');
        }
        $policy = CostPolicy::fromArray($raw, $id);
        if (!hash_equals((string) $row->fields['policy_hash'], $policy->hash())) {
            throw new \RuntimeException('versión de política de costo alterada: no reproduce su hash (fail-closed)');
        }
        return $policy;
    }

    /** Política pinneada de la solicitud; su moneda DEBE ser la de la solicitud (fail-closed). */
    public function forRequest(Request $req): CostPolicy
    {
        $policy = $this->load((int) ($req->fields['cost_policies_id'] ?? 0));
        if ($policy->currency() !== strtoupper(trim((string) ($req->fields['currency_code'] ?? '')))) {
            throw new \RuntimeException('la moneda de la solicitud no coincide con la de su política de costo pinneada (fail-closed)');
        }
        return $policy;
    }

    /**
     * Escalas monetarias para los importes de ESTA solicitud: con la compra iniciada (política pinneada), SÓLO la
     * escala pinneada; antes, la configuración vigente.
     *
     * @return array<string,int>
     */
    public static function scaleOverridesFor(Request $req): array
    {
        if ((int) ($req->fields['cost_policies_id'] ?? 0) <= 0) {
            return PluginConfig::currencyScaleOverrides();
        }
        return (new self())->forRequest($req)->scaleOverrides();
    }

    private function idByHash(string $hash): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => CostPolicyVersion::getTable(), 'WHERE' => ['policy_hash' => $hash], 'LIMIT' => 1]) as $row) {
            return (int) $row['id'];
        }
        return 0;
    }
}
