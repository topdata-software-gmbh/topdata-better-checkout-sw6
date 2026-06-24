<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractUpsertAddressRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\UpsertAddressRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class UpsertAddressRouteDecorator extends AbstractUpsertAddressRoute
{
    private const CONFIG_PREFIX = 'TopdataBetterCheckoutSW6.config.';

    public function __construct(
        private readonly AbstractUpsertAddressRoute $decorated,
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $addressRepository,
    ) {
    }

    public function getDecorated(): AbstractUpsertAddressRoute
    {
        return $this->decorated;
    }

    public function upsert(?string $addressId, RequestDataBag $data, SalesChannelContext $context, CustomerEntity $customer): UpsertAddressRouteResponse
    {
        $this->enforceAccountType($data, $context);
        $this->concatenateHouseNumber($data);
        $this->preserveBillingCompany($addressId, $data, $context, $customer);

        return $this->decorated->upsert($addressId, $data, $context, $customer);
    }

    /**
     * The billing address company field is read-only on the edit form (change-request
     * mechanism) and therefore never present in the submitted form data. Shopware's
     * core UpsertAddressRoute unconditionally overwrites the stored company with the
     * (missing) value, which would wipe it. Guard the invariant that the company name
     * of a billing address can never be emptied through the edit form by re-injecting
     * the persisted value whenever the submitted one is empty.
     */
    private function preserveBillingCompany(?string $addressId, RequestDataBag $data, SalesChannelContext $context, CustomerEntity $customer): void
    {
        if ($addressId === null || $addressId !== $customer->getDefaultBillingAddressId()) {
            return;
        }

        $submittedCompany = $data->get('company');
        if (\is_string($submittedCompany) && trim($submittedCompany) !== '') {
            return;
        }

        /** @var CustomerAddressCollection|null $addresses */
        $addresses = $this->addressRepository->search(new Criteria([$addressId]), $context->getContext())->getEntities();
        $existingAddress = $addresses?->get($addressId);

        $existingCompany = $existingAddress?->getCompany();
        if (\is_string($existingCompany) && trim($existingCompany) !== '') {
            $data->set('company', $existingCompany);
        }
    }

    private function enforceAccountType(RequestDataBag $data, SalesChannelContext $context): void
    {
        $setting = $this->systemConfigService->getString(
            self::CONFIG_PREFIX . 'registrationAccountType',
            $context->getSalesChannelId(),
        );

        if ($setting === '') {
            $setting = 'always_business';
        }

        if ($setting === 'always_private') {
            $data->set('accountType', CustomerEntity::ACCOUNT_TYPE_PRIVATE);
        } elseif ($setting === 'always_business') {
            $data->set('accountType', CustomerEntity::ACCOUNT_TYPE_BUSINESS);
        }

        if ($setting === 'always_private') {
            $data->remove('company');
            $data->remove('vatId');
        }
    }

    private function concatenateHouseNumber(RequestDataBag $data): void
    {
        $houseNumber = $data->getString('topdataHouseNumber');
        if ($houseNumber === '') {
            return;
        }

        $street = $data->getString('street');
        if ($street !== '' && !str_ends_with($street, $houseNumber)) {
            $data->set('street', $street . ' ' . $houseNumber);
        } elseif ($street === '') {
            $data->set('street', $houseNumber);
        }

        $data->remove('topdataHouseNumber');
    }
}
