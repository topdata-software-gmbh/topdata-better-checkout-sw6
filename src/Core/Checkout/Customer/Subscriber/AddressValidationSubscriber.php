<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Shopware\Core\Framework\Validation\DataValidationDefinition;

class AddressValidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService
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
        $salesChannelId = $event->getContext()->getSalesChannelId();

        $accountType = $data->get('accountType');
        $isBusiness = $accountType === CustomerEntity::ACCOUNT_TYPE_BUSINESS;

        $subDefinitions = $definition->getSubDefinitions();

        if (isset($subDefinitions['billingAddress'])) {
            $this->applyValidationRules($subDefinitions['billingAddress'], 'billing', $salesChannelId, $isBusiness);

            $billingSetting = $this->systemConfigService->getString('TopdataBetterCheckoutSW6.config.companyValidationBilling', $salesChannelId);
            if ($billingSetting === 'optional') {
                $this->removeConstraint($definition, 'company', NotBlank::class);
            } elseif ($billingSetting === 'required' && $isBusiness) {
                $this->addConstraintIfNotExists($definition, 'company', new NotBlank());
            }
        }

        if (isset($subDefinitions['shippingAddress'])) {
            $this->applyValidationRules($subDefinitions['shippingAddress'], 'shipping', $salesChannelId, $isBusiness);
        }
    }

    public function onAddressValidation(BuildValidationEvent $event): void
    {
        $definition = $event->getDefinition();
        $data = $event->getData();
        $context = $event->getContext();
        $salesChannelId = $context->getSalesChannelId();

        $customer = $context->getCustomer();
        $isBusiness = false;

        if ($data->has('accountType') && $data->get('accountType') === CustomerEntity::ACCOUNT_TYPE_BUSINESS) {
            $isBusiness = true;
        } elseif ($customer !== null && $customer->getAccountType() === CustomerEntity::ACCOUNT_TYPE_BUSINESS) {
            $isBusiness = true;
        }

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
        } else {
            if ($customer !== null && $data->has('id') && $data->get('id') === $customer->getDefaultShippingAddressId()) {
                $type = 'shipping';
            }
        }

        $this->applyValidationRules($definition, $type, $salesChannelId, $isBusiness);
    }

    private function applyValidationRules(DataValidationDefinition $definition, string $type, ?string $salesChannelId, bool $isBusiness): void
    {
        $configKey = $type === 'shipping'
            ? 'TopdataBetterCheckoutSW6.config.companyValidationShipping'
            : 'TopdataBetterCheckoutSW6.config.companyValidationBilling';

        $setting = $this->systemConfigService->getString($configKey, $salesChannelId);

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

        $constraints = $properties[$fieldName];
        $newConstraints = array_filter($constraints, fn($c) => !($c instanceof $constraintClass));

        if (count($newConstraints) !== count($constraints)) {
            $definition->set($fieldName, ...$newConstraints);
        }
    }

    private function addConstraintIfNotExists(DataValidationDefinition $definition, string $fieldName, \Symfony\Component\Validator\Constraint $newConstraint): void
    {
        $properties = $definition->getProperties();
        $constraints = $properties[$fieldName] ?? [];

        $constraintClass = \get_class($newConstraint);
        foreach ($constraints as $constraint) {
            if ($constraint instanceof $constraintClass) {
                return;
            }
        }

        $definition->add($fieldName, $newConstraint);
    }
}
