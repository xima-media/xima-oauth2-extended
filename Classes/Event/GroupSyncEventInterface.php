<?php

namespace Xima\XimaOauth2Extended\Event;

use Xima\XimaOauth2Extended\ResourceResolver\RemoteGroup;

/**
 * Common contract for remote (Entra) group sync events (created/updated).
 *
 * Dispatched by {@see \Xima\XimaOauth2Extended\Service\RemoteGroupWriter} while
 * upserting groups into a TYPO3 group table, after the row (and its `subgroup`
 * hierarchy) has been persisted. Listeners may bind to this interface for any
 * group change or to a concrete event class.
 */
interface GroupSyncEventInterface
{
    /**
     * The affected TYPO3 group table: `be_groups` or `fe_groups`.
     */
    public function getTable(): string;

    /**
     * The persisted TYPO3 group uid.
     */
    public function getGroupUid(): int;

    /**
     * The remote (Entra) group the TYPO3 group was synced from.
     */
    public function getRemoteGroup(): RemoteGroup;

    /**
     * Convenience accessor for the remote directory object id
     * (stored in the `oauth2_id` column).
     */
    public function getOauth2Id(): string;
}
