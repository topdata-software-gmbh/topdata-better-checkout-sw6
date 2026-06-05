<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Shopware\Storefront\Event\StorefrontRedirectEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class LogoutRedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRedirectEvent::class => 'onRedirect',
        ];
    }

    public function onRedirect(StorefrontRedirectEvent $event): void
    {
        if ($event->getRoute() !== 'frontend.account.login.page') {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return;
        }

        if ($request->attributes->get('_route') !== 'frontend.account.logout.page') {
            return;
        }

        $salesChannelId = $request->attributes->get('sw-sales-channel-id');
        $redirectRoute = $this->systemConfigService->getString(
            'TopdataBetterCheckoutSW6.config.logoutRedirectRoute',
            $salesChannelId
        );

        if ($redirectRoute !== '') {
            $event->setRoute($redirectRoute);
            $event->setParameters([]);
        }
    }
}
