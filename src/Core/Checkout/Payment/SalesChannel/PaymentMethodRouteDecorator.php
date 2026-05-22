<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Payment\SalesChannel;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Decorates the payment method route to block certain payment methods for guest customers.
 * This class checks if the customer is a guest and then filters out payment methods
 * that are configured to be blocked for guest customers based on their account type.
 */
class PaymentMethodRouteDecorator extends AbstractPaymentMethodRoute
{
    public function __construct(
        private readonly AbstractPaymentMethodRoute $decorated,
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function getDecorated(): AbstractPaymentMethodRoute
    {
        return $this->decorated;
    }

    /**
     * Loads payment methods and filters out blocked ones for guest customers.
     * If the customer is not a guest, the original response is returned unchanged.
     * For guest customers, payment methods that are configured to be blocked are removed from the response.
     *
     * @param Request $request The HTTP request
     * @param SalesChannelContext $context The sales channel context
     * @param Criteria $criteria The search criteria
     * @return PaymentMethodRouteResponse The filtered payment method response
     */
    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): PaymentMethodRouteResponse
    {
        // ---- Get the original payment methods response
        $response = $this->decorated->load($request, $context, $criteria);

        // ---- Check if the customer is a guest
        $customer = $context->getCustomer();
        if (!$customer instanceof CustomerEntity || !$customer->getGuest()) {
            return $response;
        }

        // ---- Resolve the payment IDs that should be blocked for this guest customer
        $blockedIds = $this->resolveBlockedPaymentIds($customer, $context->getSalesChannelId());
        if ($blockedIds === []) {
            return $response;
        }

        // ---- Filter out the blocked payment methods from the response
        $paymentMethods = $response->getPaymentMethods();
        foreach ($paymentMethods as $key => $paymentMethod) {
            if (\in_array($paymentMethod->getId(), $blockedIds, true)) {
                $paymentMethods->remove($key);
            }
        }

        return $response;
    }

    /**
     * Resolves the payment method IDs that should be blocked for a guest customer.
     * The blocked payment methods are determined based on the customer's account type
     * (private or business) and the configuration settings for the sales channel.
     *
     * @param CustomerEntity $customer The customer entity
     * @param string $salesChannelId The ID of the sales channel
     * @return list<string> The list of blocked payment method IDs
     */
    private function resolveBlockedPaymentIds(CustomerEntity $customer, string $salesChannelId): array
    {
        // ---- Determine the configuration key based on the customer's account type
        $configKey = null;
        if ($customer->getAccountType() === CustomerEntity::ACCOUNT_TYPE_PRIVATE) {
            $configKey = 'TopdataBetterCheckoutSW6.config.blockedPrivateGuestPayments';
        }

        if ($customer->getAccountType() === CustomerEntity::ACCOUNT_TYPE_BUSINESS) {
            $configKey = 'TopdataBetterCheckoutSW6.config.blockedBusinessGuestPayments';
        }

        // ---- Return empty array if no config key is found
        if ($configKey === null) {
            return [];
        }

        // ---- Get the blocked payment IDs from the system configuration
        $blockedIds = $this->systemConfigService->get($configKey, $salesChannelId);
        if (!\is_array($blockedIds)) {
            return [];
        }

        // ---- Filter and return the valid payment IDs
        return array_values(array_filter($blockedIds, static fn (mixed $id): bool => \is_string($id) && $id !== ''));
    }
}