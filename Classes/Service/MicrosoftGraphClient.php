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
                'Incomplete graphSync extension configuration (tenantId, clientId, clientSecret and providerId are required).',
                1718450000
            );
        }

        $cached = $this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_TOKEN_KEY);
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

        $select = 'id,userPrincipalName,mail,displayName,givenName,surname,accountEnabled';
        $url = self::GRAPH_BASE_URL . '/users?$select=' . $select . '&$top=999';

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
     * Returns the directory object IDs of the groups the given user is a member
     * of (used for group mapping via the `oauth2_id` column).
     *
     * @return string[]
     * @throws GraphApiException
     */
    public function getUserGroupIds(string $userId, string $token): array
    {
        $url = self::GRAPH_BASE_URL . '/users/' . rawurlencode($userId) . '/memberOf?$select=id';

        $groupIds = [];
        while ($url !== null) {
            $page = $this->requestJson($url, $token);
            foreach ($page['value'] ?? [] as $group) {
                if (!empty($group['id'])) {
                    $groupIds[] = (string)$group['id'];
                }
            }
            $url = $page['@odata.nextLink'] ?? null;
        }

        return $groupIds;
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

        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_TOKEN_KEY, [
            'client_id' => $config->clientId,
            'access_token' => $token,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    /**
     * @return array<string, mixed>
     * @throws GraphApiException
     */
    private function requestJson(string $url, string $token): array
    {
        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
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
