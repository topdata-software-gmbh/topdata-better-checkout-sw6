<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Shopware\Core\Framework\Validation\DataValidationDefinition;

/**
 * AddressValidationSubscriber handles validation rules for customer and address data.
 * It modifies validation constraints based on configuration settings and account types.
 */
class AddressValidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'framework.validation.customer.create' => 'onCustomerValidation',
            'framework.validation.customer.update' => 'onCustomerValidation',
            'framework.validation.address.create'  => 'onAddressValidation',
            'framework.validation.address.update'  => 'onAddressValidation',
        ];
    }

    /**
     * Handles validation for customer creation and update events.
     * Applies validation rules based on account type and configuration settings.
     *
     * @param BuildValidationEvent $event The validation event
     */
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

    /**
     * Handles validation for address creation and update events.
     * Determines if the address is for billing or shipping and applies appropriate validation rules.
     *
     * @param BuildValidationEvent $event The validation event
     */
    public function onAddressValidation(BuildValidationEvent $event): void
    {
        $definition = $event->getDefinition();
        $data = $event->getData();
        $context = $event->getContext();
        $source = $context->getSource();
        $salesChannelId = $source instanceof SalesChannelApiSource ? $source->getSalesChannelId() : null;

        // ---- Determine if this is a business account
        $isBusiness = $data->has('accountType') && $data->get('accountType') === CustomerEntity::ACCOUNT_TYPE_BUSINESS;

        // ---- Determine if this is a shipping or billing address
        $type = 'billing';
        $customFields = $data->get('customFields');

        if ($customFields !== null) {
            if (\is_object($customFields) && method_exists($customFields, 'get')) {
                $isFaktura = $customFields->get('is_faktura');
                if ($isFaktura === false || $isFaktura === '0' || $isFaktura === 0) {
                    $type = 'shipping';
                }
            } elseif (\is_array($customFields)) {
                if (isset($customFields['is_faktura']) && ($customFields['is_faktura'] === false || $customFields['is_faktura'] === '0' || $customFields['is_faktura'] === 0)) {
                    $type = 'shipping';
                }
            }
        }

        // ---- Apply validation rules based on address type and business status
        $this->applyValidationRules($definition, $type, $salesChannelId, $isBusiness);
    }

    /**
     * Applies validation rules to a data definition based on configuration settings.
     * Modifies company field validation based on whether it's optional or required.
     *
     * @param DataValidationDefinition $definition The validation definition to modify
     * @param string $type The address type ('billing' or 'shipping')
     * @param string|null $salesChannelId The sales channel ID
     * @param bool $isBusiness Whether the customer is a business account
     */
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

    /**
     * Removes a specific constraint from a field in the validation definition.
     *
     * @param DataValidationDefinition $definition The validation definition
     * @param string $fieldName The field name to modify
     * @param string $constraintClass The constraint class to remove
     */
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

    /**
     * Adds a constraint to a field if it doesn't already exist.
     *
     * @param DataValidationDefinition $definition The validation definition
     * @param string $fieldName The field name to modify
     * @param \Symfony\Component\Validator\Constraint $newConstraint The constraint to add
     */
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