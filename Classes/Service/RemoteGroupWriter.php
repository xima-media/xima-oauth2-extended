<?php

namespace Xima\XimaOauth2Extended\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
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
        $existing = $qb->select('uid', 'title', 'oauth2_id')
            ->from($this->table)
            ->where($qb->expr()->in('oauth2_id', $qb->quoteArrayBasedValueListToStringList($oauthIds)))
            ->executeQuery()
            ->fetchAllAssociative();

        /** @var array<string, array{uid: int, title: string}> $byOauthId */
        $byOauthId = [];
        foreach ($existing as $row) {
            $byOauthId[(string)$row['oauth2_id']] = ['uid' => (int)$row['uid'], 'title' => (string)$row['title']];
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($this->table);

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
                $byOauthId[$group->id] = ['uid' => (int)$connection->lastInsertId(), 'title' => $group->title];
            } elseif ($group->title !== '' && $byOauthId[$group->id]['title'] !== $group->title) {
                $connection->update(
                    $this->table,
                    ['title' => $group->title, 'tstamp' => time()],
                    ['uid' => $byOauthId[$group->id]['uid']]
                );
                $byOauthId[$group->id]['title'] = $group->title;
            }
        }

        // Wire the hierarchy into the subgroup field (child inherits its parents).
        foreach ($groups as $group) {
            $parentUids = [];
            foreach ($group->parentIds as $parentOauthId) {
                if (isset($byOauthId[$parentOauthId])) {
                    $parentUids[] = $byOauthId[$parentOauthId]['uid'];
                }
            }

            $connection->update(
                $this->table,
                ['subgroup' => implode(',', $parentUids)],
                ['uid' => $byOauthId[$group->id]['uid']]
            );
        }

        $map = [];
        foreach ($byOauthId as $oauthId => $data) {
            $map[$oauthId] = $data['uid'];
        }

        return $map;
    }

    private function getQueryBuilder(): QueryBuilder
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($this->table);
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $qb;
    }
}
