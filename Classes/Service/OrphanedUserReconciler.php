<?php

namespace Xima\XimaOauth2Extended\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use Xima\XimaOauth2Extended\Enum\OrphanedUserAction;
use Xima\XimaOauth2Extended\Enum\UserScope;
use Xima\XimaOauth2Extended\Event\BackendUserOrphanedEvent;
use Xima\XimaOauth2Extended\Event\FrontendUserOrphanedEvent;

/**
 * Reconciles TYPO3 users that are still linked to a graphSync client but no
 * longer present in Entra (the person left the organization), disabling or
 * soft-deleting them according to the client's {@see OrphanedUserAction}.
 *
 * Safety guards (deleting the wrong accounts is far worse than keeping stale
 * ones, so this errs strongly towards inaction):
 *
 * - Runs only for a full client sync, never for the manual single-user import.
 * - Never acts on an empty Graph result — a revoked permission or throttled
 *   request must not look like "everyone left the organization".
 * - Only considers identity links whose identifier is an Entra object id (GUID).
 *   Interactive logins link by the id_token `sub` claim, a different id space
 *   that the app-only `/users` listing never returns; those links are ignored
 *   so logged-in users are never mistaken for departed ones.
 * - Never touches backend admin accounts.
 */
class OrphanedUserReconciler
{
    private const ENTRA_OBJECT_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, true> $seenIdentifiers Entra object ids returned by the sync this run
     */
    public function reconcile(
        UserScope $scope,
        string $provider,
        array $seenIdentifiers,
        OrphanedUserAction $action,
        UserSyncResult $result,
        string $clientKey
    ): void {
        if ($action === OrphanedUserAction::None) {
            return;
        }

        // Safety valve: an empty listing means the fetch failed or returned
        // nothing usable — do not treat that as "the whole directory is gone".
        if ($seenIdentifiers === []) {
            $this->logger->warning(
                'Skipping orphaned user reconciliation for client "' . $clientKey
                . '" (' . $scope->userTable() . '): Graph returned no users.'
            );
            return;
        }

        $orphanUids = $this->findOrphanUids($scope, $provider, $seenIdentifiers);
        if ($orphanUids === []) {
            return;
        }

        foreach ($this->fetchUsers($scope, $orphanUids) as $user) {
            if ($scope->protectsAdmins() && (int)($user['admin'] ?? 0) === 1) {
                continue;
            }

            $column = $action === OrphanedUserAction::Delete ? 'deleted' : 'disable';
            // Already in the target state — nothing to do (idempotent).
            if ((int)($user[$column] ?? 0) === 1) {
                continue;
            }

            $this->applyAction($scope->userTable(), (int)$user['uid'], $column);

            if ($action === OrphanedUserAction::Delete) {
                $result->deleted++;
            } else {
                $result->disabled++;
            }

            $this->eventDispatcher->dispatch($this->createEvent($scope, $provider, $user, $action));
        }
    }

    /**
     * Uids of users linked to the provider by an Entra object id that was not
     * seen this run. `sub`-claim links from interactive logins are skipped.
     *
     * @param array<string, true> $seenIdentifiers
     * @return int[]
     */
    private function findOrphanUids(UserScope $scope, string $provider, array $seenIdentifiers): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($scope->identityTable());
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('identifier', 'parentid')
            ->from($scope->identityTable())
            ->where($qb->expr()->eq('provider', $qb->createNamedParameter($provider)))
            ->executeQuery()
            ->fetchAllAssociative();

        $uids = [];
        foreach ($rows as $row) {
            $identifier = (string)$row['identifier'];
            if (preg_match(self::ENTRA_OBJECT_ID, $identifier) !== 1) {
                continue;
            }
            if (isset($seenIdentifiers[$identifier])) {
                continue;
            }
            $uids[] = (int)$row['parentid'];
        }

        return array_values(array_unique($uids));
    }

    /**
     * @param int[] $uids
     * @return array<int, array<string, mixed>>
     */
    private function fetchUsers(UserScope $scope, array $uids): array
    {
        $qb = $this->getUserQueryBuilder($scope->userTable());

        return $qb->select('*')
            ->from($scope->userTable())
            ->where($qb->expr()->in('uid', $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function applyAction(string $table, int $uid, string $column): void
    {
        $qb = $this->getUserQueryBuilder($table);
        $qb->update($table)
            ->set($column, 1)
            ->set('tstamp', time())
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * @param array<string, mixed> $user
     */
    private function createEvent(UserScope $scope, string $provider, array $user, OrphanedUserAction $action): object
    {
        return match ($scope) {
            UserScope::Backend => new BackendUserOrphanedEvent($provider, $user, $action),
            UserScope::Frontend => new FrontendUserOrphanedEvent($provider, $user, $action),
        };
    }

    /**
     * Query builder without enable-field restrictions: reconciliation must see
     * already-disabled/deleted rows to stay idempotent.
     */
    private function getUserQueryBuilder(string $table): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();

        return $qb;
    }
}
