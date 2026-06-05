<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class LogoutRedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly RouterInterface $router,
        private readonly EntityRepository $languageRepository,
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
        $config = $this->systemConfigService->getString(
            'TopdataBetterCheckoutSW6.config.logoutRedirectRoute',
            $salesChannelId
        );

        $locale = $this->getLocaleFromRequest($request);
        $target = $this->resolveTarget($config, $locale);

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

    private function getLocaleFromRequest(Request $request): string
    {
        if ($request->attributes->has('sw-language-id')) {
            $criteria = new Criteria([$request->attributes->get('sw-language-id')]);
            $criteria->addAssociation('locale');
            $language = $this->languageRepository->search($criteria, Context::createDefaultContext())->first();
            if ($language) {
                return $language->getLocale()->getCode();
            }
        }

        return $request->getLocale();
    }

    private function resolveTarget(string $config, string $locale): string
    {
        if (!str_contains($config, "\n")) {
            return $config;
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $config));
        $map = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                $parts = explode('=', $line, 2);
                $key = $this->normalizeLocale(trim($parts[0]));
                $map[$key] = trim($parts[1]);
            }
        }

        $normalized = $this->normalizeLocale($locale);

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        $fallback = substr($normalized, 0, 2);
        if (isset($map[$fallback])) {
            return $map[$fallback];
        }

        return $map['_default'] ?? '';
    }

    private function normalizeLocale(string $locale): string
    {
        return strtolower(str_replace('-', '_', $locale));
    }
}
