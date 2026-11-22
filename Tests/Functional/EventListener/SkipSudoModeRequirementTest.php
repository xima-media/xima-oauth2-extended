<?php

declare(strict_types=1);

namespace Xima\XimaOauth2Extended\Tests\Functional\EventListener;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Security\SudoMode\Access\AccessFactory;
use TYPO3\CMS\Backend\Security\SudoMode\Access\AccessSubjectInterface;
use TYPO3\CMS\Backend\Security\SudoMode\Access\RouteAccessSubject;
use TYPO3\CMS\Backend\Security\SudoMode\Event\SudoModeRequiredEvent;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SkipSudoModeRequirementTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
    }

    /**
     * @return iterable<string, array{int, string, bool}>
     */
    public static function verificationRequiredDataProvider(): iterable
    {
        yield 'OAuth-linked editor changing its own password' => [3, 'own-password', false];
        yield 'OAuth-linked editor opening a protected route' => [3, 'route', true];
        yield 'OAuth-linked admin changing its own password' => [1, 'own-password', false];
        yield 'OAuth-linked admin opening a protected route' => [1, 'route', false];
        yield 'OAuth-linked admin changing another user\'s password' => [1, 'other-password', false];
        yield 'unlinked editor changing its own password' => [4, 'own-password', true];
        yield 'unlinked admin changing its own password' => [2, 'own-password', true];
        yield 'unlinked admin opening a protected route' => [2, 'route', true];
    }

    #[Test]
    #[DataProvider('verificationRequiredDataProvider')]
    public function verificationRequirementDependsOnOAuthLinkAndAdminFlag(int $userUid, string $subject, bool $expected): void
    {
        $this->setUpBackendUser($userUid);
        $accessSubject = match ($subject) {
            'own-password' => $this->tableSubject('be_users', 'password', (string)$userUid),
            'other-password' => $this->tableSubject('be_users', 'password', '4'),
            'route' => new RouteAccessSubject('backend_user_management'),
        };

        $event = $this->dispatch($accessSubject);

        self::assertSame($expected, $event->isVerificationRequired());
    }

    private function tableSubject(string $table, string $field, string $id): AccessSubjectInterface
    {
        return $this->get(AccessFactory::class)->buildTableAccessSubject($table, $field, $id, []);
    }

    private function dispatch(AccessSubjectInterface $subject): SudoModeRequiredEvent
    {
        $claim = $this->get(AccessFactory::class)->buildClaimForSubjectRequest(
            new ServerRequest('https://example.org/typo3/'),
            null,
            $subject
        );
        $event = $this->get(EventDispatcherInterface::class)->dispatch(new SudoModeRequiredEvent($claim));
        self::assertInstanceOf(SudoModeRequiredEvent::class, $event);
        return $event;
    }
}
