<?php

namespace Xima\XimaOauth2Extended\UserFactory;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Exception;
use JetBrains\PhpStorm\ArrayShape;
use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Waldhacker\Oauth2Client\Database\Query\Restriction\Oauth2BeUserProviderConfigurationRestriction;
use Xima\XimaOauth2Extended\ResourceResolver\ProfileImageResolverInterface;
use Xima\XimaOauth2Extended\ResourceResolver\UserGroupResolverInterface;

class BackendUserFactory extends AbstractUserFactory
{

    public function updateTypo3User(array $typo3User): array
    {
        $this->resolver->updateBackendUser($typo3User);
        $this->updateProfileImage($typo3User);
        $this->updateUserGroups($typo3User);
        $this->saveUpdatedTypo3User($typo3User);

        return $typo3User;
    }

    private function updateProfileImage(array &$userRecord): void
    {
        if (!($this->resolver instanceof ProfileImageResolverInterface)) {
            return;
        }

        $qb = $this->getQueryBuilder('sys_file_reference');
        $result = $qb->select('*')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($userRecord['uid'], \PDO::PARAM_INT)),
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('be_users')),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter('avatar'))
            )
            ->execute()
            ->fetchOne();
        if ($result) {
            return;
        }

        $imageUtility = new ImageUserFactory($this->resolver, $this->resolverOptions->imageStorageBackendIdentifier);
        $success = $imageUtility->addProfileImageForBackendUser($userRecord['uid']);
        if ($success) {
            $userRecord['avatar'] = 1;
        }
    }

    protected function getQueryBuilder(string $tableName): QueryBuilder
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($tableName);
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $qb;
    }

    private function updateUserGroups(array &$typo3User): void
    {
        $groupIds = $this->getRemoteGroupIdsCached();

        $qb = $this->getQueryBuilder('be_groups');
        $groupIdResults = $qb->select('g.uid')
            ->distinct()
            ->from('be_groups', 'g')
            ->leftJoin('g', 'be_users', 'u', $qb->expr()->inSet('g.uid', $qb->quoteIdentifier('u.usergroup')))
            ->where(
                $qb->expr()->or(
                    $qb->expr()->in('g.oauth2_id', $qb->quoteArrayBasedValueListToStringList($groupIds)),
                    $qb->expr()->and(
                        $qb->expr()->eq('g.oauth2_id', $qb->createNamedParameter('')),
                        $qb->expr()->eq('u.uid', $qb->createNamedParameter($typo3User['uid'], \PDO::PARAM_INT))
                    )
                )
            )
            ->execute()
            ->fetchAllAssociative();

        $groupIds = array_map(function ($groupResult) {
            return $groupResult['uid'];
        }, $groupIdResults);
        $groupIdsString = implode(',', $groupIds);

        $typo3User['usergroup'] = $groupIdsString;
    }

    private function saveUpdatedTypo3User(array $typo3User): void
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('be_users');
        foreach ($typo3User as $fieldName => $value) {
            $qb->set($fieldName, $value);
        }
        $qb->update('be_users')
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($typo3User['uid'], \PDO::PARAM_INT))
            )
            ->executeStatement();
    }

    public function registerRemoteUser(): ?array
    {
        $userRecord = $this->findUserByUsernameOrEmail();

        $userToLinkNotFound = !is_array($userRecord);
        $doCreateNewUser = $this->resolverOptions->createBackendUser;
        $groupRestrictionIsMet = !$this->resolverOptions->requireBackendUsergroup || $this->checkBackendGroupRestriction();

        // check if user should be created
        if ($userToLinkNotFound) {
            if ($doCreateNewUser && $groupRestrictionIsMet) {
                $userRecord = $this->createBasicBackendUser();
            } else {
                return null;
            }
        }

        // update
        $this->resolver->updateBackendUser($userRecord);

        // test for username
        if (!$userRecord['username']) {
            return null;
        }

        // test for persistence
        if (!isset($userRecord['uid'])) {
            $userRecord = $this->persistAndRetrieveUser($userRecord);
        }

        // download profile picture
        $this->updateProfileImage($userRecord);

        // update user groups
        $this->updateUserGroups($userRecord);

        // save updated user
        $this->saveUpdatedTypo3User($userRecord);

        try {
            if ($this->persistIdentityForUser($userRecord)) {
                return $userRecord;
            }
        } catch (Exception) {
        }

        return null;
    }

    protected function findUserByUsernameOrEmail(): ?array
    {
        $constraints = [];
        $username = $this->resolver->getIntendedUsername();
        $email = $this->resolver->getIntendedEmail();
        $qb = $this->getQueryBuilder('be_users');

        if ($username) {
            $constraints[] = $qb->expr()->eq(
                'username',
                $qb->createNamedParameter($username)
            );
        }

        if ($email) {
            $constraints[] = $qb->expr()->eq(
                'email',
                $qb->createNamedParameter($email)
            );
        }

        if (empty($constraints)) {
            return null;
        }

        $user = $qb
            ->select('*')
            ->from('be_users')
            ->where($qb->expr()->orX(...$constraints))
            ->execute()
            ->fetchAssociative();

        return $user ?: null;
    }

    protected function checkBackendGroupRestriction(): bool
    {
        $groupIds = $this->getRemoteGroupIdsCached();

        $qb = $this->getQueryBuilder('be_groups');
        $existingGroups = $qb->count('uid')
            ->from('be_groups')
            ->where($qb->expr()->in('oauth2_id', $qb->quoteArrayBasedValueListToStringList($groupIds)))
            ->execute()
            ->fetchOne();

        return (bool)$existingGroups;
    }

    /**
     * @throws InvalidPasswordHashException
     */
    #[ArrayShape([
        'username' => 'string',
        'realName' => 'string',
        'disable' => 'int',
        'crdate' => 'int',
        'tstamp' => 'int',
        'admin' => 'int',
        'starttime' => 'int',
        'endtime' => 'int',
        'password' => 'string',
        'usergroup' => 'string',
    ])] public function createBasicBackendUser(): array
    {
        $saltingInstance = GeneralUtility::makeInstance(PasswordHashFactory::class)->getDefaultHashInstance('BE');

        return [
            'crdate' => time(),
            'tstamp' => time(),
            'admin' => 0,
            'disable' => 1,
            'starttime' => 0,
            'endtime' => 0,
            'password' => $saltingInstance->getHashedPassword(md5(uniqid('', true))),
            'realName' => '',
            'username' => '',
            'usergroup' => $this->resolverOptions->defaultBackendUsergroup,
        ];
    }

    /**
     * @throws DBALException
     * @throws Exception
     */
    public function persistAndRetrieveUser($userRecord): ?array
    {
        $password = $userRecord['password'];

        $user = $this->getQueryBuilder('be_users')->insert('be_users')
            ->values($userRecord)
            ->execute();

        if (!$user) {
            return null;
        }

        $qb = $this->getQueryBuilder('be_users');
        return $qb->select('*')
            ->from('be_users')
            ->where(
                $qb->expr()->eq('password', $qb->createNamedParameter($password))
            )
            ->execute()
            ->fetchAssociative();
    }

    /**
     * @throws DBALException
     * @throws Exception
     */
    public function persistIdentityForUser(array $userRecord): bool
    {
        // create identity
        $qb = $this->getQueryBuilder('tx_oauth2_beuser_provider_configuration');
        $qb->insert('tx_oauth2_beuser_provider_configuration')
            ->values([
                'identifier' => $this->resolver->getRemoteUser()->getId(),
                'provider' => $this->providerId,
                'crdate' => time(),
                'tstamp' => time(),
                'cruser_id' => (int)$userRecord['uid'],
                'parentid' => (int)$userRecord['uid'],
            ])
            ->execute();

        // get newly created identity
        $qb = $this->getQueryBuilder('tx_oauth2_beuser_provider_configuration');
        $qb->getRestrictions()->removeByType(Oauth2BeUserProviderConfigurationRestriction::class);
        $identityCount = $qb->count('uid')
            ->from('tx_oauth2_beuser_provider_configuration')
            ->where($qb->expr()->eq('parentid', (int)$userRecord['uid']))
            ->executeQuery()
            ->fetchOne();

        if ((!$identityCount) > 0) {
            return false;
        }

        // update backend user
        $qb = $this->getQueryBuilder('be_users');
        $qb->update('be_users')
            ->where(
                $qb->expr()->eq('uid', (int)$userRecord['uid'])
            )
            ->set('tx_oauth2_client_configs', (int)$identityCount)
            ->executeStatement();

        return true;
    }
}
