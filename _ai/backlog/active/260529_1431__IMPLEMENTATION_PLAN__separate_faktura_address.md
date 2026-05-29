---
filename: "_ai/backlog/active/260529_1431__IMPLEMENTATION_PLAN__separate_faktura_address.md"
title: "Separate Faktura Address and Lock Default Billing Address"
createdAt: 2026-05-29 14:31
updatedAt: 2026-05-29 14:31
status: draft
priority: high
tags: [shopware6, checkout, address-management, erp-integration]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description

In Shopware 6, when a customer registers and selects "Delivery address is same like billing address" (or omits the shipping address), the system points both the default shipping and billing addresses to the exact same database entity (`customer_address`). 
Consequently, if a customer later edits their default shipping address in their account, the billing address is inadvertently changed as well. This creates major issues for ERP integrations that require a strictly isolated, unchanging, and uniquely identifiable billing address (Faktura-Adresse). 

Furthermore, Shopware allows customers to swap their default billing address to any other address in their address book, which disrupts ERP synchronization workflows where a customer must only have one true billing address.

## 2. Executive Summary

This plan extends the existing `TopdataBetterCheckoutSW6` plugin to implement the following behavior:
1. **Address Splitting during Registration**: Intercept the checkout registration route to clone the billing address data if a separate shipping address is not provided. This forces Shopware to create two separate database entries.
2. **Faktura Flagging**: Inject a `customFields.is_faktura = true` flag into the billing address (and `false` into the shipping address) during registration.
3. **Lock Default Billing Address**: Decorate `AbstractSetDefaultBillingAddressRoute` to throw an Access Denied exception, preventing any attempts via API or Storefront to change the default billing address pointer.
4. **UI Adjustments**: Override the Storefront address component template to remove the "Set as default billing address" button, hiding the locked capability from the user.

## 3. Project Environment Details

```text
Plugin Name: TopdataBetterCheckoutSW6
Framework: Shopware 6.7.*
Language: PHP 8.2+, Twig
Component focus: Checkout, Customer Registration, Address Management
Architectural constraints: 
- Must follow SOLID principles.
- Decorators must wrap existing Abstract classes instead of overriding controllers.
- Custom field flags must be written dynamically into the DataBag without requiring database schema migrations.
```

## 4. Implementation Phases

### Phase 1: Intercept Registration to Split Addresses and Set Flags

We will modify the existing `RegisterRouteDecorator` to clone the address bag and assign the custom field flags before passing it to the decorated route.

**[MODIFY]** `src/Core/Checkout/Customer/SalesChannel/RegisterRouteDecorator.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractRegisterRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\CustomerResponse;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Contracts\Translation\TranslatorInterface;

class RegisterRouteDecorator extends AbstractRegisterRoute
{
    public function __construct(
        private readonly AbstractRegisterRoute $decorated,
        private readonly EntityRepository $customerRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator
    ) {
    }

    public function getDecorated(): AbstractRegisterRoute
    {
        return $this->decorated;
    }

    public function register(
        RequestDataBag $data,
        SalesChannelContext $context,
        bool $validateStorefrontUrl = true,
        ?DataValidationDefinition $additionalValidationDefinitions = null
    ): CustomerResponse {
        $isGuest = $data->getBoolean('guest') || !$data->has('password') || empty($data->get('password'));

        if ($isGuest) {
            $this->assertGuestEmailNotRegistered($data, $context);
        }

        $this->enforceAccountType($data, $context, $isGuest);
        
        $this->splitAddressesAndFlagBilling($data);

        return $this->decorated->register($data, $context, $validateStorefrontUrl, $additionalValidationDefinitions);
    }
    
    private function splitAddressesAndFlagBilling(RequestDataBag $data): void
    {
        $billingAddress = $data->get('billingAddress');
        
        if ($billingAddress instanceof RequestDataBag) {
            $customFields = $billingAddress->get('customFields');
            if (!$customFields instanceof RequestDataBag) {
                $customFields = new RequestDataBag();
            }
            $customFields->set('is_faktura', true);
            $billingAddress->set('customFields', $customFields);

            if (!$data->has('shippingAddress')) {
                $shippingAddress = clone $billingAddress;
                
                $shippingCustomFields = clone $customFields;
                $shippingCustomFields->set('is_faktura', false);
                $shippingAddress->set('customFields', $shippingCustomFields);

                $data->set('shippingAddress', $shippingAddress);
            }
        }
    }

    private function enforceAccountType(RequestDataBag $data, SalesChannelContext $context, bool $isGuest): void
    {
        $configKey = $isGuest ? 'guestAccountType' : 'registrationAccountType';
        $defaultSetting = $isGuest ? 'user_choice' : 'always_business';

        $setting = $this->systemConfigService->getString(
            'TopdataBetterCheckoutSW6.config.' . $configKey,
            $context->getSalesChannelId()
        );

        if ($setting === '') {
            $setting = $defaultSetting;
        }

        if ($setting === 'always_private') {
            $data->set('accountType', CustomerEntity::ACCOUNT_TYPE_PRIVATE);
        } elseif ($setting === 'always_business') {
            $data->set('accountType', CustomerEntity::ACCOUNT_TYPE_BUSINESS);
        }

        if ($setting === 'always_private') {
            $data->remove('company');
            $data->remove('vatIds');
            if ($data->has('billingAddress')) {
                $billingAddress = $data->get('billingAddress');
                if ($billingAddress instanceof RequestDataBag) {
                    $billingAddress->remove('company');
                    $billingAddress->remove('vatId');
                }
            }
        }
    }

    private function assertGuestEmailNotRegistered(RequestDataBag $data, SalesChannelContext $context): void
    {
        $email = $data->get('email');
        if (!\is_string($email) || $email === '') {
            return;
        }

        $isBoundToSalesChannel = (bool) $this->systemConfigService->get(
            'core.loginRegistration.isCustomerBoundToSalesChannel',
            $context->getSalesChannelId()
        );

        $criteria = (new Criteria())
            ->setLimit(1)
            ->addFilter(new EqualsFilter('email', $email))
            ->addFilter(new EqualsFilter('guest', false));

        if ($isBoundToSalesChannel) {
            $criteria->addFilter(new EqualsFilter('boundSalesChannelId', $context->getSalesChannelId()));
        }

        $existingCustomer = $this->customerRepository->search($criteria, $context->getContext())->first();
        if (!$existingCustomer instanceof CustomerEntity) {
            return;
        }

        $message = $this->translator->trans('better-checkout.register.emailAlreadyRegistered');

        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null && $request->hasSession()) {
            $session = $request->getSession();
            if (method_exists($session, 'getFlashBag')) {
                $session->getFlashBag()->add('danger', $message);
            }
        }

        $violations = new ConstraintViolationList();
        $violations->add(new ConstraintViolation(
            $message,
            null,
            [],
            null,
            'email',
            $email
        ));

        throw new ConstraintViolationException($violations, $data->all());
    }
}
```

### Phase 2: Lock Default Billing Address in Backend API

**[NEW FILE]** `src/Core/Checkout/Customer/SalesChannel/SetDefaultBillingAddressRouteDecorator.php`
```php
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
```

**[MODIFY]** `src/Resources/config/services.xml`
Add the new decorator service registration:
```xml
        <service id="Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel\SetDefaultBillingAddressRouteDecorator"
                 decorates="Shopware\Core\Checkout\Customer\SalesChannel\SetDefaultBillingAddressRoute"
                 public="true">
            <argument type="service" id=".inner"/>
        </service>
```

### Phase 3: Hide UI Controls in Storefront

**[NEW FILE]** `src/Resources/views/storefront/component/address/address-default.html.twig`
```twig
{% sw_extends '@Storefront/storefront/component/address/address-default.html.twig' %}

{% block component_address_default_billing %}
    {# 
       Intentionally left blank.
       We completely empty this block to remove the 
       "Set as default billing address" form button. 
       The customer can now only set default shipping addresses.
    #}
{% endblock %}
```

### Phase 4: Update Documentation

**[MODIFY]** `README.md`
Add the following text to the "Features" section:
```markdown
- **Isolated Billing & Shipping Addresses**: Automatically forces the creation of separate database entities for billing and shipping addresses during registration, even if the customer selects "same as billing".
- **ERP Integration Flags**: Automatically sets a custom field (`is_faktura`: `true`) on the billing address to easily identify the correct address object for ERP systems.
- **Billing Address Lock**: Prevents customers from switching their default billing address to a different address book entry (via Storefront UI removal and API blocking). Customers can edit their existing billing address without accidentally breaking the shipping address.
```

### Phase 5: Implementation Report

Write the post-implementation report conforming to the following requirements:
```markdown
---
filename: "_ai/backlog/reports/260529_1431__IMPLEMENTATION_REPORT__separate_faktura_address.md"
title: "Report: Separate Faktura Address and Lock Default Billing Address"
createdAt: 2026-05-29 14:31
updatedAt: 2026-05-29 14:31
planFile: "_ai/backlog/active/260529_1431__IMPLEMENTATION_PLAN__separate_faktura_address.md"
project: "topdata-better-checkout-sw6"
status: completed
filesCreated: 2
filesModified: 3
filesDeleted: 0
tags: [shopware6, checkout, address-management, erp-integration]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
Implemented isolated address tracking for billing and shipping addresses during customer registration. Added `is_faktura` custom field flagging for the ERP and locked the billing address from being swapped within the customer's address book.

## 2. Files Changed
... (list detailed files here)

## 3. Key Changes
... (list detailed changes here)

... (fill in the rest of the report template from the instructions)
```

