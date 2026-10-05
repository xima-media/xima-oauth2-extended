<?php

namespace Xima\XimaOauth2Extended\Event;

/**
 * Dispatched after a new backend user has been created and linked to its remote
 * identity (fresh identity row inserted). Fires both on interactive OAuth2 login
 * and on bulk Graph sync, since both share the BackendUserFactory.
 */
final class BackendUserCreatedEvent extends AbstractUserEvent
{
}
