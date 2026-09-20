<?php

/**
 * Probe INTERNO de concurrencia (numeración / envío). Ejecuta UNA operación y imprime el resultado en
 * stdout (`OK:<seq>` / `ERR:<motivo>`), pensado para lanzarse en PARALELO desde el selftest (procesos
 * reales contra MariaDB) y demostrar que la numeración y `submitDraft()` son concurrency-safe.
 *
 * NO está cableado a ninguna UI. Requiere `COMPANYPURCHASING_ALLOW_PROBE=1` en el entorno para correr
 * (evita uso accidental en producción; de todos modos el acceso a `bin/console` ya es privilegiado).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Command;

use GlpiPlugin\Companypurchasing\Model\Request;
use GlpiPlugin\Companypurchasing\Service\NumberingService;
use GlpiPlugin\Companypurchasing\Service\RequestManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ConcurrencyProbeCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugins:companypurchasing:concurrency-probe')
            ->setDescription('Probe INTERNO de concurrencia (numeración/submit). Sólo para pruebas.')
            ->addOption('op', null, InputOption::VALUE_REQUIRED, 'assign | submit')
            ->addOption('entity', null, InputOption::VALUE_OPTIONAL, 'entities_id', '0')
            ->addOption('year', null, InputOption::VALUE_OPTIONAL, 'año', '0')
            ->addOption('request', null, InputOption::VALUE_OPTIONAL, 'requests_id', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (getenv('COMPANYPURCHASING_ALLOW_PROBE') !== '1') {
            $output->writeln('ERR:probe-deshabilitado (defina COMPANYPURCHASING_ALLOW_PROBE=1)');
            return Command::SUCCESS;
        }
        $op = (string) $input->getOption('op');
        try {
            if ($op === 'assign') {
                $seq = (new NumberingService())->assign(
                    (int) $input->getOption('entity'),
                    NumberingService::SCOPE_REQUEST,
                    (int) $input->getOption('year')
                );
                $output->writeln('OK:' . $seq);
                return Command::SUCCESS;
            }
            if ($op === 'submit') {
                $reqId = (int) $input->getOption('request');
                $req = new Request();
                if (!$req->getFromDB($reqId)) {
                    $output->writeln('ERR:inexistente');
                    return Command::SUCCESS;
                }
                $this->applySession((int) $req->fields['users_id_creator'], (int) $req->fields['entities_id']);
                $seq = (new RequestManager())->submitDraft($reqId);
                $output->writeln('OK:' . $seq);
                return Command::SUCCESS;
            }
            $output->writeln('ERR:op-desconocida');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('ERR:' . $e->getMessage());
            return Command::SUCCESS;
        }
    }

    private function applySession(int $userId, int $entity): void
    {
        $full = READ
            | Request::RIGHT_CREATE_REQUEST | Request::RIGHT_VIEW_OWN | Request::RIGHT_VIEW_ENTITY
            | Request::RIGHT_EDIT_DRAFT | Request::RIGHT_MANAGE_CONFIG;
        $_SESSION['glpiID']                      = $userId;
        $_SESSION['glpiname']                    = 'cpur_probe';
        $_SESSION['glpiactive_entity']           = $entity;
        $_SESSION['glpiactiveentities']          = [$entity];
        $_SESSION['glpiactiveentities_string']   = "'" . $entity . "'";
        $_SESSION['glpiactive_entity_recursive'] = 0;
        $_SESSION['glpigroups']                  = [];
        $_SESSION['glpi_currenttime']            = date('Y-m-d H:i:s');
        $_SESSION['glpiactiveprofile']           = ['id' => 1, 'interface' => 'central', 'entities_id' => $entity, 'plugin_companypurchasing' => $full];
    }
}
