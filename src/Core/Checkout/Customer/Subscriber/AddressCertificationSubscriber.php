<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\SwissPostApiService;
use Topdata\TopdataBetterCheckoutSW6\Message\ValidateAddressMessage;

class AddressCertificationSubscriber implements EventSubscriberInterface
{
    public const METADATA_KEY = 'topdata_swiss_post_certification_status';

    public function __construct(
        private readonly SwissPostApiService $apiService,
        private readonly EntityRepository $customerAddressRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'customer_address.written' => 'onAddressWritten',
        ];
    }

    public function onAddressWritten(EntityWrittenEvent $event): void
    {
        $salesChannelId = null;

        if (!$this->apiService->isValidationEnabled($salesChannelId)) {
            return;
        }

        foreach ($event->getWriteResults() as $result) {
            $payload = $result->getPayload();
            $addressId = $payload['id'] ?? null;

            if (!$addressId) {
                continue;
            }

            // Skip if a status is already being set in this write
            if (array_key_exists('customFields', $payload) && isset($payload['customFields'][self::METADATA_KEY])) {
                continue;
            }

            // Fetch address to check country (CH/LI only)
            $criteria = new Criteria([$addressId]);
            $criteria->addAssociation('country');
            /** @var CustomerAddressEntity|null $addressEntity */
            $addressEntity = $this->customerAddressRepository->search($criteria, $event->getContext())->first();

            if (!$addressEntity || !$addressEntity->getCountry()) {
                continue;
            }

            $iso = $addressEntity->getCountry()->getIso();
            if ($iso !== 'CH' && $iso !== 'LI') {
                continue;
            }

            // Dispatch async message instead of calling API synchronously
            $this->messageBus->dispatch(new ValidateAddressMessage(
                addressId: $addressId,
                salesChannelId: $salesChannelId,
            ));
        }
    }
}
