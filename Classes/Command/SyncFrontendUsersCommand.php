<?php

namespace Xima\XimaOauth2Extended\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xima\XimaOauth2Extended\Service\FrontendUserSyncService;

/**
 * Syncs all frontend users (and their groups) visible to the registered Azure
 * application from Microsoft Graph. Runnable from the CLI and as a schedulable
 * command via the TYPO3 Scheduler ("Execute console commands" task).
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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->syncService->sync();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Frontend user sync finished: %d created, %d updated, %d skipped, %d failed (%d processed).',
            $result->created,
            $result->updated,
            $result->skipped,
            $result->failed,
            $result->total()
        ));

        return $result->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
