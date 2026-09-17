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

use GlpiPlugin\Companysignature\Service\ReconcileService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ReconcileCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugins:companysignature:reconcile')
            ->setDescription('Reconciliación durable (harvest + worker) de evidencia desde el ledger de companyworkflow (idempotente).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!class_exists('GlpiPlugin\\Companyworkflow\\Api\\WorkflowApi')) {
            $output->writeln('<error>companyworkflow no disponible: no se puede reconciliar.</error>');
            return Command::FAILURE;
        }

        // Misma lógica DURABLE que la CronTask: encola lo nuevo y procesa la cola con reintentos.
        $r = (new ReconcileService())->run();
        $output->writeln(sprintf(
            '<info>reconcile: encolados=%d procesados=%d evidencias_nuevas=%d pendientes=%d errores=%d</info>',
            (int) $r['enqueued'],
            (int) $r['processed'],
            (int) $r['materialized'],
            (int) $r['pending'],
            (int) $r['errored']
        ));
        return Command::SUCCESS;
    }
}
