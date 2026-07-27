<?php

namespace Xima\XimaOauth2Extended\Enum;

/**
 * Distinguishes the backend and frontend user tables during orphaned-user
 * reconciliation, bundling the table names and per-scope guards so the shared
 * {@see \Xima\XimaOauth2Extended\Service\OrphanedUserReconciler} stays generic.
 */
enum UserScope
{
    case Backend;
    case Frontend;

    /** The TYPO3 user table this scope reconciles. */
    public function userTable(): string
    {
        return $this === self::Backend ? 'be_users' : 'fe_users';
    }

    /** The identity-link table holding the remote identifier => user mapping. */
    public function identityTable(): string
    {
        return $this === self::Backend
            ? 'tx_oauth2_beuser_provider_configuration'
            : 'tx_oauth2_feuser_provider_configuration';
    }

    /** Backend admins are never touched automatically; frontend users have no admin flag. */
    public function protectsAdmins(): bool
    {
        return $this === self::Backend;
    }
}
