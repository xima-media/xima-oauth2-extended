<?php

namespace Xima\XimaOauth2Extended\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Xima\XimaOauth2Extended\Configuration\GraphSyncConfiguration;
use Xima\XimaOauth2Extended\Exception\OAuth2ConfigurationException;
use Xima\XimaOauth2Extended\ResourceResolver\MicrosoftGraphSyncResolver;
use Xima\XimaOauth2Extended\UserFactory\FrontendUserFactory;

/**
 * Bulk-syncs frontend users (and their groups) from Microsoft Graph (app-only)
 * for a single graphSync client by reusing the existing FrontendUserFactory
 * pipeline.
 *
 * Every client is self-contained: credentials, identity provider key and sync
 * options all come from the {@see GraphSyncConfiguration} client, never from
 * `oauth2_client_providers`.
 *
 * Idempotency: an existing identity link (provider + Graph object id) routes the
 * record to updateTypo3User(); only genuinely new identities go through
 * registerRemoteUser(), which is the sole path that inserts identity rows.
 */
class FrontendUserSyncService
{
    public function __construct(
        private readonly MicrosoftGraphClient $graphClient,
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
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
                1718450200
            );
        }

        $token = $this->graphClient->getAppAccessToken($config);
        $users = $this->graphClient->getUsers($config);

        $result = new UserSyncResult();
        foreach ($users as $graphUser) {
            $this->syncUser($graphUser, $token, $config, $result);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $graphUser
     */
    private function syncUser(array $graphUser, string $token, GraphSyncConfiguration $config, UserSyncResult $result): void
    {
        $resolver = new MicrosoftGraphSyncResolver($graphUser, $token, $config->options, $this->graphClient);
        // Bridge the FrontendUserFactory's array-based reads (createFrontendUser,
        // defaultFrontendUsergroup) to the self-contained client options.
        $factory = new FrontendUserFactory($resolver, $config->provider, [
            $config->provider => [
                'createFrontendUser' => $config->options->createFrontendUser,
                'defaultFrontendUsergroup' => $config->options->defaultFrontendUsergroup,
            ],
        ]);
        $identifier = (string)$resolver->getRemoteUser()->getId();

        try {
            $linkedUser = $this->findLinkedFrontendUser($config->provider, $identifier);
            if ($linkedUser !== null) {
                $factory->updateTypo3User($linkedUser, $config->frontendUserPid);
                $result->updated++;
                return;
            }

            if ($factory->registerRemoteUser($config->frontendUserPid) !== null) {
                $result->created++;
            } else {
                // No matching user and creation disabled / username missing.
                $result->skipped++;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync frontend user (client: ' . $config->key . ', remote id: ' . $identifier . '): ' . $e->getMessage());
            $result->failed++;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLinkedFrontendUser(string $provider, string $identifier): ?array
    {
        $qb = $this->getQueryBuilder('tx_oauth2_feuser_provider_configuration');
        $qb->getRestrictions()->removeAll();
        $parentId = $qb->select('parentid')
            ->from('tx_oauth2_feuser_provider_configuration')
            ->where(
                $qb->expr()->eq('provider', $qb->createNamedParameter($provider)),
                $qb->expr()->eq('identifier', $qb->createNamedParameter($identifier))
            )
            ->executeQuery()
            ->fetchOne();

        if (!$parentId) {
            return null;
        }

        $qb = $this->getQueryBuilder('fe_users');
        $user = $qb->select('*')
            ->from('fe_users')
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
