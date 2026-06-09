<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\SwissPostApiService;

class AddressCertificationSubscriber implements EventSubscriberInterface
{
    public const METADATA_KEY = 'topdata_swiss_post_certification_status';

    public function __construct(
        private readonly SwissPostApiService $apiService,
        private readonly EntityRepository $customerAddressRepository
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
        $context = $event->getContext();
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

            if (array_key_exists('customFields', $payload) && isset($payload['customFields'][self::METADATA_KEY])) {
                continue;
            }

            $criteria = new Criteria([$addressId]);
            $criteria->addAssociation('country');
            /** @var CustomerAddressEntity|null $addressEntity */
            $addressEntity = $this->customerAddressRepository->search($criteria, $context)->first();

            if (!$addressEntity || !$addressEntity->getCountry()) {
                continue;
            }

            $iso = $addressEntity->getCountry()->getIso();
            if ($iso !== 'CH' && $iso !== 'LI') {
                continue;
            }

            $validation = $this->apiService->validateAddress([
                'firstName' => $addressEntity->getFirstName(),
                'lastName' => $addressEntity->getLastName(),
                'street' => $addressEntity->getStreet(),
                'zipcode' => $addressEntity->getZipcode(),
                'city' => $addressEntity->getCity(),
                'countryCode' => $iso,
            ], $salesChannelId);

            $quality = $validation['success'] ? ($validation['quality'] ?? 'UNKNOWN') : 'INVALID';

            $customFields = $addressEntity->getCustomFields() ?? [];
            $customFields[self::METADATA_KEY] = $quality;

            $this->customerAddressRepository->update([
                [
                    'id' => $addressId,
                    'customFields' => $customFields,
                ],
            ], $context);
        }
    }
}
