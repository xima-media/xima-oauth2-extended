<?php

namespace Xima\XimaOauth2Extended\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xima\XimaOauth2Extended\Configuration\GraphSyncConfiguration;
use Xima\XimaOauth2Extended\Service\FrontendUserSyncService;

/**
 * Syncs frontend users (and their groups) visible to the registered Azure
 * application(s) from Microsoft Graph. Without arguments every configured
 * graphSync client is synced; pass a client key to sync only that one. Runnable
 * from the CLI and as a schedulable command via the TYPO3 Scheduler ("Execute
 * console commands").
 */
class SyncFrontendUsersCommand extends Command
{
    public function __construct(
        private readonly FrontendUserSyncService $syncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Sync frontend users from Microsoft Graph (app-only).');
        $this->addArgument(
            'client',
            InputArgument::OPTIONAL,
            'graphSync client key to sync. If omitted, all configured clients are synced.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $configs = GraphSyncConfiguration::loadAll();
        $clientKey = $input->getArgument('client');
        if ($clientKey !== null) {
            if (!isset($configs[$clientKey])) {
                $io->error(sprintf('Unknown graphSync client "%s".', $clientKey));
                return Command::FAILURE;
            }
            $configs = [$clientKey => $configs[$clientKey]];
        }

        if (empty($configs)) {
            $io->error('No graphSync clients configured.');
            return Command::FAILURE;
        }

        $hasFailure = false;
        foreach ($configs as $key => $config) {
            try {
                $result = $this->syncService->syncClient($config);
            } catch (\Throwable $e) {
                $io->error(sprintf('[%s] %s', $key, $e->getMessage()));
                $hasFailure = true;
                continue;
            }

            $io->success(sprintf(
                '[%s] Frontend user sync finished: %d created, %d updated, %d skipped, %d failed (%d processed).',
                $key,
                $result->created,
                $result->updated,
                $result->skipped,
                $result->failed,
                $result->total()
            ));

            if ($result->failed > 0) {
                $hasFailure = true;
            }
        }

        return $hasFailure ? Command::FAILURE : Command::SUCCESS;
    }
}
