<?php

namespace Xima\XimaOauth2Extended\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Xima\XimaOauth2Extended\Configuration\GraphSyncConfiguration;
use Xima\XimaOauth2Extended\Enum\UserScope;
use Xima\XimaOauth2Extended\Exception\OAuth2ConfigurationException;
use Xima\XimaOauth2Extended\ResourceResolver\MicrosoftGraphSyncResolver;
use Xima\XimaOauth2Extended\UserFactory\BackendUserFactory;

/**
 * Bulk-syncs backend users from Microsoft Graph (app-only) for a single
 * graphSync client by reusing the existing BackendUserFactory pipeline.
 *
 * Every client is self-contained: credentials, identity provider key and sync
 * options all come from the {@see GraphSyncConfiguration} client, never from
 * `oauth2_client_providers`.
 *
 * Idempotency: an existing identity link (provider + Graph object id) routes the
 * record to updateTypo3User(); only genuinely new identities go through
 * registerRemoteUser(), which is the sole path that inserts identity rows.
 */
class BackendUserSyncService
{
    public function __construct(
        private readonly MicrosoftGraphClient $graphClient,
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
        private readonly OrphanedUserReconciler $orphanedUserReconciler,
    ) {
    }

    /**
     * @throws OAuth2ConfigurationException
     * @throws \Xima\XimaOauth2Extended\Exception\GraphApiException
     */
    public function syncClient(GraphSyncConfiguration $config): UserSyncResult
    {
        if (!$config->isComplete()) {
            throw new OAuth2ConfigurationException(
                'Incomplete graphSync client "' . $config->key . '" (tenantId, clientId and clientSecret are required).',
                1718450100
            );
        }

        $token = $this->graphClient->getAppAccessToken($config);
        $users = $this->graphClient->getUsers($config);

        $result = new UserSyncResult();
        $seenIdentifiers = [];
        foreach ($users as $graphUser) {
            $this->syncUser($graphUser, $token, $config, $result);
            $id = (string)($graphUser['id'] ?? '');
            if ($id !== '') {
                $seenIdentifiers[$id] = true;
            }
        }

        $this->orphanedUserReconciler->reconcile(
            UserScope::Backend,
            $config->provider,
            $seenIdentifiers,
            $config->options->orphanedUserAction,
            $result,
            $config->key
        );

        return $result;
    }

    /**
     * Imports a single user (by Graph object id) on demand, e.g. from the
     * backend module. Creation is forced regardless of the client's
     * createBackendUser option, since it is an explicit manual action.
     *
     * @throws OAuth2ConfigurationException
     * @throws \Xima\XimaOauth2Extended\Exception\GraphApiException
     */
    public function importUser(GraphSyncConfiguration $config, string $userId): UserSyncResult
    {
        if (!$config->isComplete()) {
            throw new OAuth2ConfigurationException(
                'Incomplete graphSync client "' . $config->key . '" (tenantId, clientId and clientSecret are required).',
                1718450101
            );
        }

        $token = $this->graphClient->getAppAccessToken($config);
        $graphUser = $this->graphClient->getUser($config, $userId);

        $forced = clone $config;
        $forced->options = clone $config->options;
        $forced->options->createBackendUser = true;

        $result = new UserSyncResult();
        $this->syncUser($graphUser, $token, $forced, $result);

        return $result;
    }

    /**
     * @param array<string, mixed> $graphUser
     */
    private function syncUser(array $graphUser, string $token, GraphSyncConfiguration $config, UserSyncResult $result): void
    {
        $resolver = new MicrosoftGraphSyncResolver($graphUser, $token, $config->options, $this->graphClient);
        $factory = new BackendUserFactory($resolver, $config->provider, $this->logger);
        $identifier = (string)$resolver->getRemoteUser()->getId();

        try {
            $linkedUser = $this->findLinkedBackendUser($config->provider, $identifier);
            if ($linkedUser !== null) {
                $factory->updateTypo3User($linkedUser);
                $result->updated++;
                return;
            }

            if ($factory->registerRemoteUser() !== null) {
                $result->created++;
            } else {
                // No matching user and creation disabled / username missing.
                $result->skipped++;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync backend user (client: ' . $config->key . ', remote id: ' . $identifier . '): ' . $e->getMessage());
            $result->failed++;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLinkedBackendUser(string $provider, string $identifier): ?array
    {
        $qb = $this->getQueryBuilder('tx_oauth2_beuser_provider_configuration');
        $qb->getRestrictions()->removeAll();
        $parentId = $qb->select('parentid')
            ->from('tx_oauth2_beuser_provider_configuration')
            ->where(
                $qb->expr()->eq('provider', $qb->createNamedParameter($provider)),
                $qb->expr()->eq('identifier', $qb->createNamedParameter($identifier))
            )
            ->executeQuery()
            ->fetchOne();

        if (!$parentId) {
            return null;
        }

        $qb = $this->getQueryBuilder('be_users');
        $user = $qb->select('*')
            ->from('be_users')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter((int)$parentId, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return $user ?: null;
    }

    private function getQueryBuilder(string $tableName): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($tableName);
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $qb;
    }
}
