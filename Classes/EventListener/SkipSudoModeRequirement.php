<?php

namespace Xima\XimaOauth2Extended\EventListener;

use TYPO3\CMS\Backend\Security\SudoMode\Event\SudoModeRequiredEvent;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SkipSudoModeRequirement
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    public function __invoke(SudoModeRequiredEvent $event): void
    {
        // Check if sudo mode is required
        if (!$event->isVerificationRequired()) {
            return;
        }

        // Check if the user has an oAuth2 client configuration
        $hasOauth2ClientConfig = $this->getCurrentUser()->user['tx_oauth2_client_configs'] ?? false;
        if (!$hasOauth2ClientConfig) {
            return;
        }

        // User has an oauth2 configuration with enabled user creation
        if (!$this->currentUserHasOauthProviderWithCreation()) {
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

    private function currentUserHasOauthProviderWithCreation(): bool
    {
        $allProviderConfigs = $this->extensionConfiguration->get('xima_oauth2_extended', 'oauth2_client_providers', []);
        $providerWithCreation = array_keys(array_filter($allProviderConfigs, static function ($config) {
            return isset($config['createBackendUser']) && $config['createBackendUser'] === true;
        }));

        if (empty($providerWithCreation)) {
            return false;
        }

        $userUid = $this->getCurrentUser()->getUserId();

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_oauth2_beuser_provider_configuration');
        $userProvider = $queryBuilder->count('uid')
            ->from('tx_oauth2_beuser_provider_configuration')
            ->where(
                $queryBuilder->expr()->eq('parentid', $queryBuilder->createNamedParameter($userUid, Connection::PARAM_INT))
            )
            ->andWhere(
                $queryBuilder->expr()->in(
                    'provider',
                    $queryBuilder->createNamedParameter($providerWithCreation, Connection::PARAM_STR_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchOne();

        return (bool)$userProvider;
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
