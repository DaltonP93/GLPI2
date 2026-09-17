<?php

/**
 * Reconciliador DURABLE e IDEMPOTENTE de evidencia (hardening §1).
 *
 * Recorre el LEDGER de historial de `companyworkflow` (vía su API) y materializa cualquier evento
 * relevante que aún no tenga evidencia — recuperando la pérdida silenciosa cuando el proceso cae
 * tras el COMMIT del workflow pero antes/durante el listener:
 *   `workflow COMMIT → caída → restart → reconcile → evidencia creada UNA sola vez`.
 *
 * Idempotente: reejecutarlo no duplica (la UNIQUE `idempotency_key` y `Materializer` lo garantizan).
 * No introduce dependencia DB-a-DB externa ni modifica el core: lee el ledger por la API de workflow.
 *
 * Uso: `php bin/console plugins:companysignature:reconcile [--since=<historyId>]`
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Command;

use GlpiPlugin\Companysignature\Service\Materializer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ReconcileCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugins:companysignature:reconcile')
            ->setDescription('Reconciliación durable: materializa evidencia faltante desde el ledger de companyworkflow (idempotente).')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Sólo filas de historial con id > este valor', '0')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de filas a procesar', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $wfApiClass = 'GlpiPlugin\\Companyworkflow\\Api\\WorkflowApi';
        if (!class_exists($wfApiClass)) {
            $output->writeln('<error>companyworkflow no disponible: no se puede reconciliar.</error>');
            return Command::FAILURE;
        }

        $since = (int) $input->getOption('since');
        $limit = (int) $input->getOption('limit');

        $api = new $wfApiClass();
        $filter = [
            'events'   => [Materializer::WF_DECISION_RECORDED, Materializer::WF_TRANSITIONED, Materializer::WF_APPROVAL_INVALIDATED],
            'since_id' => $since,
        ];
        if ($limit > 0) {
            $filter['limit'] = $limit;
        }
        $rows = $api->history($filter); // orden causal (id ascendente): aprobaciones antes de invalidaciones

        $mat = new Materializer();
        $scanned = 0;
        $created = 0;
        $maxId = $since;
        foreach ($rows as $row) {
            $scanned++;
            $created += $mat->materializeRow($row);
            $maxId = max($maxId, (int) ($row['id'] ?? 0));
        }

        $output->writeln(sprintf('<info>reconcile: filas=%d evidencias_nuevas=%d ultimo_history_id=%d</info>', $scanned, $created, $maxId));
        return Command::SUCCESS;
    }
}
