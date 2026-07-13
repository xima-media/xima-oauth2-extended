<?php

namespace Xima\XimaOauth2Extended\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\Menu\Menu;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Xima\XimaOauth2Extended\Configuration\GraphSyncConfiguration;
use Xima\XimaOauth2Extended\ResourceResolver\MicrosoftGraphSyncResolver;
use Xima\XimaOauth2Extended\Service\BackendUserSyncService;
use Xima\XimaOauth2Extended\Service\FrontendUserSyncService;
use Xima\XimaOauth2Extended\Service\MicrosoftGraphClient;

/**
 * Backend module (admin only) for the configured Microsoft Entra (Graph)
 * clients: browse the remote users, see whether each is already imported as a
 * be_user / fe_user and import them on demand, plus inspect the configuration,
 * field mapping and test the connection.
 *
 * Non-Extbase module; {@see handleRequest()} dispatches on the `action` request
 * parameter. A doc-header dropdown switches between configured clients.
 */
class GraphDebugController
{
    private const MODULE_NAME = 'tools_ximaoauth2graph';

    /** @var string[] */
    private const VIEW_ACTIONS = ['users', 'config', 'testConnection', 'user'];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly MicrosoftGraphClient $graphClient,
        private readonly ConnectionPool $connectionPool,
        private readonly FlashMessageService $flashMessageService,
        private readonly BackendUserSyncService $backendUserSyncService,
        private readonly FrontendUserSyncService $frontendUserSyncService,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $params = array_merge($request->getQueryParams(), (array)$request->getParsedBody());
        $action = (string)($params['action'] ?? 'users');
        $clients = GraphSyncConfiguration::loadAll();

        // Import actions (POST) run, then redirect back to the user list.
        if ($action === 'createBackendUser' || $action === 'createFrontendUser') {
            return $this->createUser($params, $clients, $action === 'createBackendUser' ? 'be' : 'fe');
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        if ($clients === []) {
            $moduleTemplate->assign('noClients', true);
            return $moduleTemplate->renderResponse('GraphDebug/Users');
        }

        $currentKey = (string)($params['client'] ?? array_key_first($clients));
        if (!isset($clients[$currentKey])) {
            $moduleTemplate->addFlashMessage(
                'Unknown graphSync client "' . $currentKey . '".',
                '',
                ContextualFeedbackSeverity::ERROR
            );
            $currentKey = (string)array_key_first($clients);
        }
        $config = $clients[$currentKey];

        $viewAction = in_array($action, self::VIEW_ACTIONS, true) ? $action : 'users';
        $this->configureDocHeader($moduleTemplate, $clients, $currentKey, $viewAction === 'user' ? 'users' : $viewAction);
        $moduleTemplate->setTitle('Microsoft Entra', $config->key);

        return match ($viewAction) {
            'config' => $this->config($moduleTemplate, $config),
            'testConnection' => $this->testConnection($moduleTemplate, $config),
            'user' => $this->userDetail($moduleTemplate, $config, $params),
            default => $this->users($moduleTemplate, $config, $params),
        };
    }

    /**
     * @param array<string, GraphSyncConfiguration> $clients
     */
    private function configureDocHeader(ModuleTemplate $moduleTemplate, array $clients, string $currentKey, string $currentView): void
    {
        $registry = $moduleTemplate->getDocHeaderComponent()->getMenuRegistry();

        // Configuration (client) switcher.
        $clientMenu = new Menu();
        $clientMenu->setIdentifier('client');
        $clientMenu->setLabel('Configuration');
        foreach ($clients as $key => $client) {
            $clientMenu->addMenuItem(
                $clientMenu->makeMenuItem()
                    ->setTitle($client->key)
                    ->setActive($key === $currentKey)
                    ->setHref($this->moduleLink($currentView, ['client' => $key]))
            );
        }
        $registry->addMenu($clientMenu);

        // View switcher for the current client.
        $views = [
            'users' => 'Users',
            'config' => 'Configuration',
            'testConnection' => 'Test connection',
        ];
        $viewMenu = new Menu();
        $viewMenu->setIdentifier('view');
        $viewMenu->setLabel('View');
        foreach ($views as $viewAction => $label) {
            $viewMenu->addMenuItem(
                $viewMenu->makeMenuItem()
                    ->setTitle($label)
                    ->setActive($viewAction === $currentView)
                    ->setHref($this->moduleLink($viewAction, ['client' => $currentKey]))
            );
        }
        $registry->addMenu($viewMenu);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function users(ModuleTemplate $moduleTemplate, GraphSyncConfiguration $config, array $params): ResponseInterface
    {
        $query = trim((string)($params['q'] ?? ''));
        $rows = [];
        $error = null;

        try {
            $graphUsers = $this->graphClient->searchUsers($config, $query, 50);
            $status = $this->resolveImportStatus($config, $graphUsers);
            $returnUrl = $this->moduleLink('users', $query !== ''
                ? ['client' => $config->key, 'q' => $query]
                : ['client' => $config->key]);
            foreach ($graphUsers as $graphUser) {
                $id = (string)($graphUser['id'] ?? '');
                $be = $status[$id]['be'] ?? ['imported' => false, 'exists' => false, 'uid' => null];
                $fe = $status[$id]['fe'] ?? ['imported' => false, 'exists' => false, 'uid' => null];
                if ($be['uid'] !== null) {
                    $be['editLink'] = $this->editRecordUri('be_users', $be['uid'], $returnUrl);
                }
                if ($fe['uid'] !== null) {
                    $fe['editLink'] = $this->editRecordUri('fe_users', $fe['uid'], $returnUrl);
                }
                $rows[] = [
                    'user' => $graphUser,
                    'detailLink' => $this->moduleLink('user', ['client' => $config->key, 'id' => $id]),
                    'be' => $be,
                    'fe' => $fe,
                ];
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $moduleTemplate->assign('client', $this->buildClientView($config));
        $moduleTemplate->assign('query', $query);
        $moduleTemplate->assign('rows', $rows);
        $moduleTemplate->assign('error', $error);

        return $moduleTemplate->renderResponse('GraphDebug/Users');
    }

    private function config(ModuleTemplate $moduleTemplate, GraphSyncConfiguration $config): ResponseInterface
    {
        $moduleTemplate->assign('client', $this->buildClientView($config));

        return $moduleTemplate->renderResponse('GraphDebug/Config');
    }

    private function testConnection(ModuleTemplate $moduleTemplate, GraphSyncConfiguration $config): ResponseInterface
    {
        try {
            $this->graphClient->getAppAccessToken($config);
            $sample = $this->graphClient->searchUsers($config, '', 3);
            $test = ['ok' => true, 'sampleCount' => count($sample), 'sample' => $sample];
        } catch (\Throwable $e) {
            $test = ['ok' => false, 'error' => $e->getMessage()];
        }

        $moduleTemplate->assign('client', $this->buildClientView($config));
        $moduleTemplate->assign('test', $test);

        return $moduleTemplate->renderResponse('GraphDebug/TestConnection');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function userDetail(ModuleTemplate $moduleTemplate, GraphSyncConfiguration $config, array $params): ResponseInterface
    {
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
        $moduleTemplate->assign('backLink', $this->moduleLink('users', ['client' => $config->key]));

        return $moduleTemplate->renderResponse('GraphDebug/UserDetail');
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, GraphSyncConfiguration> $clients
     */
    private function createUser(array $params, array $clients, string $type): ResponseInterface
    {
        $clientKey = (string)($params['client'] ?? '');
        $config = $clients[$clientKey] ?? null;
        $userId = (string)($params['id'] ?? '');
        $queue = $this->flashMessageService->getMessageQueueByIdentifier();

        if ($config === null || $userId === '') {
            $queue->enqueue(new FlashMessage('Invalid import request.', '', ContextualFeedbackSeverity::ERROR, true));

            return new RedirectResponse($this->moduleLink('users', $config !== null ? ['client' => $clientKey] : []));
        }

        $listUrl = $this->moduleLink('users', ['client' => $clientKey]);
        $label = $type === 'be' ? 'Backend' : 'Frontend';
        try {
            $result = $type === 'be'
                ? $this->backendUserSyncService->importUser($config, $userId)
                : $this->frontendUserSyncService->importUser($config, $userId);

            if ($result->failed > 0) {
                $queue->enqueue(new FlashMessage($label . ' user import failed — see the log for details.', '', ContextualFeedbackSeverity::ERROR, true));
            } elseif ($result->created > 0) {
                $queue->enqueue(new FlashMessage($label . ' user created.', '', ContextualFeedbackSeverity::OK, true));
            } elseif ($result->updated > 0) {
                $queue->enqueue(new FlashMessage($label . ' user already existed and was updated.', '', ContextualFeedbackSeverity::INFO, true));
            } else {
                $queue->enqueue(new FlashMessage($label . ' user was skipped (creation disabled or no username).', '', ContextualFeedbackSeverity::WARNING, true));
            }

            // After a backend import, jump to the user's edit form so the admin
            // can assign backend user groups (created users are disabled and have
            // no groups yet).
            if ($type === 'be' && $result->failed === 0 && ($result->created > 0 || $result->updated > 0)) {
                $links = $this->fetchIdentityLinks('tx_oauth2_beuser_provider_configuration', $config->provider, [$userId]);
                $uid = $links[$userId] ?? null;
                if ($uid !== null) {
                    return new RedirectResponse($this->editRecordUri('be_users', $uid, $listUrl));
                }
            }
        } catch (\Throwable $e) {
            $queue->enqueue(new FlashMessage($e->getMessage(), $label . ' user import failed', ContextualFeedbackSeverity::ERROR, true));
        }

        return new RedirectResponse($listUrl);
    }

    /**
     * Builds a FormEngine edit URL for a record, returning to $returnUrl on close.
     */
    private function editRecordUri(string $table, int $uid, string $returnUrl): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [$table => [$uid => 'edit']],
            'returnUrl' => $returnUrl,
        ]);
    }

    /**
     * Determines, for each remote user, whether it is already imported (identity
     * link present) or at least exists (matched by username/email) as be_user /
     * fe_user. Uses bulk queries to avoid a query per user.
     *
     * @param array<int, array<string, mixed>> $graphUsers
     * @return array<string, array{be: array{imported: bool, exists: bool, uid: int|null}, fe: array{imported: bool, exists: bool, uid: int|null}}>
     */
    private function resolveImportStatus(GraphSyncConfiguration $config, array $graphUsers): array
    {
        if ($graphUsers === []) {
            return [];
        }

        $ids = [];
        $usernames = [];
        $intended = [];
        foreach ($graphUsers as $graphUser) {
            $id = (string)($graphUser['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $upn = strtolower((string)($graphUser['userPrincipalName'] ?? ''));
            $mail = strtolower((string)($graphUser['mail'] ?? ''));
            $username = $upn !== '' ? $upn : $mail;
            $email = $mail !== '' ? $mail : $upn;

            $ids[] = $id;
            $intended[$id] = ['username' => $username, 'email' => $email];
            if ($username !== '') {
                $usernames[$username] = true;
            }
            if ($email !== '') {
                $usernames[$email] = true;
            }
        }

        $needles = array_keys($usernames);
        $beLinks = $this->fetchIdentityLinks('tx_oauth2_beuser_provider_configuration', $config->provider, $ids);
        $feLinks = $this->fetchIdentityLinks('tx_oauth2_feuser_provider_configuration', $config->provider, $ids);
        $beUsers = $this->fetchUsersByUsernameOrEmail('be_users', $needles);
        $feUsers = $this->fetchUsersByUsernameOrEmail('fe_users', $needles);

        $status = [];
        foreach ($ids as $id) {
            $username = $intended[$id]['username'];
            $email = $intended[$id]['email'];
            $status[$id] = [
                'be' => $this->buildStatus($id, $username, $email, $beLinks, $beUsers),
                'fe' => $this->buildStatus($id, $username, $email, $feLinks, $feUsers),
            ];
        }

        return $status;
    }

    /**
     * @param array<string, int> $links   identifier => parentid
     * @param array<string, int> $users   username|email => uid
     * @return array{imported: bool, exists: bool, uid: int|null}
     */
    private function buildStatus(string $id, string $username, string $email, array $links, array $users): array
    {
        if (isset($links[$id])) {
            return ['imported' => true, 'exists' => true, 'uid' => $links[$id]];
        }

        $uid = $users[$username] ?? $users[$email] ?? null;

        return ['imported' => false, 'exists' => $uid !== null, 'uid' => $uid];
    }

    /**
     * @param string[] $ids
     * @return array<string, int> identifier => parentid
     */
    private function fetchIdentityLinks(string $table, string $provider, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('identifier', 'parentid')
            ->from($table)
            ->where(
                $qb->expr()->eq('provider', $qb->createNamedParameter($provider)),
                $qb->expr()->in('identifier', $qb->quoteArrayBasedValueListToStringList($ids))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $links = [];
        foreach ($rows as $row) {
            $links[(string)$row['identifier']] = (int)$row['parentid'];
        }

        return $links;
    }

    /**
     * @param string[] $needles usernames and emails
     * @return array<string, int> username|email => uid
     */
    private function fetchUsersByUsernameOrEmail(string $table, array $needles): array
    {
        if ($needles === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $rows = $qb->select('uid', 'username', 'email')
            ->from($table)
            ->where(
                $qb->expr()->or(
                    $qb->expr()->in('username', $qb->quoteArrayBasedValueListToStringList($needles)),
                    $qb->expr()->in('email', $qb->quoteArrayBasedValueListToStringList($needles))
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $username = strtolower((string)$row['username']);
            $email = strtolower((string)$row['email']);
            if ($username !== '') {
                $map[$username] = (int)$row['uid'];
            }
            if ($email !== '') {
                $map[$email] = (int)$row['uid'];
            }
        }

        return $map;
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
        ];
    }

    /**
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

        $groupDetails = $resolver->resolveUserGroupDetails() ?? [];
        $titleById = [];
        foreach ($groupDetails as $group) {
            $titleById[$group->id] = $group->title;
        }

        $hierarchy = [];
        foreach ($groupDetails as $group) {
            $parents = [];
            foreach ($group->parentIds as $parentId) {
                $parents[] = ['id' => $parentId, 'title' => $titleById[$parentId] ?? $parentId];
            }
            $hierarchy[] = ['id' => $group->id, 'title' => $group->title, 'parents' => $parents];
        }

        $remoteGroupIds = array_keys($titleById);

        return [
            'intendedUsername' => $resolver->getIntendedUsername(),
            'intendedEmail' => $resolver->getIntendedEmail(),
            'backend' => $backend,
            'frontend' => $frontend,
            'remoteGroupIds' => $remoteGroupIds,
            'groupHierarchy' => $hierarchy,
            'backendGroups' => $this->matchGroups('be_groups', $remoteGroupIds, $titleById),
            'frontendGroups' => $this->matchGroups('fe_groups', $remoteGroupIds, $titleById),
        ];
    }

    /**
     * @param string[] $groupIds
     * @param array<string, string> $titleById remote display names keyed by id
     * @return array<int, array<string, mixed>>
     */
    private function matchGroups(string $table, array $groupIds, array $titleById = []): array
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
                'remoteTitle' => $titleById[$groupId] ?? '',
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
