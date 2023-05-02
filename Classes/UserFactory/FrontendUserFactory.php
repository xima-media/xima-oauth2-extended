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
use TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Waldhacker\Oauth2Client\Database\Query\Restriction\Oauth2BeUserProviderConfigurationRestriction;
use Waldhacker\Oauth2Client\Database\Query\Restriction\Oauth2FeUserProviderConfigurationRestriction;
use Xima\XimaOauth2Extended\ResourceResolver\ResourceResolverInterface;

class FrontendUserFactory
{
    protected ResourceResolverInterface $resolver;

    protected string $providerId = '';

    protected array $extendedProviderConfiguration = [];

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
                $qb->createNamedParameter($username, \PDO::PARAM_STR)
            );
        }

        if ($email) {
            $constraints[] = $qb->expr()->eq(
                'email',
                $qb->createNamedParameter($email, \PDO::PARAM_STR)
            );
        }

        if (empty($constraints)) {
            return null;
        }

        $user = $qb
            ->select('*')
            ->from('fe_users')
            ->where($qb->expr()->orX(...$constraints))
            ->execute()
            ->fetchAssociative();

        return $user ?: null;
    }

    public function registerRemoteUser(int $targetPid): ?array
    {
        $doCreateNewUser = isset($this->extendedProviderConfiguration) && $this->extendedProviderConfiguration['createFrontendUser'];

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

        // update user slug
        $this->updateFrontendUserSlug($userRecord);

        try {
            if ($this->persistIdentityForUser($userRecord)) {
                return $userRecord;
            }
        } catch (Exception $e) {
        }

        return null;
    }

    protected function updateFrontendUserSlug(&$userRecord): void
    {
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
                    $qb->createNamedParameter($userRecord['uid'], \PDO::PARAM_INT)
                )
            )
            ->set('slug', $slug);
        $qb->execute();
    }

    /**
     * @throws DBALException
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
                'cruser_id' => (int)$userRecord['uid'],
                'parentid' => (int)$userRecord['uid'],
            ])
            ->execute();

        // get newly created identity
        $qb = $this->getQueryBuilder('tx_oauth2_feuser_provider_configuration');
        $qb->getRestrictions()->removeByType(Oauth2BeUserProviderConfigurationRestriction::class);
        $qb->getRestrictions()->removeByType(Oauth2FeUserProviderConfigurationRestriction::class);
        $identityCount = $qb->count('uid')
            ->from('tx_oauth2_feuser_provider_configuration')
            ->where($qb->expr()->eq('parentid', (int)$userRecord['uid']))
            ->executeQuery()
            ->fetchOne();

        if (!$identityCount > 0) {
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
     * @throws DBALException
     * @throws Exception
     */
    public function persistAndRetrieveUser($userRecord): ?array
    {
        $password = $userRecord['password'];

        $user = $this->getQueryBuilder('fe_users')->insert('fe_users')
            ->values($userRecord)
            ->execute();

        if (!$user) {
            return null;
        }

        $qb = $this->getQueryBuilder('fe_users');
        return $qb->select('*')
            ->from('fe_users')
            ->where(
                $qb->expr()->eq('password', $qb->createNamedParameter($password, \PDO::PARAM_STR))
            )
            ->execute()
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
        $defaultUserGroup = $this->extendedProviderConfiguration['defaultFrontendUsergroup'] ?? '';

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
