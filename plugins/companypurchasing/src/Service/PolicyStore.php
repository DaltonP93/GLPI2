<?php

/**
 * Persistencia de las versiones INMUTABLES de la política de aprobación y su pinneo por solicitud.
 *
 * - `pinCurrent()`: toma la política vigente en configuración, la valida (fail-closed), y devuelve el id de
 *   su versión (`UNIQUE(policy_hash)`: idempotente y concurrency-safe; una carrera se resuelve releyendo).
 * - `forRequest()`: la política PINNEADA de una solicitud (`requests.policies_id`). FAIL-CLOSED si no
 *   tiene, o si el JSON almacenado no reproduce su hash (manipulación).
 *
 * `sync_on_workflow_events` NO es parte de la política (es operacional global).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use GlpiPlugin\Companypurchasing\Model\PolicyVersion;
use GlpiPlugin\Companypurchasing\Model\Request;

class PolicyStore
{
    /** Reglas vigentes en configuración (sin pinnear). @return array<string,mixed> */
    public function currentRaw(): array
    {
        return [
            'stage_scopes'      => PluginConfig::stageScopes(),
            'scope_checkpoints' => PluginConfig::scopeCheckpoints(),
            'pdf_stages'        => PluginConfig::pdfStages(),
            'quote_states'      => PluginConfig::quoteStates(),
            'amend_states'      => PluginConfig::amendStates(),
        ];
    }

    /** Pinnea (idempotente) la política vigente y devuelve el id de su versión inmutable. */
    public function pinCurrent(): int
    {
        $policy = ApprovalPolicy::fromArray($this->currentRaw()); // valida ANTES de escribir
        $data = $policy->toArray();
        $hash = ApprovalPolicy::hash($data);
        $existing = $this->idByHash($hash);
        if ($existing > 0) {
            return $existing;
        }
        $id = 0;
        try {
            $id = (int) (new PolicyVersion())->add([
                'policy_hash'   => $hash,
                'policy_json'   => ApprovalPolicy::canonical($data),
                'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            $id = 0; // UNIQUE(policy_hash): otro proceso la insertó en paralelo
        }
        if ($id <= 0) {
            $id = $this->idByHash($hash);
        }
        if ($id <= 0) {
            throw new \RuntimeException('no se pudo registrar la versión de política de aprobación');
        }
        return $id;
    }

    /** Carga una versión verificando su integridad (hash del JSON almacenado). */
    public function load(int $id): ApprovalPolicy
    {
        $row = new PolicyVersion();
        if ($id <= 0 || !$row->getFromDB($id)) {
            throw new \RuntimeException('versión de política inexistente (fail-closed)');
        }
        $raw = json_decode((string) $row->fields['policy_json'], true);
        if (!is_array($raw)) {
            throw new \RuntimeException('versión de política ilegible (fail-closed)');
        }
        $policy = ApprovalPolicy::fromArray($raw, $id);
        if (!hash_equals((string) $row->fields['policy_hash'], ApprovalPolicy::hash($policy->toArray()))) {
            throw new \RuntimeException('versión de política alterada: el contenido no reproduce su hash (fail-closed)');
        }
        return $policy;
    }

    /** Política PINNEADA de la solicitud. FAIL-CLOSED si no tiene. */
    public function forRequest(Request $req): ApprovalPolicy
    {
        $id = (int) ($req->fields['policies_id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('la solicitud no tiene política de aprobación pinneada (fail-closed)');
        }
        return $this->load($id);
    }

    private function idByHash(string $hash): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => PolicyVersion::getTable(), 'WHERE' => ['policy_hash' => $hash], 'LIMIT' => 1]) as $row) {
            return (int) $row['id'];
        }
        return 0;
    }
}
