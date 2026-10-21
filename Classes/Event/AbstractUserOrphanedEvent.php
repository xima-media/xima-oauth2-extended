<?php

namespace Xima\XimaOauth2Extended\Event;

use Xima\XimaOauth2Extended\Enum\OrphanedUserAction;

/**
 * Shared implementation for the concrete orphaned-user events. Never dispatched
 * directly; listeners bind either to {@see UserOrphanedEventInterface} (any
 * orphaned user) or to a concrete subclass.
 */
abstract class AbstractUserOrphanedEvent implements UserOrphanedEventInterface
{
    /**
     * @param array<string, mixed> $typo3User
     */
    public function __construct(
        protected readonly string $providerId,
        protected readonly array $typo3User,
        protected readonly OrphanedUserAction $action,
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

    public function getAction(): OrphanedUserAction
    {
        return $this->action;
    }
}
