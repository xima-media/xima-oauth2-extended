<?php

namespace Xima\XimaOauth2Extended\Event;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use Xima\XimaOauth2Extended\ResourceResolver\ResourceResolverInterface;

/**
 * Common contract for every user-related sync event
 * (backend/frontend, created/updated).
 *
 * Listeners may bind to this interface to react to *any* user change, or to a
 * concrete event class to narrow down to one flow. Events are dispatched after
 * the TYPO3 user record has been persisted, so {@see getTypo3User()} always
 * carries a `uid`.
 */
interface UserSyncEventInterface
{
    /**
     * Identity provider key the user was synced from (e.g. the graphSync client
     * provider key or an `oauth2_client_providers` key on interactive login).
     */
    public function getProviderId(): string;

    /**
     * The persisted TYPO3 user record (be_users / fe_users row), including uid.
     *
     * @return array<string, mixed>
     */
    public function getTypo3User(): array;

    /**
     * Convenience accessor for the persisted user's uid.
     */
    public function getUserId(): int;

    /**
     * The resolver that mapped the remote data onto the TYPO3 user, giving
     * access to the remote owner, intended values and the sync options.
     */
    public function getResolver(): ResourceResolverInterface;

    /**
     * The remote resource owner (Entra/OAuth2 user) behind this sync.
     */
    public function getRemoteUser(): ResourceOwnerInterface;
}
