<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Storefront\Page\Address\Detail\AddressDetailPageLoadedEvent;
use Shopware\Storefront\Page\Address\Listing\AddressListingPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangePendingExtension;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestService;

class AccountAddressPageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CompanyNameChangeRequestService $companyNameChangeRequestService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AddressListingPageLoadedEvent::class => 'onAddressListingPageLoaded',
            AddressDetailPageLoadedEvent::class => 'onAddressDetailPageLoaded',
        ];
    }

    public function onAddressListingPageLoaded(AddressListingPageLoadedEvent $event): void
    {
        $customer = $event->getSalesChannelContext()->getCustomer();
        if (!$customer instanceof CustomerEntity) {
            return;
        }

        $pendingRequest = $this->companyNameChangeRequestService->findPendingChangeRequestForCustomer(
            $customer->getId(),
            $event->getContext()
        );

        if ($pendingRequest !== null) {
            $event->getPage()->addExtension(
                'topdataCompanyNameChangePending',
                new CompanyNameChangePendingExtension($pendingRequest)
            );
        }
    }

    public function onAddressDetailPageLoaded(AddressDetailPageLoadedEvent $event): void
    {
        $customer = $event->getSalesChannelContext()->getCustomer();
        if (!$customer instanceof CustomerEntity) {
            return;
        }

        $pendingRequest = $this->companyNameChangeRequestService->findPendingChangeRequestForCustomer(
            $customer->getId(),
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
