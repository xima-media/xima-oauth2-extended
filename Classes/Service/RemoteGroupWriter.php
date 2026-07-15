<?php

namespace Xima\XimaOauth2Extended\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Xima\XimaOauth2Extended\Event\UserGroupCreatedEvent;
use Xima\XimaOauth2Extended\Event\UserGroupUpdatedEvent;
use Xima\XimaOauth2Extended\ResourceResolver\RemoteGroup;

/**
 * Upserts remote (Entra) groups into a TYPO3 group table (`be_groups` /
 * `fe_groups`), matched by the `oauth2_id` column:
 *
 * - creates missing groups with their Entra display name as title,
 * - refreshes the title when the Entra display name changed,
 * - reconstructs the Entra nesting into the TYPO3 `subgroup` field (a child
 *   group lists its parents as subgroups, so members inherit the parents).
 *
 * Only groups managed by the sync (those carrying an `oauth2_id`) are touched.
 */
class RemoteGroupWriter
{
    public function __construct(private readonly string $table)
    {
    }

    /**
     * @param RemoteGroup[] $groups
     * @return array<string, int> map of oauth2_id => TYPO3 group uid
     */
    public function persist(array $groups, int $pid): array
    {
        if ($groups === []) {
            return [];
        }

        $oauthIds = array_map(static fn (RemoteGroup $group) => $group->id, $groups);

        $qb = $this->getQueryBuilder();
        $existing = $qb->select('uid', 'title', 'oauth2_id', 'subgroup')
            ->from($this->table)
            ->where($qb->expr()->in('oauth2_id', $qb->quoteArrayBasedValueListToStringList($oauthIds)))
            ->executeQuery()
            ->fetchAllAssociative();

        /** @var array<string, array{uid: int, title: string, subgroup: string}> $byOauthId */
        $byOauthId = [];
        foreach ($existing as $row) {
            $byOauthId[(string)$row['oauth2_id']] = [
                'uid' => (int)$row['uid'],
                'title' => (string)$row['title'],
                'subgroup' => (string)$row['subgroup'],
            ];
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);

        // Track what actually changed so we only emit meaningful events.
        /** @var array<string, true> $created oauth2_id set of newly inserted groups */
        $created = [];
        /** @var array<string, string> $previousTitles oauth2_id => title before refresh */
        $previousTitles = [];

        // Create missing groups and refresh changed display names.
        foreach ($groups as $group) {
            if (!isset($byOauthId[$group->id])) {
                $connection->insert($this->table, [
                    'pid' => $pid,
                    'crdate' => time(),
                    'tstamp' => time(),
                    'title' => $group->title,
                    'oauth2_id' => $group->id,
                ]);
                $byOauthId[$group->id] = ['uid' => (int)$connection->lastInsertId(), 'title' => $group->title, 'subgroup' => ''];
                $created[$group->id] = true;
            } elseif ($group->title !== '' && $byOauthId[$group->id]['title'] !== $group->title) {
                $previousTitles[$group->id] = $byOauthId[$group->id]['title'];
                $connection->update(
                    $this->table,
                    ['title' => $group->title, 'tstamp' => time()],
                    ['uid' => $byOauthId[$group->id]['uid']]
                );
                $byOauthId[$group->id]['title'] = $group->title;
            }
        }

        // Wire the hierarchy into the subgroup field (child inherits its parents).
        /** @var array<string, true> $subgroupChanged oauth2_id set of groups whose subgroup was rewritten */
        $subgroupChanged = [];
        foreach ($groups as $group) {
            $parentUids = [];
            foreach ($group->parentIds as $parentOauthId) {
                if (isset($byOauthId[$parentOauthId])) {
                    $parentUids[] = $byOauthId[$parentOauthId]['uid'];
                }
            }

            $subgroup = implode(',', $parentUids);
            if ($byOauthId[$group->id]['subgroup'] === $subgroup) {
                continue;
            }

            $connection->update(
                $this->table,
                ['subgroup' => $subgroup],
                ['uid' => $byOauthId[$group->id]['uid']]
            );
            $byOauthId[$group->id]['subgroup'] = $subgroup;
            $subgroupChanged[$group->id] = true;
        }

        $this->dispatchGroupEvents($groups, $byOauthId, $created, $previousTitles, $subgroupChanged);

        $map = [];
        foreach ($byOauthId as $oauthId => $data) {
            $map[$oauthId] = $data['uid'];
        }

        return $map;
    }

    /**
     * Emits a created event per newly inserted group and an updated event per
     * existing group whose title or hierarchy actually changed.
     *
     * @param RemoteGroup[] $groups
     * @param array<string, array{uid: int, title: string, subgroup: string}> $byOauthId
     * @param array<string, true> $created
     * @param array<string, string> $previousTitles
     * @param array<string, true> $subgroupChanged
     */
    private function dispatchGroupEvents(
        array $groups,
        array $byOauthId,
        array $created,
        array $previousTitles,
        array $subgroupChanged
    ): void {
        $dispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);

        foreach ($groups as $group) {
            $uid = $byOauthId[$group->id]['uid'];

            if (isset($created[$group->id])) {
                $dispatcher->dispatch(new UserGroupCreatedEvent($this->table, $uid, $group));
                continue;
            }

            if (isset($previousTitles[$group->id]) || isset($subgroupChanged[$group->id])) {
                $previousTitle = $previousTitles[$group->id] ?? $byOauthId[$group->id]['title'];
                $dispatcher->dispatch(new UserGroupUpdatedEvent($this->table, $uid, $group, $previousTitle));
            }
        }
    }

    private function getQueryBuilder(): QueryBuilder
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($this->table);
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $qb;
    }
}
