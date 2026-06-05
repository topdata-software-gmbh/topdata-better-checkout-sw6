<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class LogoutRedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->attributes->get('_route') !== 'frontend.account.logout.page') {
            return;
        }

        if (!$event->getResponse() instanceof RedirectResponse) {
            return;
        }

        $salesChannelId = $request->attributes->get('sw-sales-channel-id');
        $target = $this->systemConfigService->getString(
            'TopdataBetterCheckoutSW6.config.logoutRedirectRoute',
            $salesChannelId
        );

        if ($target === '' || $target === 'frontend.account.login.page') {
            return;
        }

        if (str_starts_with($target, '/') || str_starts_with($target, 'http')) {
            $event->setResponse(new RedirectResponse($target));
        } else {
            $url = $this->router->generate($target);
            $event->setResponse(new RedirectResponse($url));
        }
    }
}
