<?php

namespace Xima\XimaOauth2Extended\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Xima\XimaOauth2Extended\Configuration\GraphSyncConfiguration;
use Xima\XimaOauth2Extended\ResourceResolver\MicrosoftGraphSyncResolver;
use Xima\XimaOauth2Extended\Service\MicrosoftGraphClient;

/**
 * Backend module (admin only) to explore / debug the configured Microsoft Entra
 * (Graph) endpoints: inspect the per-client configuration and field mapping,
 * test the connection (app-only token + a sample /users call) and search users.
 *
 * Read-only: it never creates or modifies TYPO3 users. It is a non-Extbase
 * module; {@see handleRequest()} dispatches on the `action` request parameter.
 */
class GraphDebugController
{
    private const MODULE_NAME = 'tools_ximaoauth2graph';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly MicrosoftGraphClient $graphClient,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle('Microsoft Entra / Graph Debug');

        $params = array_merge($request->getQueryParams(), (array)$request->getParsedBody());
        $action = (string)($params['action'] ?? 'overview');

        return match ($action) {
            'testConnection' => $this->testConnection($moduleTemplate, $params),
            'search' => $this->searchUsers($moduleTemplate, $params),
            'user' => $this->userDetail($moduleTemplate, $params),
            default => $this->overview($moduleTemplate),
        };
    }

    private function overview(ModuleTemplate $moduleTemplate, ?string $notFoundClient = null): ResponseInterface
    {
        $clients = [];
        foreach (GraphSyncConfiguration::loadAll() as $key => $config) {
            $clients[$key] = $this->buildClientView($config);
        }

        $moduleTemplate->assign('clients', $clients);
        $moduleTemplate->assign('hasClients', $clients !== []);
        $moduleTemplate->assign('notFoundClient', $notFoundClient);

        return $moduleTemplate->renderResponse('GraphDebug/Overview');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function testConnection(ModuleTemplate $moduleTemplate, array $params): ResponseInterface
    {
        $config = $this->resolveClient($params);
        if ($config === null) {
            return $this->overview($moduleTemplate, (string)($params['client'] ?? ''));
        }

        try {
            $this->graphClient->getAppAccessToken($config);
            $sample = $this->graphClient->searchUsers($config, '', 3);
            $test = ['ok' => true, 'sampleCount' => count($sample), 'sample' => $sample];
        } catch (\Throwable $e) {
            $test = ['ok' => false, 'error' => $e->getMessage()];
        }

        $moduleTemplate->assign('client', $this->buildClientView($config));
        $moduleTemplate->assign('test', $test);
        $moduleTemplate->assign('backLink', $this->moduleLink('overview'));

        return $moduleTemplate->renderResponse('GraphDebug/TestConnection');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function searchUsers(ModuleTemplate $moduleTemplate, array $params): ResponseInterface
    {
        $config = $this->resolveClient($params);
        if ($config === null) {
            return $this->overview($moduleTemplate, (string)($params['client'] ?? ''));
        }

        $query = trim((string)($params['q'] ?? ''));
        $searched = array_key_exists('q', $params);
        $rows = [];
        $error = null;

        if ($searched) {
            try {
                foreach ($this->graphClient->searchUsers($config, $query, 25) as $user) {
                    $rows[] = [
                        'user' => $user,
                        'detailLink' => $this->moduleLink('user', [
                            'client' => $config->key,
                            'id' => (string)($user['id'] ?? ''),
                        ]),
                    ];
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $moduleTemplate->assign('client', $this->buildClientView($config));
        $moduleTemplate->assign('query', $query);
        $moduleTemplate->assign('searched', $searched);
        $moduleTemplate->assign('rows', $rows);
        $moduleTemplate->assign('error', $error);
        $moduleTemplate->assign('backLink', $this->moduleLink('overview'));

        return $moduleTemplate->renderResponse('GraphDebug/SearchUsers');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function userDetail(ModuleTemplate $moduleTemplate, array $params): ResponseInterface
    {
        $config = $this->resolveClient($params);
        if ($config === null) {
            return $this->overview($moduleTemplate, (string)($params['client'] ?? ''));
        }

        $userId = (string)($params['id'] ?? '');
        $graphUser = null;
        $mapping = null;
        $error = null;

        try {
            $token = $this->graphClient->getAppAccessToken($config);
            $graphUser = $this->graphClient->getUser($config, $userId);
            $mapping = $this->buildMapping($graphUser, $config, $token);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $moduleTemplate->assign('client', $this->buildClientView($config));
        $moduleTemplate->assign('userId', $userId);
        $moduleTemplate->assign('graphUser', $graphUser);
        $moduleTemplate->assign(
            'graphUserJson',
            $graphUser !== null ? (string)json_encode($graphUser, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
        );
        $moduleTemplate->assign('mapping', $mapping);
        $moduleTemplate->assign('error', $error);
        $moduleTemplate->assign('backLink', $this->moduleLink('search', ['client' => $config->key]));

        return $moduleTemplate->renderResponse('GraphDebug/UserDetail');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveClient(array $params): ?GraphSyncConfiguration
    {
        $configs = GraphSyncConfiguration::loadAll();
        $client = (string)($params['client'] ?? '');

        return $configs[$client] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildClientView(GraphSyncConfiguration $config): array
    {
        return [
            'key' => $config->key,
            'tenantId' => $config->tenantId,
            'clientId' => $config->clientId,
            'secretSet' => $config->clientSecret !== '',
            'provider' => $config->provider,
            'frontendUserPid' => $config->frontendUserPid,
            'complete' => $config->isComplete(),
            'options' => $config->options,
            'endpoints' => $this->graphClient->getEndpoints($config),
            'tokenExpiry' => $this->graphClient->getCachedTokenExpiry($config),
            'links' => [
                'test' => $this->moduleLink('testConnection', ['client' => $config->key]),
                'search' => $this->moduleLink('search', ['client' => $config->key]),
            ],
        ];
    }

    /**
     * Resolves how a single Graph user would map onto TYPO3 user records for the
     * given client, without persisting anything.
     *
     * @param array<string, mixed> $graphUser
     * @return array<string, mixed>
     */
    private function buildMapping(array $graphUser, GraphSyncConfiguration $config, string $token): array
    {
        $resolver = new MicrosoftGraphSyncResolver($graphUser, $token, $config->options, $this->graphClient);

        $backend = [];
        $resolver->updateBackendUser($backend);
        $frontend = [];
        $resolver->updateFrontendUser($frontend);

        $remoteGroupIds = $resolver->resolveUserGroups() ?? [];

        return [
            'intendedUsername' => $resolver->getIntendedUsername(),
            'intendedEmail' => $resolver->getIntendedEmail(),
            'backend' => $backend,
            'frontend' => $frontend,
            'remoteGroupIds' => $remoteGroupIds,
            'backendGroups' => $this->matchGroups('be_groups', $remoteGroupIds),
            'frontendGroups' => $this->matchGroups('fe_groups', $remoteGroupIds),
        ];
    }

    /**
     * Matches remote group ids against the `oauth2_id` column of the given TYPO3
     * group table.
     *
     * @param string[] $groupIds
     * @return array<int, array<string, mixed>>
     */
    private function matchGroups(string $table, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $existing = $qb->select('uid', 'title', 'oauth2_id')
            ->from($table)
            ->where($qb->expr()->in('oauth2_id', $qb->quoteArrayBasedValueListToStringList($groupIds)))
            ->executeQuery()
            ->fetchAllAssociative();

        $byOauthId = [];
        foreach ($existing as $row) {
            $byOauthId[(string)$row['oauth2_id']] = $row;
        }

        $result = [];
        foreach ($groupIds as $groupId) {
            $result[] = [
                'oauth2_id' => $groupId,
                'uid' => $byOauthId[$groupId]['uid'] ?? null,
                'title' => $byOauthId[$groupId]['title'] ?? null,
                'exists' => isset($byOauthId[$groupId]),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function moduleLink(string $action, array $params = []): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute(self::MODULE_NAME, array_merge(['action' => $action], $params));
    }
}
