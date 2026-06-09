<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\SwissPostApiService;

class AddressValidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly RequestStack $requestStack,
        private readonly SwissPostApiService $swissPostApiService,
        private readonly EntityRepository $countryRepository,
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
            $billingData = $data->get('billingAddress');
            if ($billingData instanceof DataBag) {
                $this->applySwissPostValidation($subDefinitions['billingAddress'], $billingData, $salesChannelId);
            }

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
            $shippingData = $data->get('shippingAddress');
            if ($shippingData instanceof DataBag) {
                $this->applySwissPostValidation($subDefinitions['shippingAddress'], $shippingData, $salesChannelId);
            }
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
        $this->applySwissPostValidation($definition, $data, $salesChannelId);
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

    private function applySwissPostValidation(DataValidationDefinition $definition, DataBag $data, ?string $salesChannelId): void
    {
        if (!$this->swissPostApiService->isValidationEnabled($salesChannelId)) {
            return;
        }

        $countryId = $data->get('countryId') ?? $data->get('country_id');
        if (!$countryId) {
            return;
        }

        try {
            $criteria = new Criteria([$countryId]);
            $country = $this->countryRepository->search($criteria, Context::createDefaultContext())->first();
            if (!$country) {
                return;
            }
            $iso = $country->getIso();
        } catch (\Throwable) {
            return;
        }

        if (!\in_array($iso, ['CH', 'LI'], true)) {
            return;
        }

        $address = [
            'firstName' => $data->get('firstName') ?? $data->get('firstname') ?? '',
            'lastName' => $data->get('lastName') ?? $data->get('lastname') ?? '',
            'street' => $data->get('street') ?? '',
            'zipcode' => $data->get('zipcode') ?? '',
            'city' => $data->get('city') ?? '',
            'countryCode' => $iso,
        ];

        $apiService = $this->swissPostApiService;
        $addressData = $address;

        $definition->add('zipcode', new Callback([
            'callback' => function ($value, ExecutionContextInterface $context, $payload) use ($apiService, $addressData, $salesChannelId): void {
                if (empty($value)) {
                    return;
                }

                $result = $apiService->validateAddress($addressData, $salesChannelId);

                if ($result['success'] === true) {
                    return;
                }

                $context->buildViolation('better-checkout.swissPostValidationFailed')
                    ->setTranslationDomain('topdata-better-checkout')
                    ->addViolation();
            },
        ]));
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