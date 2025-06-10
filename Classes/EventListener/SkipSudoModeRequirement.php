<?php

namespace Xima\XimaOauth2Extended\EventListener;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Security\SudoMode\Event\SudoModeRequiredEvent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SkipSudoModeRequirement
{
    public function __invoke(SudoModeRequiredEvent $event): void
    {
        // Check if sudo mode is required
        $isVerificationRequired = $event->isVerificationRequired();
        if (!$isVerificationRequired) {
            return;
        }

        // Check if the user has an oAuth2 client configuration
        $hasOauth2ClientConfig = $this->getCurrentUser()->user['tx_oauth2_client_configs'] ?? false;
        if (!$hasOauth2ClientConfig) {
            return;
        }

        // Users can always bypass sudo mode if changing their own password
        $subjects = $event->getClaim()->subjects;
        if (isset($subjects[0]) && str_starts_with($subjects[0]->getSubject(), 'be_users.password.')) {
            $event->setVerificationRequired(false);
            return;
        }

        // Admins can bypass any sudo mode
        if ($this->currentUserIsAdmin()) {
            $event->setVerificationRequired(false);
        }
    }

    private function getCurrentUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    private function currentUserIsAdmin(): bool
    {
        $user = $this->getCurrentUser()->getUserId();

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('be_users');
        return (bool)$queryBuilder->select('admin')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($user, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne();
    }
}
