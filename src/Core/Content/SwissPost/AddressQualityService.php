<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

class AddressQualityService
{
    public const METADATA_KEY = 'topdata_swiss_post_certification_status';
    public const NOT_APPLICABLE = '_NOT_APPLICABLE';

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $customerAddressRepository,
    ) {
    }

    public function isApplicableCountry(?string $iso): bool
    {
        return $iso === 'CH' || $iso === 'LI';
    }

    public function hasStatus(?array $customFields): bool
    {
        return isset($customFields[self::METADATA_KEY]);
    }

    public function getStatus(?array $customFields): ?string
    {
        return $customFields[self::METADATA_KEY] ?? null;
    }

    public function setQuality(string $addressId, string $quality, Context $context): void
    {
        $criteria = new Criteria([$addressId]);
        $address = $this->customerAddressRepository->search($criteria, $context)->first();
        if (!$address) {
            return;
        }

        $customFields = $address->getCustomFields() ?? [];
        $customFields[self::METADATA_KEY] = $quality;

        $this->customerAddressRepository->update([
            [
                'id' => $addressId,
                'customFields' => $customFields,
            ],
        ], $context);
    }

    public function setNotApplicable(string $addressId, Context $context): void
    {
        $this->setQuality($addressId, self::NOT_APPLICABLE, $context);
    }

    public function upsertQualityRaw(string $hexId, string $quality): void
    {
        $this->connection->executeStatement(
            'UPDATE customer_address
             SET custom_fields = JSON_SET(COALESCE(custom_fields, \'{}\'), :jsonPath, :quality)
             WHERE id = :id',
            [
                'jsonPath' => '$.' . self::METADATA_KEY,
                'quality' => $quality,
                'id' => Uuid::fromHexToBytes($hexId),
            ],
            [
                'quality' => ParameterType::STRING,
                'id' => ParameterType::BINARY,
            ]
        );
    }
}
