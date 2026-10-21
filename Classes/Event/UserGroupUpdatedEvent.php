<?php

namespace Xima\XimaOauth2Extended\Event;

use Xima\XimaOauth2Extended\ResourceResolver\RemoteGroup;

/**
 * Dispatched after an existing TYPO3 group synced from a remote (Entra) group
 * was refreshed during sync (display name changed and/or the `subgroup`
 * hierarchy was rewired).
 */
final class UserGroupUpdatedEvent extends AbstractUserGroupEvent
{
    public function __construct(
        string $table,
        int $groupUid,
        RemoteGroup $remoteGroup,
        protected readonly string $previousTitle,
    ) {
        parent::__construct($table, $groupUid, $remoteGroup);
    }

    /**
     * The TYPO3 group title before this sync refreshed it.
     */
    public function getPreviousTitle(): string
    {
        return $this->previousTitle;
    }
}
