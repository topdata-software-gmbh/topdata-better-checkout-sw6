<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Storefront\Page\Account\Profile\AccountProfilePageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangePendingExtension;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestService;

class AccountProfilePageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CompanyNameChangeRequestService $companyNameChangeRequestService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AccountProfilePageLoadedEvent::class => 'onProfilePageLoaded',
        ];
    }

    public function onProfilePageLoaded(AccountProfilePageLoadedEvent $event): void
    {
        $customer = $event->getSalesChannelContext()->getCustomer();
        if (!$customer instanceof CustomerEntity) {
            return;
        }

        $billingAddressId = $customer->getDefaultBillingAddressId();
        if ($billingAddressId === null) {
            return;
        }

        $pendingRequest = $this->companyNameChangeRequestService->findPendingChangeRequest(
            $customer->getId(),
            $billingAddressId,
            $event->getContext()
        );

        if ($pendingRequest !== null) {
            $event->getPage()->addExtension(
                'topdataCompanyNameChangePending',
                new CompanyNameChangePendingExtension($pendingRequest)
            );
        }
    }
}
