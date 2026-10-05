<?php

namespace Xima\XimaOauth2Extended\Event;

use Xima\XimaOauth2Extended\ResourceResolver\RemoteGroup;

/**
 * Shared implementation for the concrete group sync events. Never dispatched
 * directly; listeners bind either to {@see GroupSyncEventInterface} (all group
 * events) or to a concrete subclass.
 */
abstract class AbstractUserGroupEvent implements GroupSyncEventInterface
{
    public function __construct(
        protected readonly string $table,
        protected readonly int $groupUid,
        protected readonly RemoteGroup $remoteGroup,
    ) {
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getGroupUid(): int
    {
        return $this->groupUid;
    }

    public function getRemoteGroup(): RemoteGroup
    {
        return $this->remoteGroup;
    }

    public function getOauth2Id(): string
    {
        return $this->remoteGroup->id;
    }
}
