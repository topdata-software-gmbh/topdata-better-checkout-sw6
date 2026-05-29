<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractSetDefaultBillingAddressRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SuccessResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SetDefaultBillingAddressRouteDecorator extends AbstractSetDefaultBillingAddressRoute
{
    public function __construct(
        private readonly AbstractSetDefaultBillingAddressRoute $decorated
    ) {
    }

    public function getDecorated(): AbstractSetDefaultBillingAddressRoute
    {
        return $this->decorated;
    }

    public function setDefaultBillingAddress(string $addressId, SalesChannelContext $context, CustomerEntity $customer): SuccessResponse
    {
        throw new AccessDeniedHttpException('Changing the default billing address is not allowed. Please edit the existing billing address instead.');
    }
}
