<?php

/**
 * Construye una DEFINICIÓN de workflow VERSIONADA a partir de una especificación declarativa.
 *
 * Lo usa el plugin de dominio (p. ej. companypurchasing aporta la definición de Compras) y los
 * tests. `companyworkflow` NO hardcodea ningún dominio: aquí no hay estados de compras.
 *
 * Versionado: cada llamada a createVersion() crea una fila nueva (code, version+1) y desactiva
 * las versiones previas del mismo `code`. Las instancias en ejecución conservan su versión.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Service;

use GlpiPlugin\Companyworkflow\Model\StateDef;
use GlpiPlugin\Companyworkflow\Model\Step;
use GlpiPlugin\Companyworkflow\Model\Transition;
use GlpiPlugin\Companyworkflow\Model\WorkflowDef;

class DefinitionBuilder
{
    /** Reintentos ante colisión de versión (dos publish concurrentes del mismo code). */
    private const MAX_VERSION_ATTEMPTS = 5;

    /**
     * Publica una NUEVA versión de definición de forma FAIL-CLOSED y TRANSACCIONAL:
     *  1) valida la spec ANTES de escribir (spec inválida → excepción, sin escrituras parciales);
     *  2) crea la definición INACTIVA + estados/transiciones/steps, verificando cada resultado;
     *  3) sólo al final hace el switch ATÓMICO de versión activa (desactiva las previas, activa la
     *     nueva) dentro de la misma transacción;
     *  4) si algo falla → rollback y la versión anterior sigue activa;
     *  5) ante colisión de versión (UNIQUE code,version) reintenta con la versión siguiente.
     * Nunca deja cero versiones activas.
     *
     * @param array<string,mixed> $spec
     * @return WorkflowDef  la definición recién creada (activa)
     * @throws \InvalidArgumentException  spec inválida (sin escrituras)
     * @throws \RuntimeException          fallo de persistencia (con rollback)
     */
    public function createVersion(array $spec): WorkflowDef
    {
        // (1) Validación PURA previa: rechaza sin tocar la BD.
        $errors = (new DefinitionSpecValidator())->validate($spec);
        if ($errors !== []) {
            throw new \InvalidArgumentException('spec inválida: ' . implode('; ', $errors));
        }

        $code = (string) $spec['code'];
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $this->createVersionOnce($spec, $code);
            } catch (VersionCollisionException $e) {
                if ($attempt >= self::MAX_VERSION_ATTEMPTS) {
                    throw new \RuntimeException('no se pudo asignar versión tras ' . $attempt . ' intentos', 0, $e);
                }
                // Reintentar: la próxima iteración recomputa la versión sobre datos ya confirmados.
            }
        }
    }

    /**
     * @param array<string,mixed> $spec
     * @throws VersionCollisionException  cuando la versión calculada ya existe (retry externo)
     */
    private function createVersionOnce(array $spec, string $code): WorkflowDef
    {
        /** @var \DBmysql $DB */
        global $DB;
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $DB->beginTransaction();
        try {
            $version = $this->nextVersion($code);

            // (2) Definición INACTIVA primero (no se activa hasta el final).
            $def = new WorkflowDef();
            try {
                $defId = (int) $def->add([
                    'code'            => $code,
                    'name'            => (string) ($spec['name'] ?? $code),
                    'itemtype_target' => (string) ($spec['itemtype_target'] ?? ''),
                    'version'         => $version,
                    'is_active'       => 0,
                    'entities_id'     => (int) ($spec['entities_id'] ?? 0),
                    'is_recursive'    => (int) ($spec['is_recursive'] ?? 0),
                    'date_creation'   => $now,
                    'date_mod'        => $now,
                ]);
            } catch (\Throwable) {
                // add() puede lanzar en vez de devolver 0 ante UNIQUE(code,version): normalizar.
                $defId = 0;
            }
            if ($defId <= 0) {
                // ¿Colisión de versión (otra publicación concurrente tomó esta versión)?
                // (el rollback lo hace el catch; versionExists es sólo lectura)
                if ($this->versionExists($code, $version)) {
                    throw new VersionCollisionException("colisión versión {$version} para code {$code}");
                }
                throw new \RuntimeException('no se pudo crear la definición');
            }

            // Estados (verificar cada inserción).
            $stateIds = [];
            foreach (($spec['states'] ?? []) as $st) {
                $sid = (int) (new StateDef())->add([
                    'workflowdefs_id' => $defId,
                    'code'            => (string) $st['code'],
                    'label'           => (string) ($st['label'] ?? $st['code']),
                    'kind'            => (string) ($st['kind'] ?? StateDef::KIND_INTERMEDIATE),
                    'is_editable'     => (int) ($st['is_editable'] ?? 0),
                    'sla_hours'       => array_key_exists('sla_hours', $st) ? $st['sla_hours'] : null,
                    'date_creation'   => $now,
                    'date_mod'        => $now,
                ]);
                if ($sid <= 0) {
                    throw new \RuntimeException("no se pudo crear el estado '" . (string) $st['code'] . "'");
                }
                $stateIds[(string) $st['code']] = $sid;
            }

            // Transiciones + steps (verificar cada inserción).
            foreach (($spec['transitions'] ?? []) as $tr) {
                $fromId = (int) ($stateIds[(string) ($tr['from'] ?? '')] ?? 0);
                $toId   = (int) ($stateIds[(string) ($tr['to'] ?? '')] ?? 0);
                if ($fromId <= 0 || $toId <= 0) {
                    // La validación previa lo garantiza; defensa en profundidad.
                    throw new \RuntimeException('transición referencia un estado inexistente');
                }
                $cond = $tr['condition'] ?? null;
                $condJson = is_array($cond) ? json_encode($cond, JSON_UNESCAPED_UNICODE) : ($cond !== null ? (string) $cond : null);

                $tId = (int) (new Transition())->add([
                    'workflowdefs_id'   => $defId,
                    'from_statedefs_id' => $fromId,
                    'to_statedefs_id'   => $toId,
                    'action'            => (string) ($tr['action'] ?? ''),
                    'requires_comment'  => (int) ($tr['requires_comment'] ?? 0),
                    'is_auto'           => (int) ($tr['is_auto'] ?? 0),
                    'condition_json'    => $condJson,
                    'required_right'    => (int) ($tr['required_right'] ?? 0),
                    'date_creation'     => $now,
                    'date_mod'          => $now,
                ]);
                if ($tId <= 0) {
                    throw new \RuntimeException('no se pudo crear la transición');
                }

                foreach (($tr['steps'] ?? []) as $stp) {
                    $stpId = (int) (new Step())->add([
                        'transitions_id' => $tId,
                        'level'          => (int) ($stp['level'] ?? 1),
                        'quorum_type'    => (string) ($stp['quorum_type'] ?? Step::QUORUM_COUNT),
                        'quorum_value'   => (int) ($stp['quorum_value'] ?? 1),
                        'approver_kind'  => (string) ($stp['approver_kind'] ?? Step::APPROVER_GROUP),
                        'approver_ref'   => (int) ($stp['approver_ref'] ?? 0),
                        'date_creation'  => $now,
                        'date_mod'       => $now,
                    ]);
                    if ($stpId <= 0) {
                        throw new \RuntimeException('no se pudo crear el step de la transición');
                    }
                }
            }

            // Punto de inyección de fallo para tests de rollback (no-op en producción).
            $this->afterChildrenCreated();

            // (3) Switch ATÓMICO de versión activa (recién ahora se toca is_active).
            $DB->update(WorkflowDef::getTable(), ['is_active' => 0], ['code' => $code]);
            $DB->update(WorkflowDef::getTable(), ['is_active' => 1], ['id' => $defId]);

            $DB->commit();
            $def->getFromDB($defId);
            return $def;
        } catch (VersionCollisionException $e) {
            // (4) Rollback y propagar para reintento (nunca deja versión a medias).
            $this->safeRollback($DB);
            throw $e;
        } catch (\Throwable $e) {
            // (4) Cualquier fallo → rollback; la versión anterior sigue activa.
            $this->safeRollback($DB);
            throw ($e instanceof \RuntimeException || $e instanceof \InvalidArgumentException)
                ? $e
                : new \RuntimeException('fallo al crear la versión: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Rollback tolerante (no falla si la transacción ya no está activa). */
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

    /** Punto de inyección de fallo SÓLO para tests (subclase). En producción no hace nada. */
    protected function afterChildrenCreated(): void
    {
    }

    protected function nextVersion(string $code): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $version = 1;
        foreach ($DB->request([
            'SELECT' => 'version',
            'FROM'   => WorkflowDef::getTable(),
            'WHERE'  => ['code' => $code],
            'ORDER'  => 'version DESC',
            'LIMIT'  => 1,
        ]) as $row) {
            $version = ((int) ($row['version'] ?? 0)) + 1;
        }
        return $version;
    }

    private function versionExists(string $code, int $version): bool
    {
        return (new WorkflowDef())->getFromDBByCrit(['code' => $code, 'version' => $version]);
    }

    /** Estado inicial de una definición. */
    public function initialState(int $defId): ?StateDef
    {
        $sd = new StateDef();
        if ($sd->getFromDBByCrit(['workflowdefs_id' => $defId, 'kind' => StateDef::KIND_INITIAL])) {
            return $sd;
        }
        return null;
    }

    /** Definición ACTIVA por code (la versión vigente para nuevas instancias). */
    public function activeByCode(string $code): ?WorkflowDef
    {
        $def = new WorkflowDef();
        if ($def->getFromDBByCrit(['code' => $code, 'is_active' => 1])) {
            return $def;
        }
        return null;
    }
}
