<?php

/**
 * `plugins:companyintegrations:si4-run` — ejecuta UNA corrida del worker SI-4 (SI4-1 ADR-0020 + SI4-2 ADR-0021 + SI4-3
 * ADR-0022): finalizador de sagas QR_READY; Snipe create-or-reconcile y, en la misma saga, activo GLPI + Infocom +
 * asset_bridge (`BRIDGED`), código companyqr + etiqueta (`QR_READY`), `acknowledgeProcessed()` y `COMPLETED`.
 *
 * DESHABILITADO por defecto (`si4_enabled = 0`) y SIN Acción automática: su activación y programación son una
 * decisión operativa posterior (staging primero). Uso previsto hoy: staging.
 *
 *   php bin/console plugins:companyintegrations:si4-run --user=<id técnico> [--profile=<id>]
 *
 * Exit: 0 = corrida completa (o deshabilitado: nada que hacer); 1 = abortada (config/auth/Snipe/ACL).
 * El token de Snipe se lee SÓLO de la variable de entorno `COMPANYINTEGRATIONS_SNIPEIT_TOKEN` (nunca de BD/Git/logs).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Command;

use GlpiPlugin\Companyintegrations\Client\CurlTransport;
use GlpiPlugin\Companyintegrations\Client\SnipeAssetWriter;
use GlpiPlugin\Companyintegrations\Service\LogSanitizer;
use GlpiPlugin\Companyintegrations\Service\PluginConfig;
use GlpiPlugin\Companyintegrations\Service\SnipeConfigFactory;
use GlpiPlugin\Companyintegrations\Si4\CoreGlpiAssetGateway;
use GlpiPlugin\Companyintegrations\Si4\CoreQrGateway;
use GlpiPlugin\Companyintegrations\Si4\DbBridgeStore;
use GlpiPlugin\Companyintegrations\Si4\DbGlpiMappingResolver;
use GlpiPlugin\Companyintegrations\Si4\DbMappingResolver;
use GlpiPlugin\Companyintegrations\Si4\DbSagaStore;
use GlpiPlugin\Companyintegrations\Si4\HandoffSource;
use GlpiPlugin\Companyintegrations\Si4\PurchasingHandoffSource;
use GlpiPlugin\Companyintegrations\Si4\Si4Config;
use GlpiPlugin\Companyintegrations\Si4\Si4Errors;
use GlpiPlugin\Companyintegrations\Si4\Si4GlpiStage;
use GlpiPlugin\Companyintegrations\Si4\Si4QrStage;
use GlpiPlugin\Companyintegrations\Si4\Si4Worker;
use GlpiPlugin\Companyintegrations\Si4\WorkerSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Si4RunCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('plugins:companyintegrations:si4-run')
            ->setDescription('SI-4 (SI4-1 + SI4-2 + SI4-3): consume el handoff de Compras por lease; Snipe-IT, activo GLPI + Infocom + asset_bridge, código companyqr + etiqueta, ack y cierre. Deshabilitado por defecto.')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'ID del usuario técnico (RIGHT_SI4 + RIGHT_INTEGRATION + companyqr generate/print + alta de activos/Infocom)')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'ID de perfil del usuario técnico (opcional)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cfg = Si4Config::fromArray(PluginConfig::all());
        if (!$cfg->enabled) {
            $output->writeln('<comment>' . __('SI-4 worker is disabled (si4_enabled = 0): nothing to do.', 'companyintegrations') . '</comment>');
            return Command::SUCCESS;
        }
        $sanitizer = new LogSanitizer();
        $logger = static function (string $level, string $message, array $ctx) use ($output, $sanitizer): void {
            $line = json_encode(['level' => $level, 'msg' => $sanitizer->redact($message)] + $sanitizer->redactArray($ctx), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            if (class_exists('Toolbox')) {
                \Toolbox::logInFile('companyintegrations-si4', $line . "\n");
            }
            if ($output->isVerbose() || $level === 'error') {
                $output->writeln($line);
            }
        };
        try {
            WorkerSession::open((int) $input->getOption('user'), (int) $input->getOption('profile'));
            $client = (new SnipeConfigFactory())->fromConfig();
            if (!$client->hasToken()) {
                throw new \RuntimeException('falta el token de Snipe en la variable de entorno ' . PluginConfig::TOKEN_ENV);
            }
            $worker = self::buildWorker($cfg, new SnipeAssetWriter(new CurlTransport(), $client, $logger), $client->timeoutMs, $client->maxRetries, $logger);
            $m = $worker->run();
        } catch (\Throwable $e) {
            $output->writeln('<error>SI-4: ' . Si4Errors::sanitize($e->getMessage()) . '</error>');
            return Command::FAILURE;
        }
        $output->writeln(json_encode(array_diff_key($m, ['config_errors' => 1]), JSON_UNESCAPED_UNICODE) ?: '');
        if ($m['aborted'] !== null) {
            foreach ((array) $m['config_errors'] as $err) {
                $output->writeln('<error>' . Si4Errors::sanitize((string) $err) . '</error>');
            }
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }

    /**
     * Cableado de PRODUCCIÓN del worker: saga en BD, handoff SÓLO por la API de Compras, etapas GLPI de SI4-2 con la API
     * nativa de GLPI y etapa QR de SI4-3 con la API pública de companyqr (público para que el selftest verifique que el
     * comando real incluye todas las etapas). Los parámetros opcionales son SÓLO para tests (null/true en producción):
     * `$withQr = false` reproduce el modo SI4-2 (estacionar en BRIDGED, sin ack), `$source` envuelve la API de Compras
     * y `$probe` inyecta crashes.
     *
     * @param callable(string,string,array<string,mixed>):void $logger
     * @param callable(string,string):void|null $probe
     */
    public static function buildWorker(Si4Config $cfg, SnipeAssetWriter $writer, int $timeoutMs, int $maxRetries, callable $logger,
        bool $withQr = true, ?HandoffSource $source = null, ?callable $probe = null): Si4Worker
    {
        $sagas = new DbSagaStore();
        $bridges = new DbBridgeStore();
        $glpi = new CoreGlpiAssetGateway();
        return new Si4Worker(
            $source ?? new PurchasingHandoffSource(),
            $sagas,
            new DbMappingResolver($cfg->statusId),
            $writer,
            $cfg,
            $timeoutMs,
            $maxRetries,
            $logger,
            $probe,
            null,
            new Si4GlpiStage($sagas, $glpi, new DbGlpiMappingResolver(), $bridges, $cfg->infocomCurrency, $probe),
            $withQr ? new Si4QrStage($sagas, $bridges, $glpi, new CoreQrGateway(), $probe) : null
        );
    }
}
