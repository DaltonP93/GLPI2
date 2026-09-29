<?php

/**
 * `plugins:companypurchasing:reconcile` — converge `requests.domain_state` (proyección) con la instancia
 * de `companyworkflow` (AUTORIDAD), por lotes con cursor y wrap-around (misma lógica que la Acción
 * automática `reconcileprojection`). Idempotente. Re-enlaza una instancia si una caída ocurrió entre
 * `startInstance()` y el enlace local. REPORTA (y sale con error) solicitudes enviadas sin instancia e
 * integridad de aprobación pendiente: no las repara (exigen un actor con RIGHT_ACT). P2D-3: converge la saga de
 * recepción cuando puede y REPORTA la pendiente/anómala y las instancias con una versión anterior de la definición.
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
            '<info>reconcile: revisadas=%d corregidas=%d sin_instancia=%d integridad_pendiente=%d recepcion_pendiente=%d anomalias_recepcion=%d definicion_anterior=%d errores=%d cursor=%d%s</info>',
            $r['checked'],
            $r['corrected'],
            count($r['orphans']),
            count($r['dirty']),
            count($r['receiving_pending']),
            count($r['receiving_anomalies']),
            count($r['legacy']),
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
        // P2D-3: la saga de recepción converge sólo con un actor autorizado o en la Acción automática NATIVA
        // (contexto de sistema de la CronTask); desde la CLI sin sesión se REPORTA como pendiente.
        if ($r['receiving_pending'] !== []) {
            $output->writeln('<error>recepción confirmada con sincronización del workflow PENDIENTE (converge la Acción automática reconcileprojection): ' . implode(',', $r['receiving_pending']) . '</error>');
        }
        if ($r['receiving_anomalies'] !== []) {
            $pairs = [];
            foreach ($r['receiving_anomalies'] as $id => $kind) {
                $pairs[] = $id . ':' . $kind;
            }
            $output->writeln('<error>anomalías de recepción NO convergibles (no se muta nada): ' . implode(',', $pairs) . '</error>');
        }
        if ($r['legacy'] !== []) {
            $output->writeln('<comment>instancias con una versión ANTERIOR de la definición (sin fase de compra; conservan su versión, no se migran): ' . implode(',', $r['legacy']) . '</comment>');
        }
        if ($r['legacy_blocked'] !== []) {
            $output->writeln('<error>…de ellas, APROBADAS sin poder iniciar la compra (requieren decisión humana): ' . implode(',', $r['legacy_blocked']) . '</error>');
        }
        return ($r['errors'] > 0 || $r['orphans'] !== [] || $r['dirty'] !== [] || $r['receiving_pending'] !== []
            || $r['receiving_anomalies'] !== [] || $r['legacy_blocked'] !== []) ? Command::FAILURE : Command::SUCCESS;
    }
}
