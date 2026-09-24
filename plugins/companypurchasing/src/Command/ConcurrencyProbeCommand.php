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
use GlpiPlugin\Companypurchasing\Service\ApprovalOrchestrator;
use GlpiPlugin\Companypurchasing\Service\DocumentVersionAllocator;
use GlpiPlugin\Companypurchasing\Service\NumberingService;
use GlpiPlugin\Companypurchasing\Service\QuoteManager;
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
            ->setDescription('Probe INTERNO de concurrencia (numeración/submit/cotización/versiones/aprobación). Sólo para pruebas.')
            ->addOption('op', null, InputOption::VALUE_REQUIRED, 'assign | submit | edit | addline | select-quote | docversion | approve')
            ->addOption('entity', null, InputOption::VALUE_OPTIONAL, 'entities_id', '0')
            ->addOption('year', null, InputOption::VALUE_OPTIONAL, 'año', '0')
            ->addOption('request', null, InputOption::VALUE_OPTIONAL, 'requests_id', '0')
            // P2D-2
            ->addOption('user', null, InputOption::VALUE_OPTIONAL, 'users_id del actor (P2D-2)', '0')
            ->addOption('quote', null, InputOption::VALUE_OPTIONAL, 'quotes_id (select-quote)', '0')
            ->addOption('expect', null, InputOption::VALUE_OPTIONAL, 'lock_version esperado (select-quote)', '-1')
            ->addOption('scope', null, InputOption::VALUE_OPTIONAL, 'scope (docversion)', 'REQUEST_SCOPE')
            ->addOption('hash', null, InputOption::VALUE_OPTIONAL, 'sha256 del payload (docversion)', '');
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

            // Operaciones sobre una solicitud existente (submit / edit / addline): se aplica una sesión
            // con los derechos del creador en la entidad de la solicitud.
            $reqId = (int) $input->getOption('request');
            $req = new Request();
            if (!$req->getFromDB($reqId)) {
                $output->writeln('ERR:inexistente');
                return Command::SUCCESS;
            }
            $actor = (int) $input->getOption('user');
            $this->applySession($actor > 0 ? $actor : (int) $req->fields['users_id_creator'], (int) $req->fields['entities_id']);
            $rm = new RequestManager();
            switch ($op) {
                // ---- P2D-2 ----
                case 'select-quote':
                    $expect = (int) $input->getOption('expect');
                    (new QuoteManager())->selectQuote($reqId, (int) $input->getOption('quote'), $expect >= 0 ? $expect : null);
                    $output->writeln('OK:selected-' . (int) $input->getOption('quote'));
                    return Command::SUCCESS;
                case 'docversion':
                    $a = (new DocumentVersionAllocator())->allocateOrReuse($reqId, (string) $input->getOption('scope'), (string) $input->getOption('hash'));
                    $output->writeln('OK:' . $a['document_version'] . ':' . ($a['reused'] ? 'reused' : 'new'));
                    return Command::SUCCESS;
                case 'approve':
                    $r = (new ApprovalOrchestrator())->decide($reqId, 'approve', 'probe-' . getmypid());
                    $output->writeln('OK:' . $r['status'] . ':v' . (int) ($r['document_version'] ?? 0));
                    return Command::SUCCESS;
                case 'submit':
                    $output->writeln('OK:' . $rm->submitDraft($reqId));
                    return Command::SUCCESS;
                case 'edit':
                    $rm->updateDraft($reqId, ['observations' => 'edited-' . getmypid()]);
                    $output->writeln('OK:edited');
                    return Command::SUCCESS;
                case 'addline':
                    $lineId = $rm->addLine($reqId, [
                        'description' => 'conc-' . getmypid(), 'quantity' => '1',
                        'estimated_unit_price' => '1000', 'is_inventoriable' => 0,
                    ]);
                    $output->writeln('OK:' . $lineId);
                    return Command::SUCCESS;
                default:
                    $output->writeln('ERR:op-desconocida');
                    return Command::SUCCESS;
            }
        } catch (\Throwable $e) {
            $output->writeln('ERR:' . $e->getMessage());
            return Command::SUCCESS;
        }
    }

    private function applySession(int $userId, int $entity): void
    {
        $full = READ
            | Request::RIGHT_CREATE_REQUEST | Request::RIGHT_VIEW_OWN | Request::RIGHT_VIEW_ENTITY
            | Request::RIGHT_EDIT_DRAFT | Request::RIGHT_MANAGE_CONFIG | Request::RIGHT_MANAGE_PURCHASING;
        $_SESSION['glpiID']                      = $userId;
        $_SESSION['glpiname']                    = 'cpur_probe';
        $_SESSION['glpiactive_entity']           = $entity;
        $_SESSION['glpiactiveentities']          = [$entity];
        $_SESSION['glpiactiveentities_string']   = "'" . $entity . "'";
        $_SESSION['glpiactive_entity_recursive'] = 0;
        $_SESSION['glpigroups']                  = [];
        $_SESSION['glpi_currenttime']            = date('Y-m-d H:i:s');
        // P2D-2: el actor de la saga necesita actuar en el motor (READ|RIGHT_ACT=2) y registrar versiones en
        // Firma (RIGHT_RECORD=2 ⊂ ALLSTANDARDRIGHT). La AUTORIZACIÓN real de cada decisión la da el motor
        // (grupo aprobador + quórum), no estos bits.
        $_SESSION['glpiactiveprofile']           = [
            'id' => 1, 'interface' => 'central', 'entities_id' => $entity,
            'plugin_companypurchasing' => $full,
            'plugin_companyworkflow'   => READ | 2,
            'plugin_companysignature'  => ALLSTANDARDRIGHT,
        ];
    }
}
