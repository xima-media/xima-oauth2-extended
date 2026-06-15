<?php

namespace Xima\XimaOauth2Extended\ResourceResolver;

use League\OAuth2\Client\Provider\GenericResourceOwner;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use Xima\XimaOauth2Extended\Service\MicrosoftGraphClient;

/**
 * Resolver for the app-only Microsoft Graph user sync.
 *
 * Unlike the login-time resolvers it is not built from a login event but wraps
 * a single record from the Graph `/users` endpoint. The data shape differs from
 * the id_token claims used by {@see MicrosoftResourceResolver} (userPrincipalName,
 * mail, displayName, accountEnabled vs. email, name, picture), which is why the
 * field mapping lives here rather than being reused.
 */
class MicrosoftGraphSyncResolver implements ResourceResolverInterface, UserGroupResolverInterface, ProfileImageResolverInterface
{
    private readonly GenericResourceOwner $remoteUser;

    /**
     * @param array<string, mixed> $graphUser raw record from GET /users
     */
    public function __construct(
        private readonly array $graphUser,
        private readonly string $appAccessToken,
        private readonly ResolverOptions $options,
        private readonly MicrosoftGraphClient $graphClient,
    ) {
        $this->remoteUser = new GenericResourceOwner($graphUser, 'id');
    }

    public function getRemoteUser(): ResourceOwnerInterface
    {
        return $this->remoteUser;
    }

    public function getOptions(): ResolverOptions
    {
        return $this->options;
    }

    public function getIntendedUsername(): ?string
    {
        $username = $this->graphUser['userPrincipalName'] ?? $this->graphUser['mail'] ?? null;

        return $username ? strtolower((string)$username) : null;
    }

    public function getIntendedEmail(): ?string
    {
        $email = $this->graphUser['mail'] ?? $this->graphUser['userPrincipalName'] ?? null;

        return $email ? strtolower((string)$email) : null;
    }

    /**
     * @param array<string, mixed> $beUser
     */
    public function updateBackendUser(array &$beUser): void
    {
        $this->applyCommonFields($beUser);

        if (empty($beUser['realName']) && !empty($this->graphUser['displayName'])) {
            $beUser['realName'] = (string)$this->graphUser['displayName'];
        }
    }

    /**
     * @param array<string, mixed> $feUser
     */
    public function updateFrontendUser(array &$feUser): void
    {
        $this->applyCommonFields($feUser);

        if (empty($feUser['name']) && !empty($this->graphUser['displayName'])) {
            $feUser['name'] = (string)$this->graphUser['displayName'];
        }
    }

    public function resolveUserGroups(): ?array
    {
        return $this->graphClient->getUserGroupIds((string)$this->remoteUser->getId(), $this->appAccessToken);
    }

    public function resolveProfileImage(): ?string
    {
        return $this->graphClient->getUserPhoto((string)$this->remoteUser->getId(), $this->appAccessToken);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function applyCommonFields(array &$user): void
    {
        $username = $this->getIntendedUsername();
        if (empty($user['username']) && $username) {
            $user['username'] = $username;
        }

        $email = $this->getIntendedEmail();
        if ($email) {
            $user['email'] = $email;
        }

        // Mirror the remote account state: disable TYPO3 users whose Graph
        // account is disabled, enable those that are active.
        if (array_key_exists('accountEnabled', $this->graphUser)) {
            $user['disable'] = $this->graphUser['accountEnabled'] ? 0 : 1;
        }
    }
}
