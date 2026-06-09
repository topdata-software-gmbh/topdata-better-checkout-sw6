<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\NotBlank;
use Shopware\Core\Framework\Validation\DataValidationDefinition;

class AddressValidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'framework.validation.customer.create' => 'onCustomerValidation',
            'framework.validation.customer.update' => 'onCustomerValidation',
            'framework.validation.address.create'  => 'onAddressValidation',
            'framework.validation.address.update'  => 'onAddressValidation',
        ];
    }

    public function onCustomerValidation(BuildValidationEvent $event): void
    {
        $definition = $event->getDefinition();
        $data = $event->getData();
        $context = $event->getContext();
        $source = $context->getSource();
        $salesChannelId = $source instanceof SalesChannelApiSource ? $source->getSalesChannelId() : null;

        $accountType = $data->get('accountType');
        $isBusiness = $accountType === CustomerEntity::ACCOUNT_TYPE_BUSINESS;

        $subDefinitions = $definition->getSubDefinitions();

        // ---- Apply validation rules to billing address if it exists
        if (isset($subDefinitions['billingAddress'])) {
            $this->applyValidationRules($subDefinitions['billingAddress'], 'billing', $salesChannelId, $isBusiness);

            // ---- Handle company field validation based on configuration
            $billingSetting = $this->systemConfigService->getString('TopdataBetterCheckoutSW6.config.companyValidationBilling', $salesChannelId);
            if ($billingSetting === 'optional') {
                $this->removeConstraint($definition, 'company', NotBlank::class);
            } elseif ($billingSetting === 'required' && $isBusiness) {
                $this->addConstraintIfNotExists($definition, 'company', new NotBlank());
            }
        }

        // ---- Apply validation rules to shipping address if it exists
        if (isset($subDefinitions['shippingAddress'])) {
            $this->applyValidationRules($subDefinitions['shippingAddress'], 'shipping', $salesChannelId, $isBusiness);
        }
    }

    public function onAddressValidation(BuildValidationEvent $event): void
    {
        $definition = $event->getDefinition();
        $data = $event->getData();
        $context = $event->getContext();
        $source = $context->getSource();
        $salesChannelId = $source instanceof SalesChannelApiSource ? $source->getSalesChannelId() : null;

        $isBusiness = $data->has('accountType') && $data->get('accountType') === CustomerEntity::ACCOUNT_TYPE_BUSINESS;

        $type = 'shipping';

        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            $salesChannelContext = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
            if ($salesChannelContext && $salesChannelContext->getCustomer()) {
                $customer = $salesChannelContext->getCustomer();
                $addressId = $data->get('id');

                if ($addressId !== null && $addressId === $customer->getDefaultBillingAddressId()) {
                    $type = 'billing';
                }
            }
        }

        $this->applyValidationRules($definition, $type, $salesChannelId, $isBusiness);
    }

    private function applyValidationRules(DataValidationDefinition $definition, string $type, ?string $salesChannelId, bool $isBusiness): void
    {
        // ---- Get the appropriate configuration key based on address type
        $configKey = $type === 'shipping'
            ? 'TopdataBetterCheckoutSW6.config.companyValidationShipping'
            : 'TopdataBetterCheckoutSW6.config.companyValidationBilling';

        $setting = $this->systemConfigService->getString($configKey, $salesChannelId);

        // ---- Apply validation rules based on configuration setting
        if ($setting === 'optional') {
            $this->removeConstraint($definition, 'company', NotBlank::class);
        } elseif ($setting === 'required' && $isBusiness) {
            $this->addConstraintIfNotExists($definition, 'company', new NotBlank());
        }
    }

    private function removeConstraint(DataValidationDefinition $definition, string $fieldName, string $constraintClass): void
    {
        $properties = $definition->getProperties();
        if (!isset($properties[$fieldName])) {
            return;
        }

        // ---- Filter out the specified constraint class
        $constraints = $properties[$fieldName];
        $newConstraints = array_filter($constraints, fn($c) => !($c instanceof $constraintClass));

        // ---- Update the definition if constraints were removed
        if (count($newConstraints) !== count($constraints)) {
            $definition->set($fieldName, ...$newConstraints);
        }
    }

    private function addConstraintIfNotExists(DataValidationDefinition $definition, string $fieldName, \Symfony\Component\Validator\Constraint $newConstraint): void
    {
        $properties = $definition->getProperties();
        $constraints = $properties[$fieldName] ?? [];

        // ---- Check if constraint already exists
        $constraintClass = \get_class($newConstraint);
        foreach ($constraints as $constraint) {
            if ($constraint instanceof $constraintClass) {
                return;
            }
        }

        // ---- Add the new constraint
        $definition->add($fieldName, $newConstraint);
    }
}