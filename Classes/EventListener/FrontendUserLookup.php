<?php

namespace Xima\XimaOauth2Extended\EventListener;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Waldhacker\Oauth2Client\Events\FrontendUserLookupEvent;
use Xima\XmDkfzNetSite\ResourceResolver\AbstractResolver;
use Xima\XmDkfzNetSite\ResourceResolver\DkfzResourceResolver;
use Xima\XmDkfzNetSite\ResourceResolver\GitlabResolver;
use Xima\XmDkfzNetSite\ResourceResolver\XimaResolver;
use Xima\XmDkfzNetSite\UserFactory\FrontendUserFactory;

class FrontendUserLookup
{
    private LoggerInterface $logger;

    protected FrontendUserFactory $frontendUserFactory;

    protected ?array $typo3User = null;

    public function __construct(LoggerInterface $logger, FrontendUserFactory $frontendUserFactory)
    {
        $this->logger = $logger;
        $this->frontendUserFactory = $frontendUserFactory;
    }

    public function __invoke(FrontendUserLookupEvent $event): void
    {
        if ($event->getTypo3User() !== null || !($event->getRemoteUser() instanceof ResourceOwnerInterface)) {
            return;
        }
        $this->logger->debug('Creating remote user from provider "' . $event->getProviderId() . '" (remote id: ' . $event->getRemoteUser()->getId() . ')');

        $resolver = $this->createResolver($event);

        $this->frontendUserFactory->setResolver($resolver);

        /** @var Site|null $site */
        $site = $event->getSite();
        $language = $event->getLanguage();
        if ($site === null || $language === null) {
            return;
        }
        $siteConfiguration = $site->getConfiguration();
        $languageConfiguration = $language->toArray();
        $storagePid = empty($languageConfiguration['oauth2_storage_pid'])
            ? ($siteConfiguration['oauth2_storage_pid'] ?? null)
            : $languageConfiguration['oauth2_storage_pid'];

        $this->typo3User = $this->frontendUserFactory->registerRemoteUser($storagePid);

        if ($this->typo3User) {
            $event->setTypo3User($this->typo3User);
        }
    }

    /**
     * @throws \Exception
     */
    public function createResolver(FrontendUserLookupEvent $event): AbstractResolver
    {
        $resolverClasses = [
            'gitlab' => GitlabResolver::class,
            'dkfz' => DkfzResourceResolver::class,
            'xima' => XimaResolver::class,
        ];
        $resolverClass = $resolverClasses[$event->getProviderId()] ?? false;

        if (!$resolverClass) {
            throw new \Exception('No Resolver found for provider id "' . $event->getProviderId() . '"');
        }

        return GeneralUtility::makeInstance(
            $resolverClass,
            $event->getProvider(),
            $event->getRemoteUser(),
            $event->getAccessToken(),
            $event->getProviderId()
        );
    }
}
