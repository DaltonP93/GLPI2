<?php

/**
 * Prepara (idempotente) un activo + código para el E2E HTTP y emite el token.
 * Nombre: `plugins:companyqr:emit-fixture`.
 *
 * Imprime en stdout:  token=<token>   items_id=<id>
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Command;

use Computer;
use GlpiPlugin\Companyqr\Service\CodeManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class EmitFixtureCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugins:companyqr:emit-fixture')
            ->setDescription('Crea (idempotente) un activo + código para el E2E HTTP y emite el token.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Sesión permisiva para crear el fixture (no comprueba ACL aquí).
        $_SESSION['glpiID'] = 2;
        $_SESSION['glpiname'] = 'qr_e2e';
        $_SESSION['glpiactive_entity'] = 0;
        $_SESSION['glpiactiveentities'] = [0];
        $_SESSION['glpiactiveentities_string'] = "'0'";
        $_SESSION['glpiactiveprofile'] = ['id' => 4, 'interface' => 'central', 'entities_id' => 0, 'computer' => ALLSTANDARDRIGHT];

        $computer = new Computer();
        if (!$computer->getFromDBByCrit(['name' => 'QR-E2E', 'entities_id' => 0])) {
            $id = (int) $computer->add([
                'name'        => 'QR-E2E',
                'entities_id' => 0,
                'otherserial' => 'NB-E2E',
            ]);
            $computer->getFromDB($id);
        }

        $code = (new CodeManager())->getOrCreateForItem($computer);

        $output->writeln('token=' . $code->fields['token']);
        $output->writeln('items_id=' . $computer->getID());

        return Command::SUCCESS;
    }
}
