<?php

/**
 * `plugins:companypurchasing:reconcile` — converge `requests.domain_state` (proyección) con la instancia
 * de `companyworkflow` (AUTORIDAD), por lotes con cursor y wrap-around (misma lógica que la Acción
 * automática `reconcileprojection`). Idempotente. Re-enlaza una instancia si una caída ocurrió entre
 * `startInstance()` y el enlace local. REPORTA (y sale con error) solicitudes enviadas sin instancia e
 * integridad de aprobación pendiente: no las repara (exigen un actor con RIGHT_ACT).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Command;

use GlpiPlugin\Companypurchasing\Service\ApprovalOrchestrator;
use GlpiPlugin\Companypurchasing\Service\WorkflowGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ReconcileCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugins:companypurchasing:reconcile')
            ->setDescription('Reconcilia la proyección domain_state con companyworkflow (el motor gana; idempotente).')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'máximo de solicitudes a revisar', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!(new WorkflowGateway())->available()) {
            $output->writeln('<error>companyworkflow no disponible: no se puede reconciliar.</error>');
            return Command::FAILURE;
        }
        $r = (new ApprovalOrchestrator())->reconcileAll((int) $input->getOption('limit'));
        $output->writeln(sprintf(
            '<info>reconcile: revisadas=%d corregidas=%d sin_instancia=%d integridad_pendiente=%d errores=%d cursor=%d%s</info>',
            $r['checked'],
            $r['corrected'],
            count($r['orphans']),
            count($r['dirty']),
            $r['errors'],
            $r['cursor'],
            $r['wrapped'] ? ' (wrap-around)' : ''
        ));
        // Anomalías que la reconciliación NO puede reparar sola (exigen un actor con RIGHT_ACT): se reportan y
        // el comando falla para que no pasen inadvertidas (nunca "éxito silencioso").
        if ($r['orphans'] !== []) {
            $output->writeln('<error>solicitudes enviadas SIN instancia de workflow (reintentar submit): ' . implode(',', $r['orphans']) . '</error>');
        }
        if ($r['dirty'] !== []) {
            $output->writeln('<error>solicitudes con integridad de aprobación PENDIENTE (reparar antes de decidir): ' . implode(',', $r['dirty']) . '</error>');
        }
        return ($r['errors'] > 0 || $r['orphans'] !== [] || $r['dirty'] !== []) ? Command::FAILURE : Command::SUCCESS;
    }
}
