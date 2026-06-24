<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class AddressValidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly RequestStack $requestStack,
        private readonly Connection $connection,
        private readonly TranslatorInterface $translator,
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
            $this->addZipcodeCountryCheck($subDefinitions['billingAddress']);

            // ---- Handle company field validation based on configuration
            $billingSetting = $this->systemConfigService->getString('TopdataBetterCheckoutSW6.config.companyValidationBilling', $salesChannelId);
            if ($billingSetting === 'optional' || ($billingSetting === 'core' && !$isBusiness)) {
                $this->removeConstraint($definition, 'company', NotBlank::class);
            } elseif ($billingSetting === 'required' && $isBusiness) {
                $this->addConstraintIfNotExists($definition, 'company', new NotBlank());
            }
        }

        // ---- Apply validation rules to shipping address if it exists
        if (isset($subDefinitions['shippingAddress'])) {
            $this->applyValidationRules($subDefinitions['shippingAddress'], 'shipping', $salesChannelId, $isBusiness);
            $this->addZipcodeCountryCheck($subDefinitions['shippingAddress']);
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
        $this->addZipcodeCountryCheck($definition);
    }

    private function applyValidationRules(DataValidationDefinition $definition, string $type, ?string $salesChannelId, bool $isBusiness): void
    {
        // ---- Billing address company field is read-only (change request mechanism) — never in form data
        if ($type === 'billing') {
            $this->removeConstraint($definition, 'company', NotBlank::class);

            return;
        }

        // ---- Get the appropriate configuration key based on address type
        $configKey = 'TopdataBetterCheckoutSW6.config.companyValidationShipping';

        $setting = $this->systemConfigService->getString($configKey, $salesChannelId);

        // ---- Apply validation rules based on configuration setting
        if ($setting === 'optional' || ($setting === 'core' && !$isBusiness)) {
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

    private function addZipcodeCountryCheck(DataValidationDefinition $definition): void
    {
        $definition->add('zipcode', new Callback([$this, 'validateZipcodeCountry']));
    }

    public function validateZipcodeCountry($zipcode, ExecutionContextInterface $context): void
    {
        if (empty($zipcode)) {
            return;
        }

        $object = $context->getObject();
        $countryId = null;

        if ($object instanceof RequestDataBag) {
            $countryId = $object->get('countryId');
        } elseif (\is_array($object)) {
            $countryId = $object['countryId'] ?? null;
        } elseif (\is_object($object)) {
            $countryId = $object->countryId ?? null;
        }

        if (empty($countryId) || !\is_string($countryId) || !Uuid::isValid($countryId)) {
            return;
        }

        $countryIso = $this->getCountryIso($countryId);
        if (empty($countryIso)) {
            return;
        }

        if (!preg_match('/^\d{4}$/', (string)$zipcode)) {
            return;
        }

        $zipInt = (int)$zipcode;
        $isLiechtensteinZip = ($zipInt >= 9480 && $zipInt <= 9499);

        if ($countryIso === 'LI' && !$isLiechtensteinZip) {
            $message = $this->translator->trans('TopdataBetterCheckoutSW6.validation.invalidLiechtensteinZip');
            $context->buildViolation($message)->addViolation();
        }

        if ($countryIso === 'CH' && $isLiechtensteinZip) {
            $message = $this->translator->trans('TopdataBetterCheckoutSW6.validation.swissZipForLiechtenstein');
            $context->buildViolation($message)->addViolation();
        }
    }

    private function getCountryIso(?string $countryId): ?string
    {
        if (!$countryId) {
            return null;
        }

        try {
            $countryIdBytes = Uuid::fromHexToBytes($countryId);
        } catch (\Exception $e) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT iso FROM country WHERE id = :id',
            ['id' => $countryIdBytes]
        ) ?: null;
    }
}