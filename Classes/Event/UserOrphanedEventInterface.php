<?php

namespace Xima\XimaOauth2Extended\Event;

use Xima\XimaOauth2Extended\Enum\OrphanedUserAction;

/**
 * Common contract for orphaned-user events, dispatched when the Graph sync
 * disables or soft-deletes a TYPO3 user that vanished from Entra.
 *
 * Unlike {@see UserSyncEventInterface} there is no resolver or remote owner:
 * the user no longer exists remotely, so only the local record is available.
 * Fired after the record has been updated.
 */
interface UserOrphanedEventInterface
{
    /**
     * Identity provider key (graphSync client provider) the user was linked to.
     */
    public function getProviderId(): string;

    /**
     * The affected TYPO3 user record (be_users / fe_users row) as it was read
     * before the action was applied, including uid.
     *
     * @return array<string, mixed>
     */
    public function getTypo3User(): array;

    public function getUserId(): int;

    /**
     * The action that was applied ({@see OrphanedUserAction::Disable} or
     * {@see OrphanedUserAction::Delete}).
     */
    public function getAction(): OrphanedUserAction;
}
