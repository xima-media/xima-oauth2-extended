<?php

namespace Xima\XimaOauth2Extended\Event;

/**
 * Dispatched after a new TYPO3 group has been created from a remote (Entra)
 * group during sync. The group row and its `subgroup` hierarchy are already
 * persisted when this event fires.
 */
final class UserGroupCreatedEvent extends AbstractUserGroupEvent
{
}
