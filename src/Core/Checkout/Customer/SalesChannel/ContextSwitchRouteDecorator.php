<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel;

use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\ContextTokenResponse;

class ContextSwitchRouteDecorator extends AbstractContextSwitchRoute
{
    public function __construct(
        private readonly AbstractContextSwitchRoute $decorated
    ) {
    }

    public function getDecorated(): AbstractContextSwitchRoute
    {
        return $this->decorated;
    }

    public function switchContext(RequestDataBag $data, SalesChannelContext $context): ContextTokenResponse
    {
        $customer = $context->getCustomer();

        if ($customer !== null && $data->has('billingAddressId')) {
            $data->remove('billingAddressId');
        }

        return $this->decorated->switchContext($data, $context);
    }
}
