<?php

namespace Xima\XimaOauth2Extended\EventListener;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Waldhacker\Oauth2Client\Events\FrontendUserLookupEvent;
use Xima\XimaOauth2Extended\Exception\IdentityResolverException;
use Xima\XimaOauth2Extended\Exception\OAuth2ConfigurationException;
use Xima\XimaOauth2Extended\ResourceResolver\ResourceResolverInterface;
use Xima\XimaOauth2Extended\UserFactory\FrontendUserFactory;

class FrontendUserLookup
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ExtensionConfiguration $extensionConfiguration
    ) {
    }

    public function __invoke(FrontendUserLookupEvent $event): void
    {
        if ($event->getTypo3User() !== null || !($event->getRemoteUser() instanceof ResourceOwnerInterface)) {
            return;
        }

        $providerId = $event->getProviderId();
        $extendedProviderConfiguration = $this->extensionConfiguration->get('xima-oauth2-extended', $providerId) ?? [];
        $resolverClass = $extendedProviderConfiguration['resolverClassName'] ?? '';

        if (!$resolverClass) {
            return;
        }

        // create resolver
        $resolver = GeneralUtility::makeInstance($resolverClass, $event);
        if (!$resolver instanceof ResourceResolverInterface) {
            $message = 'Class ' . $resolverClass . ' musst implement interface ' . ResourceResolverInterface::class;
            throw new IdentityResolverException($message, 1683016777);
        }

        // log info
        $this->logger->info('Register remote user from provider "' . $event->getProviderId() . '" (remote id: ' . $event->getRemoteUser()->getId() . ')');

        // create/link user
        $userFactory = new FrontendUserFactory($resolver, $providerId, $extendedProviderConfiguration);
        $storagePid = $this->getUserStoragePid($event);
        $typo3User = $userFactory->registerRemoteUser($storagePid);

        // add user to event
        if ($typo3User) {
            $event->setTypo3User($typo3User);
        }
    }

    /**
     * @throws SiteNotFoundException
     * @throws OAuth2ConfigurationException
     */
    protected function getUserStoragePid(FrontendUserLookupEvent $event): int
    {
        /** @var Site|null $site */
        $site = $event->getSite();
        $language = $event->getLanguage();
        if ($site === null || $language === null) {
            throw new SiteNotFoundException('Could not resolve site config in FrontendUserLookup', 1683033518);
        }
        $siteConfiguration = $site->getConfiguration();
        $languageConfiguration = $language->toArray();
        if ($languageConfiguration['oauth2_storage_pid'] ?? '') {
            return (int)$languageConfiguration['oauth2_storage_pid'];
        }
        if ($siteConfiguration['oauth2_storage_pid'] ?? '') {
            return (int)$siteConfiguration['oauth2_storage_pid'];
        }

        throw new OAuth2ConfigurationException('Could not determine frontend user storage pid', 1683034465);
    }
}
