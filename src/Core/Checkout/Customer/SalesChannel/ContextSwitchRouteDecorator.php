<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel;

use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\ContextTokenResponse;

/**
 * Decorator for the context switch route that handles customer-specific logic during context switching.
 * This implementation ensures that billing address ID is removed from request data when a customer is present.
 */
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

    /**
     * Switches the sales channel context with additional customer-specific logic.
     * Removes the billing address ID from request data if a customer is present to prevent conflicts.
     *
     * @param RequestDataBag $data The request data containing context information
     * @param SalesChannelContext $context The current sales channel context
     * @return ContextTokenResponse The response containing the new context token
     */
    public function switchContext(RequestDataBag $data, SalesChannelContext $context): ContextTokenResponse
    {
        // ---- Check if customer exists and remove billing address ID if present
        $customer = $context->getCustomer();

        if ($customer !== null && $data->has('billingAddressId')) {
            $data->remove('billingAddressId');
        }

        return $this->decorated->switchContext($data, $context);
    }
}