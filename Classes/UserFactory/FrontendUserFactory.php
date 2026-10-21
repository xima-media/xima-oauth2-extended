<?php

namespace Xima\XimaOauth2Extended\UserFactory;

use Doctrine\DBAL\Driver\Exception;
use JetBrains\PhpStorm\ArrayShape;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Waldhacker\Oauth2Client\Database\Query\Restriction\Oauth2BeUserProviderConfigurationRestriction;
use Waldhacker\Oauth2Client\Database\Query\Restriction\Oauth2FeUserProviderConfigurationRestriction;
use Xima\XimaOauth2Extended\Event\FrontendUserCreatedEvent;
use Xima\XimaOauth2Extended\Event\FrontendUserUpdatedEvent;
use Xima\XimaOauth2Extended\ResourceResolver\ResourceResolverInterface;
use Xima\XimaOauth2Extended\ResourceResolver\UserGroupDetailsResolverInterface;
use Xima\XimaOauth2Extended\ResourceResolver\UserGroupResolverInterface;
use Xima\XimaOauth2Extended\Service\RemoteGroupWriter;

class FrontendUserFactory
{
    protected ResourceResolverInterface $resolver;

    protected string $providerId = '';

    protected array $extendedProviderConfiguration = [];

    /** @var string[]|null */
    protected ?array $remoteGroupIds = null;

    public function __construct(
        ResourceResolverInterface $resolver,
        string $providerId,
        array $extendedProviderConfiguration
    ) {
        $this->resolver = $resolver;
        $this->providerId = $providerId;
        $this->extendedProviderConfiguration = $extendedProviderConfiguration;
    }

    protected function findUserByUsernameOrEmail(): ?array
    {
        $constraints = [];
        $username = $this->resolver->getIntendedUsername();
        $email = $this->resolver->getIntendedEmail();
        $qb = $this->getQueryBuilder('fe_users');

        if ($username) {
            $constraints[] = $qb->expr()->eq(
                'username',
                $qb->createNamedParameter($username, Connection::PARAM_STR)
            );
        }

        if ($email) {
            $constraints[] = $qb->expr()->eq(
                'email',
                $qb->createNamedParameter($email, Connection::PARAM_STR)
            );
        }

        if (empty($constraints)) {
            return null;
        }

        $user = $qb
            ->select('*')
            ->from('fe_users')
            ->where($qb->expr()->or(...$constraints))
            ->executeQuery()
            ->fetchAssociative();

        return $user ?: null;
    }

    /**
     * Updates an existing, already linked frontend user without inserting a new
     * identity row. Counterpart to {@see registerRemoteUser()} used by the bulk
     * Graph sync when an identity link already exists.
     *
     * @param array<string, mixed> $typo3User
     * @return array<string, mixed>
     */
    public function updateTypo3User(array $typo3User, int $targetPid = 0): array
    {
        $this->resolver->updateFrontendUser($typo3User);
        $this->createFrontendUserGroups($targetPid);
        $this->updateFrontendUserGroups($typo3User);
        $this->saveUpdatedFrontendUser($typo3User);

        GeneralUtility::makeInstance(EventDispatcherInterface::class)->dispatch(
            new FrontendUserUpdatedEvent($this->providerId, $typo3User, $this->resolver)
        );

        return $typo3User;
    }

    public function registerRemoteUser(int $targetPid): ?array
    {
        $doCreateNewUser = isset($this->extendedProviderConfiguration[$this->providerId]['createFrontendUser']) && $this->extendedProviderConfiguration[$this->providerId]['createFrontendUser'];

        // find or optionally create
        $userRecord = $this->findUserByUsernameOrEmail();
        if (!is_array($userRecord)) {
            if ($doCreateNewUser) {
                $userRecord = $this->createBasicFrontendUser($targetPid);
            } else {
                return null;
            }
        }

        // update
        $this->resolver->updateFrontendUser($userRecord);

        // test for username
        if (!$userRecord['username']) {
            return null;
        }

        // test for persistence
        if (!isset($userRecord['uid'])) {
            $userRecord = $this->persistAndRetrieveUser($userRecord);
        }

        // abort if persistence failed
        if (!is_array($userRecord)) {
            return null;
        }

        // create user groups
        $this->createFrontendUserGroups($targetPid);

        // update user groups
        $this->updateFrontendUserGroups($userRecord);

        // save updated user
        $this->saveUpdatedFrontendUser($userRecord);

        // update user slug
        $this->updateFrontendUserSlug($userRecord);

        try {
            if ($this->persistIdentityForUser($userRecord)) {
                GeneralUtility::makeInstance(EventDispatcherInterface::class)->dispatch(
                    new FrontendUserCreatedEvent($this->providerId, $userRecord, $this->resolver)
                );

                return $userRecord;
            }
        } catch (Exception $e) {
        }

        return null;
    }

    /** @return string[]|null */
    protected function getRemoteGroupIdsCached(): ?array
    {
        if (!$this->resolver instanceof UserGroupResolverInterface) {
            return null;
        }
        if ($this->remoteGroupIds === null) {
            $this->remoteGroupIds = $this->resolver->resolveUserGroups();
        }

        return $this->remoteGroupIds;
    }

    protected function createFrontendUserGroups(int $targetPid = 0): void
    {
        if (!$this->resolver->getOptions()->createFrontendUsergroups || !$this->resolver instanceof UserGroupResolverInterface) {
            return;
        }

        // Rich path: create groups with their real names and reconstruct the
        // Entra hierarchy into the subgroup field.
        if ($this->resolver instanceof UserGroupDetailsResolverInterface) {
            $details = $this->resolver->resolveUserGroupDetails();
            if (!empty($details)) {
                (new RemoteGroupWriter('fe_groups'))->persist($details, $targetPid);
            }
            return;
        }

        $groupIds = $this->getRemoteGroupIdsCached();
        if ($groupIds === null || !count($groupIds)) {
            return;
        }

        $qb = $this->getQueryBuilder('fe_groups');
        $existingGroupsResult = $qb->select('oauth2_id')
            ->from('fe_groups')
            ->where($qb->expr()->in('oauth2_id', $qb->quoteArrayBasedValueListToStringList($groupIds)))
            ->executeQuery()
            ->fetchAllAssociative();

        $groupIdsToCreate = array_diff($groupIds, array_column($existingGroupsResult, 'oauth2_id'));
        if (!count($groupIdsToCreate)) {
            return;
        }

        $insertValues = array_map(static function ($oauthId) use ($targetPid) {
            return [$targetPid, time(), time(), $oauthId, $oauthId];
        }, $groupIdsToCreate);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('fe_groups');
        $connection->bulkInsert(
            'fe_groups',
            $insertValues,
            ['pid', 'crdate', 'tstamp', 'title', 'oauth2_id'],
            [Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_STR]
        );
    }

    /**
     * @param array<string, mixed> $typo3User
     */
    protected function updateFrontendUserGroups(array &$typo3User): void
    {
        $groupIds = $this->getRemoteGroupIdsCached();

        if ($groupIds === null) {
            return;
        }

        $qb = $this->getQueryBuilder('fe_groups');
        $groupIdResults = $qb->select('g.uid')
            ->distinct()
            ->from('fe_groups', 'g')
            ->leftJoin('g', 'fe_users', 'u', $qb->expr()->inSet('u.usergroup', $qb->quoteIdentifier('g.uid')))
            ->where(
                $qb->expr()->or(
                    $qb->expr()->in('g.oauth2_id', $qb->quoteArrayBasedValueListToStringList($groupIds)),
                    $qb->expr()->and(
                        $qb->expr()->eq('g.oauth2_id', $qb->createNamedParameter('')),
                        $qb->expr()->eq('u.uid', $qb->createNamedParameter($typo3User['uid'], Connection::PARAM_INT))
                    )
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $groupIds = array_map(static function ($groupResult) {
            return $groupResult['uid'];
        }, $groupIdResults);

        $typo3User['usergroup'] = implode(',', $groupIds);
    }

    /**
     * @param array<string, mixed> $typo3User
     */
    protected function saveUpdatedFrontendUser(array $typo3User): void
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('fe_users');
        foreach ($typo3User as $fieldName => $value) {
            $qb->set($fieldName, $value);
        }
        $qb->update('fe_users')
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($typo3User['uid'], Connection::PARAM_INT))
            )
            ->executeStatement();
    }

    protected function updateFrontendUserSlug(&$userRecord): void
    {
        // abort in case no slug field is defined
        if (!isset($GLOBALS['TCA']['fe_users']['columns']['slug'])) {
            return;
        }

        // init SlugHelper for this table
        $fieldConfig = $GLOBALS['TCA']['fe_users']['columns']['slug']['config'];
        /** @var SlugHelper $slugHelper */
        $slugHelper = GeneralUtility::makeInstance(
            SlugHelper::class,
            'fe_users',
            'slug',
            $fieldConfig
        );

        // generate unique slug for user
        $value = $slugHelper->generate($userRecord, $userRecord['pid']);
        $state = RecordStateFactory::forName('fe_users')
            ->fromArray($userRecord, $userRecord['pid'], $userRecord['uid']);
        $slug = $slugHelper->buildSlugForUniqueInPid($value, $state);

        // update slug field of user
        $qb = $this->getQueryBuilder('fe_users');
        $qb->update('fe_users')
            ->where(
                $qb->expr()->eq(
                    'uid',
                    $qb->createNamedParameter($userRecord['uid'], Connection::PARAM_INT)
                )
            )
            ->set('slug', $slug)
            ->executeStatement();
    }

    /**
     * @throws Exception
     */
    public function persistIdentityForUser($userRecord): bool
    {
        // create identity
        $qb = $this->getQueryBuilder('tx_oauth2_feuser_provider_configuration');
        $qb->insert('tx_oauth2_feuser_provider_configuration')
            ->values([
                'identifier' => $this->resolver->getRemoteUser()->getId(),
                'provider' => $this->providerId,
                'crdate' => time(),
                'tstamp' => time(),
                'parentid' => (int)$userRecord['uid'],
            ])
            ->executeStatement();

        // get newly created identity
        $qb = $this->getQueryBuilder('tx_oauth2_feuser_provider_configuration');
        $qb->getRestrictions()->removeByType(Oauth2BeUserProviderConfigurationRestriction::class);
        $qb->getRestrictions()->removeByType(Oauth2FeUserProviderConfigurationRestriction::class);
        $identityCount = $qb->count('uid')
            ->from('tx_oauth2_feuser_provider_configuration')
            ->where($qb->expr()->eq('parentid', (int)$userRecord['uid']))
            ->executeQuery()
            ->fetchOne();

        if ((!$identityCount) > 0) {
            return false;
        }

        // update frontend user
        $qb = $this->getQueryBuilder('fe_users');
        $qb->update('fe_users')
            ->where(
                $qb->expr()->eq('uid', (int)$userRecord['uid'])
            )
            ->set('tx_oauth2_client_configs', (int)$identityCount)
            ->executeStatement();

        return true;
    }

    /**
     * @throws Exception
     */
    public function persistAndRetrieveUser($userRecord): ?array
    {
        $password = $userRecord['password'];

        $this->getQueryBuilder('fe_users')->insert('fe_users')
            ->values($userRecord)
            ->executeStatement();

        $qb = $this->getQueryBuilder('fe_users');
        return $qb->select('*')
            ->from('fe_users')
            ->where(
                $qb->expr()->eq('password', $qb->createNamedParameter($password, Connection::PARAM_STR))
            )
            ->executeQuery()
            ->fetchAssociative();
    }

    /**
     * @throws InvalidPasswordHashException
     */
    /**
     * @throws InvalidPasswordHashException
     */
    #[ArrayShape([
        'pid' => 'int',
        'username' => 'string',
        'realName' => 'string',
        'disable' => 'int',
        'crdate' => 'int',
        'tstamp' => 'int',
        'admin' => 'int',
        'starttime' => 'int',
        'endtime' => 'int',
        'password' => 'string',
        'name' => 'string',
        'usergroup' => 'string',
    ])] public function createBasicFrontendUser(int $targetPid): array
    {
        $saltingInstance = GeneralUtility::makeInstance(PasswordHashFactory::class)->getDefaultHashInstance('FE');
        $defaultUserGroup = $this->extendedProviderConfiguration[$this->providerId]['defaultFrontendUsergroup'] ?? '';

        return [
            'pid' => $targetPid,
            'crdate' => time(),
            'tstamp' => time(),
            'disable' => 1,
            'starttime' => 0,
            'endtime' => 0,
            'password' => $saltingInstance->getHashedPassword(md5(uniqid())),
            'name' => '',
            'username' => '',
            'usergroup' => $defaultUserGroup,
        ];
    }

    protected function getQueryBuilder(string $tableName): QueryBuilder
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($tableName);
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $qb;
    }
}
