<?php

namespace Xima\XimaOauth2Extended\Event;

/**
 * Dispatched after an already linked backend user has been updated from its
 * remote identity (profile fields, groups and admin flag re-applied). Fires on
 * bulk Graph sync for existing users.
 */
final class BackendUserUpdatedEvent extends AbstractUserEvent
{
}
