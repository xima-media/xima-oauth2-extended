<?php

namespace Xima\XimaOauth2Extended\Event;

/**
 * Dispatched after a frontend user that is no longer present in Entra was
 * disabled or soft-deleted by the sync reconciliation.
 */
final class FrontendUserOrphanedEvent extends AbstractUserOrphanedEvent
{
}
