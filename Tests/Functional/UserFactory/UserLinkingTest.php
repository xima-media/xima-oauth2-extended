<?php

declare(strict_types=1);

namespace Xima\XimaOauth2Extended\Tests\Functional\UserFactory;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GenericResourceOwner;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Waldhacker\Oauth2Client\Events\BackendUserLookupEvent;
use Waldhacker\Oauth2Client\Events\FrontendUserLookupEvent;
use Xima\XimaOauth2Extended\ResourceResolver\GenericResourceResolver;
use Xima\XimaOauth2Extended\ResourceResolver\ResolverOptions;
use Xima\XimaOauth2Extended\UserFactory\BackendUserFactory;
use Xima\XimaOauth2Extended\UserFactory\FrontendUserFactory;

final class UserLinkingTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'setup',
    ];

    protected array $testExtensionsToLoad = [
        'co-stack/typo3-oauth2-client',
        'xima/xima-oauth2-extended',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/users.csv');
    }

    #[Test]
    public function verifiedEmailLinksTheExistingEditorOnce(): void
    {
        $claims = ['sub' => 'editor', 'email' => 'editor@example.org', 'email_verified' => true, 'name' => 'Remote'];

        self::assertSame(2, $this->backendFactory($claims)->registerRemoteUser()['uid'] ?? null);
        self::assertSame(2, $this->backendFactory($claims)->registerRemoteUser()['uid'] ?? null);
        self::assertSame([2], $this->identityParents('tx_oauth2_beuser_provider_configuration', 'editor'));
    }

    #[Test]
    public function missingEmailVerifiedClaimStillLinks(): void
    {
        $claims = ['sub' => 'editor', 'email' => 'editor@example.org', 'name' => 'Remote'];

        self::assertSame(2, $this->backendFactory($claims)->registerRemoteUser()['uid'] ?? null);
    }

    #[Test]
    public function unverifiedEmailDoesNotLink(): void
    {
        $claims = ['sub' => 'editor', 'email' => 'editor@example.org', 'email_verified' => false, 'name' => 'Remote'];

        self::assertNull($this->backendFactory($claims, ['createBackendUser' => true])->registerRemoteUser());
        self::assertSame([], $this->identityParents('tx_oauth2_beuser_provider_configuration', 'editor'));
        self::assertSame(4, $this->countRows('be_users'));
    }

    #[Test]
    public function administratorsAreNeverLinked(): void
    {
        $claims = ['sub' => 'admin', 'username' => 'admin', 'email' => 'admin@example.org', 'email_verified' => true, 'name' => 'Remote'];

        self::assertNull($this->backendFactory($claims)->registerRemoteUser());
        self::assertSame([], $this->identityParents('tx_oauth2_beuser_provider_configuration', 'admin'));
        self::assertSame('Admin', $this->fetchUser('be_users', 1)['realName']);
    }

    #[Test]
    public function disabledSettingPreventsLinking(): void
    {
        $claims = ['sub' => 'editor', 'email' => 'editor@example.org', 'email_verified' => true, 'name' => 'Remote'];

        self::assertNull($this->backendFactory($claims, [], false)->registerRemoteUser());
        self::assertSame([], $this->identityParents('tx_oauth2_beuser_provider_configuration', 'editor'));
    }

    #[Test]
    public function disabledUserIsNotLinkedNorEnabled(): void
    {
        $claims = ['sub' => 'other', 'username' => 'disabled', 'email' => 'disabled@example.org', 'email_verified' => true, 'name' => 'Remote'];

        self::assertNull($this->backendFactory($claims)->registerRemoteUser());
        self::assertSame(1, $this->fetchUser('be_users', 3)['disable']);
        self::assertSame([], $this->identityParents('tx_oauth2_beuser_provider_configuration', 'other'));
    }

    #[Test]
    public function identityOfDisabledUserNeitherLogsInNorCreatesAUser(): void
    {
        $claims = ['sub' => 'disabled', 'email' => 'new@example.org', 'name' => 'Remote'];

        self::assertNull($this->backendFactory($claims, ['createBackendUser' => true])->registerRemoteUser());
        self::assertSame(1, $this->fetchUser('be_users', 3)['disable']);
        self::assertSame([3], $this->identityParents('tx_oauth2_beuser_provider_configuration', 'disabled'));
        self::assertSame(4, $this->countRows('be_users'));
    }

    #[Test]
    public function duplicateIdentityRowsOfOneUserAreMerged(): void
    {
        $claims = ['sub' => 'duplicate', 'email' => 'duplicate@example.org', 'name' => 'Remote'];

        self::assertSame(4, $this->backendFactory($claims, [], false)->registerRemoteUser()['uid'] ?? null);
        self::assertSame([4], $this->identityParents('tx_oauth2_beuser_provider_configuration', 'duplicate'));
        self::assertSame(1, $this->fetchUser('be_users', 4)['tx_oauth2_client_configs']);
    }

    #[Test]
    public function frontendUserInTheStorageFolderIsLinkedOnce(): void
    {
        $claims = ['sub' => 'member', 'email' => 'member@example.org', 'email_verified' => true, 'name' => 'Remote'];

        self::assertSame(2, $this->frontendFactory($claims)->registerRemoteUser(2)['uid'] ?? null);
        self::assertSame(2, $this->frontendFactory($claims)->registerRemoteUser(2)['uid'] ?? null);
        self::assertSame([2], $this->identityParents('tx_oauth2_feuser_provider_configuration', 'member'));
    }

    #[Test]
    public function frontendUserOutsideTheStorageFolderIsIgnored(): void
    {
        $claims = ['sub' => 'other-site', 'username' => 'other-site', 'email' => 'other-site@example.org', 'name' => 'Remote'];

        self::assertNull($this->frontendFactory($claims)->registerRemoteUser(2));
        self::assertSame([], $this->identityParents('tx_oauth2_feuser_provider_configuration', 'other-site'));

        $created = $this->frontendFactory($claims, ['createFrontendUser' => true])->registerRemoteUser(2);
        self::assertSame(2, $created['pid'] ?? null);
        self::assertNotSame(1, $created['uid'] ?? null);
        self::assertSame([$created['uid']], $this->identityParents('tx_oauth2_feuser_provider_configuration', 'other-site'));
    }

    private function backendFactory(array $claims, array $options = [], bool $linkExistingUsers = true): BackendUserFactory
    {
        $event = new BackendUserLookupEvent(
            'mock',
            self::createStub(AbstractProvider::class),
            new AccessToken(['access_token' => 'token']),
            new GenericResourceOwner($claims, 'sub'),
            null,
            new ServerRequest()
        );
        $resolver = new GenericResourceResolver($event, $this->resolverOptions($options));

        return new BackendUserFactory($resolver, 'mock', new NullLogger(), $linkExistingUsers);
    }

    private function frontendFactory(array $claims, array $options = []): FrontendUserFactory
    {
        $event = new FrontendUserLookupEvent(
            'mock',
            self::createStub(AbstractProvider::class),
            new AccessToken(['access_token' => 'token']),
            new GenericResourceOwner($claims, 'sub'),
            null,
            null,
            null
        );
        $resolver = new GenericResourceResolver($event, $this->resolverOptions($options));

        return new FrontendUserFactory($resolver, 'mock', ['mock' => $options]);
    }

    private function resolverOptions(array $options): ResolverOptions
    {
        return ResolverOptions::createFromExtensionConfiguration(['resolverClassName' => GenericResourceResolver::class] + $options);
    }

    /**
     * @return int[]
     */
    private function identityParents(string $table, string $identifier): array
    {
        $parents = $this->getConnectionPool()->getConnectionForTable($table)
            ->executeQuery('SELECT parentid FROM ' . $table . ' WHERE identifier = ? ORDER BY uid', [$identifier])
            ->fetchFirstColumn();

        return array_map('intval', $parents);
    }

    private function fetchUser(string $table, int $uid): array
    {
        $row = $this->getConnectionPool()->getConnectionForTable($table)
            ->executeQuery('SELECT * FROM ' . $table . ' WHERE uid = ?', [$uid])
            ->fetchAssociative();
        self::assertIsArray($row);

        return array_map(static fn ($value) => is_numeric($value) ? (int)$value : $value, $row);
    }

    private function countRows(string $table): int
    {
        return (int)$this->getConnectionPool()->getConnectionForTable($table)
            ->executeQuery('SELECT COUNT(*) FROM ' . $table)
            ->fetchOne();
    }
}
