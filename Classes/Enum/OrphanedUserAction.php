<?php

namespace Xima\XimaOauth2Extended\Enum;

/**
 * What the Graph sync does with a TYPO3 user that is still linked to a graphSync
 * client but no longer present in Entra (the person left the organization).
 *
 * Configured per client via the `orphanedUserAction` option. Reconciliation is
 * opt-in: {@see self::None} (the default) keeps the current, non-destructive
 * behaviour.
 */
enum OrphanedUserAction: string
{
    /** Leave orphaned users untouched (default). */
    case None = 'none';

    /** Disable the user (`disable = 1`) — reversible via the backend. */
    case Disable = 'disable';

    /** Soft-delete the user (`deleted = 1`) — recoverable via the recycler. */
    case Delete = 'delete';

    /**
     * Resolves a raw configuration value to an action, falling back to
     * {@see self::None} for empty/unknown values.
     */
    public static function fromConfig(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return self::tryFrom(trim((string)$value)) ?? self::None;
    }
}
