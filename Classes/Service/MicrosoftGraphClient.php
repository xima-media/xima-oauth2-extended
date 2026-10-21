<?php

namespace Xima\XimaOauth2Extended\Service;

use League\OAuth2\Client\Grant\ClientCredentials;
use League\OAuth2\Client\Provider\GenericProvider;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Registry;
use Xima\XimaOauth2Extended\Configuration\GraphSyncConfiguration;
use Xima\XimaOauth2Extended\Exception\GraphApiException;
use Xima\XimaOauth2Extended\Exception\OAuth2ConfigurationException;
use Xima\XimaOauth2Extended\ResourceResolver\RemoteGroup;

/**
 * App-only (client credentials) Microsoft Graph client used for bulk user sync.
 *
 * The application access token is cached in sys_registry and re-acquired on
 * demand once it has expired. The client credentials grant does not issue a
 * refresh token, so re-acquisition is the refresh.
 */
class MicrosoftGraphClient
{
    private const REGISTRY_NAMESPACE = 'xima_oauth2_extended';
    private const REGISTRY_TOKEN_KEY = 'graphAppToken';

    /**
     * Re-acquire the token slightly before it actually expires to avoid using a
     * token that lapses mid-request.
     */
    private const EXPIRY_BUFFER_SECONDS = 60;

    private const GRAPH_SCOPE = 'https://graph.microsoft.com/.default';
    private const GRAPH_BASE_URL = 'https://graph.microsoft.com/v1.0';

    /**
     * Properties requested for every user, whether fetched in bulk, by search or
     * individually. Beyond the identity fields needed to map a TYPO3 user, this
     * carries the contact properties (phone numbers, office location, job title,
     * department) that listeners on the sync events use to build contact
     * records. All of them are standard, selectable `microsoft.graph.user`
     * properties — Graph rejects the whole request with 400 on an unknown one.
     */
    private const USER_SELECT = 'id,userPrincipalName,mail,displayName,givenName,surname,accountEnabled,'
        . 'jobTitle,department,officeLocation,mobilePhone,businessPhones';

    /**
     * Per-run cache of group parent lookups (group id => parent group ids), so
     * each group's hierarchy is resolved once across a whole sync run.
     *
     * @var array<string, string[]>
     */
    private array $groupParentCache = [];

    public function __construct(
        private readonly Registry $registry,
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns a valid app-only access token, acquiring (and caching) a new one
     * if no cached token exists or the cached token has expired.
     *
     * @throws OAuth2ConfigurationException
     * @throws GraphApiException
     */
    public function getAppAccessToken(GraphSyncConfiguration $config): string
    {
        if (!$config->isComplete()) {
            throw new OAuth2ConfigurationException(
                'Incomplete graphSync client "' . $config->key . '" (tenantId, clientId and clientSecret are required).',
                1718450000
            );
        }

        $cached = $this->registry->get(self::REGISTRY_NAMESPACE, $this->tokenCacheKey($config));
        if (is_array($cached)
            && ($cached['client_id'] ?? null) === $config->clientId
            && !empty($cached['access_token'])
            && (int)($cached['expires_at'] ?? 0) > time() + self::EXPIRY_BUFFER_SECONDS
        ) {
            return (string)$cached['access_token'];
        }

        return $this->acquireAndCacheToken($config);
    }

    /**
     * Per-client registry key so tokens for different tenants/clients do not
     * evict each other.
     */
    private function tokenCacheKey(GraphSyncConfiguration $config): string
    {
        return self::REGISTRY_TOKEN_KEY . '_' . $config->clientId . '_' . $config->tenantId;
    }

    /**
     * Fetches all users visible to the application, following Graph paging
     * (`@odata.nextLink`) until the result set is exhausted.
     *
     * @return array<int, array<string, mixed>>
     * @throws OAuth2ConfigurationException
     * @throws GraphApiException
     */
    public function getUsers(GraphSyncConfiguration $config): array
    {
        $token = $this->getAppAccessToken($config);

        $url = self::GRAPH_BASE_URL . '/users?$select=' . self::USER_SELECT . '&$top=999';

        $users = [];
        while ($url !== null) {
            $page = $this->requestJson($url, $token);
            foreach ($page['value'] ?? [] as $user) {
                $users[] = $user;
            }
            $url = $page['@odata.nextLink'] ?? null;
        }

        return $users;
    }

    /**
     * Searches users by display name, UPN or mail. An empty query returns the
     * first `$top` users. Intended for the backend debugging module.
     *
     * @return array<int, array<string, mixed>>
     * @throws OAuth2ConfigurationException
     * @throws GraphApiException
     */
    public function searchUsers(GraphSyncConfiguration $config, string $query, int $top = 25): array
    {
        $token = $this->getAppAccessToken($config);
        $top = max(1, min($top, 100));

        $query = trim($query);
        if ($query === '') {
            $url = self::GRAPH_BASE_URL . '/users?$select=' . self::USER_SELECT . '&$top=' . $top;

            return $this->requestJson($url, $token)['value'] ?? [];
        }

        // $search needs the ConsistencyLevel: eventual header. Strip quotes to
        // keep the search expression well-formed.
        $term = str_replace('"', '', $query);
        $search = rawurlencode(
            '"displayName:' . $term . '" OR "userPrincipalName:' . $term . '" OR "mail:' . $term . '" OR "givenName:' . $term . '" OR "surname:' . $term . '"'
        );
        $url = self::GRAPH_BASE_URL . '/users?$select=' . self::USER_SELECT . '&$top=' . $top . '&$search=' . $search;

        return $this->requestJson($url, $token, ['ConsistencyLevel' => 'eventual'])['value'] ?? [];
    }

    /**
     * Fetches a single user by object id or userPrincipalName.
     *
     * @return array<string, mixed>
     * @throws OAuth2ConfigurationException
     * @throws GraphApiException
     */
    public function getUser(GraphSyncConfiguration $config, string $userId): array
    {
        $token = $this->getAppAccessToken($config);
        $url = self::GRAPH_BASE_URL . '/users/' . rawurlencode($userId) . '?$select=' . self::USER_SELECT;

        return $this->requestJson($url, $token);
    }

    /**
     * Describes the concrete Graph endpoints used for a client, for display in
     * the debugging module.
     *
     * @return array<string, string>
     */
    public function getEndpoints(GraphSyncConfiguration $config): array
    {
        return [
            'tokenEndpoint' => 'https://login.microsoftonline.com/' . $config->tenantId . '/oauth2/v2.0/token',
            'scope' => self::GRAPH_SCOPE,
            'graphBaseUrl' => self::GRAPH_BASE_URL,
            'usersEndpoint' => self::GRAPH_BASE_URL . '/users',
            'memberOfEndpoint' => self::GRAPH_BASE_URL . '/users/{id}/memberOf',
            'photoEndpoint' => self::GRAPH_BASE_URL . '/users/{id}/photo/$value',
        ];
    }

    /**
     * Returns the expiry timestamp of the currently cached app token for the
     * client, or null when no (matching) token is cached.
     */
    public function getCachedTokenExpiry(GraphSyncConfiguration $config): ?int
    {
        $cached = $this->registry->get(self::REGISTRY_NAMESPACE, $this->tokenCacheKey($config));
        if (is_array($cached)
            && ($cached['client_id'] ?? null) === $config->clientId
            && !empty($cached['expires_at'])
        ) {
            return (int)$cached['expires_at'];
        }

        return null;
    }

    /**
     * Returns the groups the given user is a member of, including nested
     * (transitive) memberships, with display names and — optionally — the Entra
     * nesting (each group's parent groups). Used for group mapping via the
     * `oauth2_id` column.
     *
     * `transitiveMemberOf` flattens nested group memberships, so a user is
     * mapped to parent groups it inherits through nesting, not just its direct
     * groups.
     *
     * @return RemoteGroup[]
     * @throws GraphApiException
     */
    public function getUserGroups(string $userId, string $token, bool $withHierarchy = false): array
    {
        $url = self::GRAPH_BASE_URL . '/users/' . rawurlencode($userId)
            . '/transitiveMemberOf/microsoft.graph.group?$select=id,displayName&$top=999';

        $names = [];
        while ($url !== null) {
            $page = $this->requestJson($url, $token);
            foreach ($page['value'] ?? [] as $group) {
                if (!empty($group['id'])) {
                    $names[(string)$group['id']] = (string)($group['displayName'] ?? $group['id']);
                }
            }
            $url = $page['@odata.nextLink'] ?? null;
        }

        $knownIds = array_keys($names);
        $groups = [];
        foreach ($names as $id => $title) {
            $parentIds = [];
            if ($withHierarchy) {
                // Restrict parents to groups the user actually has. Because
                // transitiveMemberOf already includes all ancestors, this only
                // guards against unexpected non-group parents.
                $parentIds = array_values(array_intersect($this->getGroupParentIds((string)$id, $token), $knownIds));
            }
            $groups[] = new RemoteGroup((string)$id, $title, $parentIds);
        }

        return $groups;
    }

    /**
     * Returns the directory object ids of the groups the given group is a direct
     * member of (its parents). Cached per run so each group is resolved once.
     *
     * @return string[]
     * @throws GraphApiException
     */
    private function getGroupParentIds(string $groupId, string $token): array
    {
        if (isset($this->groupParentCache[$groupId])) {
            return $this->groupParentCache[$groupId];
        }

        $url = self::GRAPH_BASE_URL . '/groups/' . rawurlencode($groupId)
            . '/memberOf/microsoft.graph.group?$select=id&$top=999';

        $parents = [];
        while ($url !== null) {
            $page = $this->requestJson($url, $token);
            foreach ($page['value'] ?? [] as $group) {
                if (!empty($group['id'])) {
                    $parents[] = (string)$group['id'];
                }
            }
            $url = $page['@odata.nextLink'] ?? null;
        }

        return $this->groupParentCache[$groupId] = $parents;
    }

    /**
     * Returns the raw bytes of the user's profile photo, or null when the user
     * has no photo (Graph returns 404) or the request fails.
     */
    public function getUserPhoto(string $userId, string $token): ?string
    {
        $url = self::GRAPH_BASE_URL . '/users/' . rawurlencode($userId) . '/photo/$value';

        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);
        } catch (\Throwable) {
            return null;
        }

        $body = $response->getBody()->getContents();

        return $body !== '' ? $body : null;
    }

    /**
     * @throws OAuth2ConfigurationException
     * @throws GraphApiException
     */
    private function acquireAndCacheToken(GraphSyncConfiguration $config): string
    {
        $provider = new GenericProvider([
            'clientId' => $config->clientId,
            'clientSecret' => $config->clientSecret,
            'urlAccessToken' => 'https://login.microsoftonline.com/' . $config->tenantId . '/oauth2/v2.0/token',
            // Unused for the client credentials grant but required by GenericProvider.
            'urlAuthorize' => 'https://login.microsoftonline.com/' . $config->tenantId . '/oauth2/v2.0/authorize',
            'urlResourceOwnerDetails' => self::GRAPH_BASE_URL . '/me',
        ]);

        try {
            $accessToken = $provider->getAccessToken(new ClientCredentials(), ['scope' => self::GRAPH_SCOPE]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to acquire Microsoft Graph app token: ' . $e->getMessage());
            throw new GraphApiException('Could not acquire Microsoft Graph app token: ' . $e->getMessage(), 1718450001, $e);
        }

        $token = $accessToken->getToken();
        // Client credentials tokens always carry an expiry; fall back to a
        // conservative 30 minutes if the provider omits it.
        $expiresAt = $accessToken->getExpires() ?? (time() + 1800);

        $this->registry->set(self::REGISTRY_NAMESPACE, $this->tokenCacheKey($config), [
            'client_id' => $config->clientId,
            'access_token' => $token,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     * @throws GraphApiException
     */
    private function requestJson(string $url, string $token, array $extraHeaders = []): array
    {
        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'headers' => array_merge([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ], $extraHeaders),
            ]);
        } catch (\Throwable $e) {
            throw new GraphApiException('Microsoft Graph request failed for ' . $url . ': ' . $e->getMessage(), 1718450002, $e);
        }

        $decoded = json_decode($response->getBody()->getContents(), true);
        if (!is_array($decoded)) {
            throw new GraphApiException('Unexpected non-JSON response from Microsoft Graph for ' . $url, 1718450003);
        }

        return $decoded;
    }
}
