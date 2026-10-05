<?php

namespace Xima\XimaOauth2Extended\Event;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use Xima\XimaOauth2Extended\ResourceResolver\ResourceResolverInterface;

/**
 * Shared implementation for the concrete user sync events. Never dispatched
 * directly; listeners bind either to {@see UserSyncEventInterface} (all user
 * events) or to a concrete subclass.
 */
abstract class AbstractUserEvent implements UserSyncEventInterface
{
    /**
     * @param array<string, mixed> $typo3User
     */
    public function __construct(
        protected readonly string $providerId,
        protected readonly array $typo3User,
        protected readonly ResourceResolverInterface $resolver,
    ) {
    }

    public function getProviderId(): string
    {
        return $this->providerId;
    }

    public function getTypo3User(): array
    {
        return $this->typo3User;
    }

    public function getUserId(): int
    {
        return (int)($this->typo3User['uid'] ?? 0);
    }

    public function getResolver(): ResourceResolverInterface
    {
        return $this->resolver;
    }

    public function getRemoteUser(): ResourceOwnerInterface
    {
        return $this->resolver->getRemoteUser();
    }
}
