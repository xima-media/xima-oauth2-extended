<?php

namespace Xima\XimaOauth2Extended\UserFactory;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Waldhacker\Oauth2Client\Database\Query\Restriction\Oauth2BeUserProviderConfigurationRestriction;
use Waldhacker\Oauth2Client\Database\Query\Restriction\Oauth2FeUserProviderConfigurationRestriction;
use Xima\XimaOauth2Extended\Exception\UserLinkingDeniedException;
use Xima\XimaOauth2Extended\ResourceResolver\ResourceResolverInterface;
use Xima\XimaOauth2Extended\ResourceResolver\UserGroupResolverInterface;

abstract class AbstractUserFactory
{
    /** @var string[]|null */
    protected ?array $remoteGroupIds = null;

    public function __construct(
        protected ResourceResolverInterface $resolver,
        protected string $providerId,
        protected LoggerInterface $logger,
        protected bool $linkExistingUsers = true
    ) {
    }

    /** @return string[]|null */
    public function getRemoteGroupIdsCached(): ?array
    {
        if (!$this->resolver instanceof UserGroupResolverInterface) {
            return null;
        }
        if ($this->remoteGroupIds === null) {
            $this->remoteGroupIds = $this->resolver->resolveUserGroups();
        }
        return $this->remoteGroupIds;
    }

    /**
     * Returns the user the remote identity belongs to, or null if a new user may be created.
     *
     * @return array<string, mixed>|null
     * @throws UserLinkingDeniedException
     */
    protected function findExistingUser(string $userTable, string $identityTable, ?int $storagePid = null): ?array
    {
        // The core lookup found no user, so a linked user here is disabled or linked more than once
        $linkedUsers = $this->findUsersLinkedToIdentity($userTable, $identityTable, $storagePid);
        if ($linkedUsers !== []) {
            if (count($linkedUsers) > 1 || !self::isEnabled($linkedUsers[0])) {
                throw new UserLinkingDeniedException('the identity is linked to a disabled user or to more than one user', 1795366001);
            }
            return $linkedUsers[0];
        }

        $matchingUsers = $this->findUsersByUsernameOrEmail($userTable, $storagePid);
        if ($matchingUsers === []) {
            return null;
        }
        if (count($matchingUsers) > 1) {
            throw new UserLinkingDeniedException('more than one user matches the username or email', 1795366002);
        }
        $this->assertUserMayBeLinked($matchingUsers[0]);

        return $matchingUsers[0];
    }

    /**
     * @param array<string, mixed> $user
     * @throws UserLinkingDeniedException
     */
    protected function assertUserMayBeLinked(array $user): void
    {
        if (!$this->linkExistingUsers) {
            throw new UserLinkingDeniedException('linking existing users is disabled', 1795366003);
        }
        if (!self::isEnabled($user)) {
            throw new UserLinkingDeniedException('the matching user is disabled', 1795366004);
        }
        $claims = $this->resolver->getRemoteUser()->toArray();
        if (array_key_exists('email_verified', $claims) && !filter_var($claims['email_verified'], FILTER_VALIDATE_BOOLEAN)) {
            throw new UserLinkingDeniedException('the email address is not verified', 1795366005);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    protected static function isEnabled(array $user): bool
    {
        $now = time();
        $starttime = (int)($user['starttime'] ?? 0);
        $endtime = (int)($user['endtime'] ?? 0);

        return !($user['disable'] ?? false)
            && $starttime <= $now
            && ($endtime === 0 || $endtime > $now);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function findUsersLinkedToIdentity(string $userTable, string $identityTable, ?int $storagePid): array
    {
        $qb = $this->getQueryBuilder($userTable);
        $qb->select('u.*')
            ->distinct()
            ->from($identityTable, 'i')
            ->join('i', $userTable, 'u', $qb->expr()->eq('i.parentid', $qb->quoteIdentifier('u.uid')))
            ->where(
                $qb->expr()->eq('i.provider', $qb->createNamedParameter($this->providerId)),
                $qb->expr()->eq('i.identifier', $qb->createNamedParameter((string)$this->resolver->getRemoteUser()->getId()))
            );
        if ($storagePid !== null) {
            $qb->andWhere($qb->expr()->eq('u.pid', $qb->createNamedParameter($storagePid, Connection::PARAM_INT)));
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function findUsersByUsernameOrEmail(string $userTable, ?int $storagePid): array
    {
        $constraints = [];
        $username = $this->resolver->getIntendedUsername();
        $email = $this->resolver->getIntendedEmail();
        $qb = $this->getQueryBuilder($userTable);

        if ($username) {
            $constraints[] = $qb->expr()->eq('username', $qb->createNamedParameter($username));
        }
        if ($email) {
            $constraints[] = $qb->expr()->eq('email', $qb->createNamedParameter($email));
        }
        if ($constraints === []) {
            return [];
        }

        $qb->select('*')
            ->from($userTable)
            ->where($qb->expr()->or(...$constraints));
        if ($storagePid !== null) {
            $qb->andWhere($qb->expr()->eq('pid', $qb->createNamedParameter($storagePid, Connection::PARAM_INT)));
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Links the remote identity to the user exactly once, removing duplicate links.
     */
    protected function persistIdentity(string $userTable, string $identityTable, int $userUid): bool
    {
        $identifier = (string)$this->resolver->getRemoteUser()->getId();
        $qb = $this->getQueryBuilder($identityTable);
        $identityUids = $qb->select('uid')
            ->from($identityTable)
            ->where(
                $qb->expr()->eq('provider', $qb->createNamedParameter($this->providerId)),
                $qb->expr()->eq('identifier', $qb->createNamedParameter($identifier)),
                $qb->expr()->eq('parentid', $qb->createNamedParameter($userUid, Connection::PARAM_INT))
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchFirstColumn();

        if ($identityUids === []) {
            $this->getQueryBuilder($identityTable)->insert($identityTable)
                ->values([
                    'identifier' => $identifier,
                    'provider' => $this->providerId,
                    'crdate' => time(),
                    'tstamp' => time(),
                    'parentid' => $userUid,
                ])
                ->executeStatement();
        } elseif (count($identityUids) > 1) {
            $qb = $this->getQueryBuilder($identityTable);
            $qb->delete($identityTable)
                ->where($qb->expr()->in(
                    'uid',
                    $qb->createNamedParameter(array_map('intval', array_slice($identityUids, 1)), Connection::PARAM_INT_ARRAY)
                ))
                ->executeStatement();
        }

        $qb = $this->getQueryBuilder($identityTable);
        $identityCount = (int)$qb->count('uid')
            ->from($identityTable)
            ->where($qb->expr()->eq('parentid', $qb->createNamedParameter($userUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
        if ($identityCount === 0) {
            return false;
        }

        $qb = $this->getQueryBuilder($userTable);
        $qb->update($userTable)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($userUid, Connection::PARAM_INT)))
            ->set('tx_oauth2_client_configs', $identityCount)
            ->executeStatement();

        return true;
    }

    protected function logDeniedLogin(UserLinkingDeniedException $exception): void
    {
        $this->logger->warning(sprintf(
            'OAuth2 login of remote user "%s" from provider "%s" denied: %s',
            $this->resolver->getRemoteUser()->getId(),
            $this->providerId,
            $exception->getMessage()
        ));
    }

    protected function getQueryBuilder(string $tableName): QueryBuilder
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($tableName);
        // The identity restrictions of oauth2_client are enforced and survive removeAll()
        $qb->getRestrictions()
            ->removeAll()
            ->removeByType(Oauth2BeUserProviderConfigurationRestriction::class)
            ->removeByType(Oauth2FeUserProviderConfigurationRestriction::class)
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $qb;
    }
}
