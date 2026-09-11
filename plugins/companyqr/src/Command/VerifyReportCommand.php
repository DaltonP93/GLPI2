<?php

/**
 * Verifica (para el E2E HTTP) que existe al menos un ticket VINCULADO al activo dado.
 * Nombre: `plugins:companyqr:verify-report`.
 *
 * Exit 0 si hay >=1 Item_Ticket para el Computer; exit 1 si no.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Command;

use Item_Ticket;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class VerifyReportCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugins:companyqr:verify-report')
            ->setDescription('Verifica que existe un ticket vinculado (Item_Ticket) al activo indicado.')
            ->addOption('items-id', null, InputOption::VALUE_REQUIRED, 'items_id del Computer', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        global $DB;
        $itemsId = (int) $input->getOption('items-id');

        $count = 0;
        foreach ($DB->request([
            'COUNT' => 'cpt',
            'FROM'  => Item_Ticket::getTable(),
            'WHERE' => ['itemtype' => 'Computer', 'items_id' => $itemsId],
        ]) as $row) {
            $count = (int) $row['cpt'];
        }

        if ($count > 0) {
            $output->writeln("OK: {$count} ticket(s) vinculado(s) al Computer #{$itemsId}");
            return Command::SUCCESS;
        }
        $output->writeln("FAIL: no hay tickets vinculados al Computer #{$itemsId}");
        return Command::FAILURE;
    }
}
