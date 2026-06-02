<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractSwitchDefaultAddressRoute;
use Shopware\Core\System\SalesChannel\NoContentResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SetDefaultBillingAddressRouteDecorator extends AbstractSwitchDefaultAddressRoute
{
    public function __construct(
        private readonly AbstractSwitchDefaultAddressRoute $decorated
    ) {
    }

    public function getDecorated(): AbstractSwitchDefaultAddressRoute
    {
        return $this->decorated;
    }

    public function swap(string $addressId, string $type, SalesChannelContext $context, CustomerEntity $customer): NoContentResponse
    {
        if ($type === AbstractSwitchDefaultAddressRoute::TYPE_BILLING) {
            throw new AccessDeniedHttpException('Changing the default billing address is not allowed. Please edit the existing billing address instead.');
        }

        if ($type === AbstractSwitchDefaultAddressRoute::TYPE_SHIPPING) {
            $billingAddress = $customer->getDefaultBillingAddress();
            if ($billingAddress !== null && $billingAddress->getId() === $addressId) {
                throw new AccessDeniedHttpException('The billing address cannot be set as the default shipping address.');
            }
        }

        return $this->decorated->swap($addressId, $type, $context, $customer);
    }
}
