<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractUpsertAddressRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\UpsertAddressRouteResponse;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class UpsertAddressRouteDecorator extends AbstractUpsertAddressRoute
{
    private const CONFIG_PREFIX = 'TopdataBetterCheckoutSW6.config.';

    public function __construct(
        private readonly AbstractUpsertAddressRoute $decorated,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function getDecorated(): AbstractUpsertAddressRoute
    {
        return $this->decorated;
    }

    public function upsert(?string $addressId, RequestDataBag $data, SalesChannelContext $context, CustomerEntity $customer): UpsertAddressRouteResponse
    {
        $this->enforceAccountType($data, $context);

        return $this->decorated->upsert($addressId, $data, $context, $customer);
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
}
