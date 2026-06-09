<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Message;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\SwissPostApiService;

#[AsMessageHandler]
class ValidateAddressHandler
{
    public const METADATA_KEY = 'topdata_swiss_post_certification_status';

    public function __construct(
        private readonly SwissPostApiService $apiService,
        private readonly EntityRepository $customerAddressRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ValidateAddressMessage $message): void
    {
        $addressId = $message->getAddressId();
        $context = Context::createDefaultContext();

        try {
            $criteria = new Criteria([$addressId]);
            $criteria->addAssociation('country');
            $address = $this->customerAddressRepository->search($criteria, $context)->first();

            if (!$address || !$address->getCountry()) {
                $this->logger->warning('ValidateAddressHandler: address not found or missing country', ['addressId' => $addressId]);
                return;
            }

            $iso = $address->getCountry()->getIso();
            if ($iso !== 'CH' && $iso !== 'LI') {
                return;
            }

            $validation = $this->apiService->validateAddress([
                'firstName' => $address->getFirstName(),
                'lastName' => $address->getLastName(),
                'street' => $address->getStreet(),
                'zipcode' => $address->getZipcode(),
                'city' => $address->getCity(),
                'countryCode' => $iso,
            ], $message->getSalesChannelId());

            $quality = $validation['quality'] ?? ($validation['success'] ? 'UNKNOWN' : 'INVALID');

            $customFields = $address->getCustomFields() ?? [];
            $customFields[self::METADATA_KEY] = $quality;

            $this->customerAddressRepository->update([
                [
                    'id' => $addressId,
                    'customFields' => $customFields,
                ],
            ], $context);

            $this->logger->info('ValidateAddressHandler: address quality updated', [
                'addressId' => $addressId,
                'quality' => $quality,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('ValidateAddressHandler: exception', [
                'addressId' => $addressId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
