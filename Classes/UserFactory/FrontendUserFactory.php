<?php

namespace Xima\XimaOauth2Extended\UserFactory;

use Doctrine\DBAL\Driver\Exception;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Xima\XimaOauth2Extended\Exception\UserLinkingDeniedException;
use Xima\XimaOauth2Extended\ResourceResolver\ResourceResolverInterface;

class FrontendUserFactory extends AbstractUserFactory
{
    public function __construct(
        ResourceResolverInterface $resolver,
        string $providerId,
        protected array $extendedProviderConfiguration,
        LoggerInterface $logger = new NullLogger(),
        bool $linkExistingUsers = true
    ) {
        parent::__construct($resolver, $providerId, $logger, $linkExistingUsers);
    }

    public function registerRemoteUser(int $targetPid): ?array
    {
        $doCreateNewUser = isset($this->extendedProviderConfiguration[$this->providerId]['createFrontendUser']) && $this->extendedProviderConfiguration[$this->providerId]['createFrontendUser'];

        // find or optionally create
        try {
            $userRecord = $this->findExistingUser('fe_users', 'tx_oauth2_feuser_provider_configuration', $targetPid);
        } catch (UserLinkingDeniedException $e) {
            $this->logDeniedLogin($e);
            return null;
        }
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
        return $this->persistIdentity('fe_users', 'tx_oauth2_feuser_provider_configuration', (int)$userRecord['uid']);
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
            'disable' => 0,
            'starttime' => 0,
            'endtime' => 0,
            'password' => $saltingInstance->getHashedPassword(md5(uniqid())),
            'name' => '',
            'username' => '',
            'usergroup' => $defaultUserGroup,
        ];
    }
}
