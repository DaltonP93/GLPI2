<?php

/**
 * `plugins:companypurchasing:reconcile` — converge `requests.domain_state` (proyección) con la instancia
 * de `companyworkflow` (AUTORIDAD). Idempotente: una segunda ejecución no corrige nada. Re-enlaza una
 * instancia si una caída ocurrió entre `startInstance()` y el enlace local. No invalida aprobaciones (eso
 * exige una sesión con RIGHT_ACT; lo hace el orquestador antes de cada decisión).
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
            '<info>reconcile: revisadas=%d corregidas=%d errores=%d</info>',
            $r['checked'],
            $r['corrected'],
            $r['errors']
        ));
        return $r['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
