<?php

namespace Xima\XimaOauth2Extended\Event;

/**
 * Dispatched after an already linked frontend user has been updated from its
 * remote identity (profile fields and group memberships re-applied). Fires on
 * bulk Graph sync for existing users.
 */
final class FrontendUserUpdatedEvent extends AbstractUserEvent
{
}
