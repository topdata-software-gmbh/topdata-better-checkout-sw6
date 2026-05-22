---
filename: "_ai/backlog/archive/260522_1200__IMPLEMENTATION_PLAN__better_checkout_extensions.md"
title: "Implementation Plan: Better Checkout Extension for Shopware 6.7"
createdAt: 2026-05-22 12:00
updatedAt: 2026-05-22 12:00
status: completed
priority: high
tags: [shopware6, checkout, payment-restrictions, validation]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## Problem Description
The current checkout plugin needs to be extended to support custom validation rules, account type constraints, and payment method restrictions based on customer configuration during guest checkout:
1. **Normal Registration**: The customer account type must always be "business" and the dropdown for selecting private/business account type must be hidden.
2. **Guest Registration**: The account type selection dropdown (private/business) must remain visible in the registration form.
3. **Guest Email Check**: When a guest attempts to register but a non-guest registered customer with the same email already exists in the system, the registration should be blocked and a clear flash message shown requesting them to log in.
4. **Dynamic Payment Methods**: Payment methods must be dynamically filtered during guest checkout. "Kauf auf Rechnung" (Invoice) or other configured payment methods should be blocked for private guests but allowed for business guests, using a fully configurable system config setting rather than hardcoded logic.

## Executive Summary of Solution
To address these issues safely and in alignment with SOLID principles:
1. **Storefront Template Overrides**: We will update the `address-personal.html.twig` block override. When the `checkoutType` is `guest`, the standard private/business selection dropdown will render. For any other checkout types or general registrations, it will render a hidden input field forcing `ACCOUNT_TYPE_BUSINESS` and hiding the dropdown entirely.
2. **AbstractRegisterRoute Service Decoration**: We will decorate the `RegisterRoute` service. Before processing a customer registration, we will check if it is a guest checkout. If a non-guest customer with the exact same email address already exists (considering Sales Channel boundary settings), we will append a danger alert flash message to the request session and raise a standard `ConstraintViolationException` on the email field. This stops registration and returns the user to the form displaying the violation message.
3. **AbstractPaymentMethodRoute Service Decoration**: We will decorate the `PaymentMethodRoute` service. If the current active context belongs to a guest customer, we will retrieve the list of blocked payment methods for private guests or business guests from the system configuration. Any matching payment methods will be dynamically removed from the active selection collection before rendering.
4. **Configuration Panel**: We will expand `config.xml` to include two multi-entity selection components allowing store administrators to assign blocked payment methods per sales channel.

## Project Environment Details
- PHP Version: 8.2+
- Shopware Version: 6.7.x
- Target Plugin: `TopdataBetterCheckoutSW6`

---

## Phases of Implementation

### Phase 1: Configuration Panel Expansion
We will add configuration fields to `config.xml` using Shopware's standard multi-select entity components to assign blocked payment methods.

```xml
<!-- [MODIFY] src/Resources/config/config.xml -->
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/platform/trunk/src/Core/System/SystemConfig/Schema/config.xsd">
    <card>
        <title>Basic Configuration</title>
        <title lang="de-DE">Grundeinstellungen</title>
        
        <input-field>
            <name>example</name>
            <label>Example Configuration</label>
            <label lang="de-DE">Beispiel Konfiguration</label>
        </input-field>
    </card>
    
    <card>
        <title>Payment Restrictions for Guest Checkouts</title>
        <title lang="de-DE">Zahlungseinschränkungen für Gastbestellungen</title>
        
        <component name="sw-entity-multi-id-select">
            <name>blockedPrivateGuestPayments</name>
            <entity>payment_method</entity>
            <label>Blocked payment methods for Private Guest Checkouts</label>
            <label lang="de-DE">Gesperrte Bezahlmethoden für private Gastbestellungen</label>
        </component>

        <component name="sw-entity-multi-id-select">
            <name>blockedBusinessGuestPayments</name>
            <entity>payment_method</entity>
            <label>Blocked payment methods for Business Guest Checkouts</label>
            <label lang="de-DE">Gesperrte Bezahlmethoden für gewerbliche Gastbestellungen</label>
        </component>
    </card>
</config>
```

### Phase 2: Translation Snippets Addition
We will append translation keys to support the new validation alert message.

```json
// [MODIFY] src/Resources/snippet/de_DE/storefront.de-DE.json
{
    "better-checkout": {
        "box": {
            "registerTitle": "Ich möchte mich als neuer Kunde registrieren",
            "registerText": "Melden Sie sich einmal an und profitieren Sie für lange Zeit.",
            "registerBtn": "Ein Konto erstellen",
            "loginTitle": "Ich habe bereits ein Konto",
            "guestTitle": "Ich möchte nur als Gast bestellen",
            "guestText": "Der schnelle Weg zu Ihrer Bestellung ohne Kundenkonto",
            "guestBtn": "Bestellung als Gast"
        },
        "register": {
            "emailAlreadyRegistered": "Sie sind bereits als Kunde registriert - bitte loggen Sie sich ein."
        }
    }
}
```

```json
// [MODIFY] src/Resources/snippet/en_GB/storefront.en-GB.json
{
    "better-checkout": {
        "box": {
            "registerTitle": "I want to register as a new customer",
            "registerText": "Sign up once and benefit for a long time.",
            "registerBtn": "Create an account",
            "loginTitle": "I already have an account",
            "guestTitle": "I only want to order as a guest",
            "guestText": "The quick way to your order without a customer account",
            "guestBtn": "Order as guest"
        },
        "register": {
            "emailAlreadyRegistered": "You are already registered as a customer - please log in."
        }
    }
}
```

### Phase 3: Core Logic decoration (Email Verification)
We will decorate the `RegisterRoute` class to check if a registered customer exists with the email address of the incoming guest registration request.

```php
// [NEW FILE] src/Core/Checkout/Customer/SalesChannel/RegisterRouteDecorator.php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractRegisterRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\CustomerResponse;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Contracts\Translation\TranslatorInterface;

class RegisterRouteDecorator extends AbstractRegisterRoute
{
    private AbstractRegisterRoute $decorated;
    private EntityRepository $customerRepository;
    private SystemConfigService $systemConfigService;
    private RequestStack $requestStack;
    private TranslatorInterface $translator;

    public function __construct(
        AbstractRegisterRoute $decorated,
        EntityRepository $customerRepository,
        SystemConfigService $systemConfigService,
        RequestStack $requestStack,
        TranslatorInterface $translator
    ) {
        $this->decorated = $decorated;
        $this->customerRepository = $customerRepository;
        $this->systemConfigService = $systemConfigService;
        $this->requestStack = $requestStack;
        $this->translator = $translator;
    }

    public function getDecorated(): AbstractRegisterRoute
    {
        return $this->decorated;
    }

    public function register(
        RequestDataBag $data,
        SalesChannelContext $context,
        bool $validateStorefrontUrl = true,
        ?RequestDataBag $additionalValidationDefinitions = null
    ): CustomerResponse {
        $isGuest = $data->getBoolean('guest') || !$data->has('password') || empty($data->get('password'));

        if ($isGuest) {
            $email = $data->get('email');
            if (!empty($email) && \is_string($email)) {
                $isBound = $this->systemConfigService->get(
                    'core.loginRegistration.isCustomerBoundToSalesChannel',
                    $context->getSalesChannelId()
                );

                $criteria = new Criteria();
                $criteria->addFilter(new EqualsFilter('email', $email));
                $criteria->addFilter(new EqualsFilter('guest', false));
                if ($isBound) {
                    $criteria->addFilter(new EqualsFilter('boundSalesChannelId', $context->getSalesChannelId()));
                }

                $existingCustomer = $this->customerRepository->search($criteria, $context->getContext())->first();

                if ($existingCustomer instanceof CustomerEntity) {
                    $request = $this->requestStack->getCurrentRequest();
                    $message = $this->translator->trans('better-checkout.register.emailAlreadyRegistered');
                    
                    if ($request && $request->hasSession()) {
                        $request->getSession()->getFlashBag()->add('danger', $message);
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
        }

        return $this->decorated->register($data, $context, $validateStorefrontUrl, $additionalValidationDefinitions);
    }
}
```

### Phase 4: Dynamic Payment Filtering Logic
We will decorate the `PaymentMethodRoute` service to remove payment methods restricted by the plugin configuration for guest accounts.

```php
// [NEW FILE] src/Core/Checkout/Payment/SalesChannel/PaymentMethodRouteDecorator.php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Payment\SalesChannel;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

class PaymentMethodRouteDecorator extends AbstractPaymentMethodRoute
{
    private AbstractPaymentMethodRoute $decorated;
    private SystemConfigService $systemConfigService;

    public function __construct(
        AbstractPaymentMethodRoute $decorated,
        SystemConfigService $systemConfigService
    ) {
        $this->decorated = $decorated;
        $this->systemConfigService = $systemConfigService;
    }

    public function getDecorated(): AbstractPaymentMethodRoute
    {
        return $this->decorated;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): PaymentMethodRouteResponse
    {
        $response = $this->decorated->load($request, $context, $criteria);

        $customer = $context->getCustomer();
        if (!$customer) {
            return $response;
        }

        if (!$customer->getGuest()) {
            return $response;
        }

        $salesChannelId = $context->getSalesChannelId();
        $accountType = $customer->getAccountType();

        $blockedIds = [];
        if ($accountType === CustomerEntity::ACCOUNT_TYPE_PRIVATE) {
            $blockedIds = $this->systemConfigService->get(
                'TopdataBetterCheckoutSW6.config.blockedPrivateGuestPayments',
                $salesChannelId
            ) ?: [];
        } elseif ($accountType === CustomerEntity::ACCOUNT_TYPE_BUSINESS) {
            $blockedIds = $this->systemConfigService->get(
                'TopdataBetterCheckoutSW6.config.blockedBusinessGuestPayments',
                $salesChannelId
            ) ?: [];
        }

        if (!empty($blockedIds)) {
            $paymentMethods = $response->getPaymentMethods();
            foreach ($paymentMethods as $key => $paymentMethod) {
                if (\in_array($paymentMethod->getId(), $blockedIds, true)) {
                    $paymentMethods->remove($key);
                }
            }
        }

        return $response;
    }
}
```

### Phase 5: Template Overrides
We will rewrite `address-personal.html.twig` to toggle visibility of the account type dropdown based on whether it is a guest checkout or a normal registration.

```twig
{# [MODIFY] src/Resources/views/storefront/component/address/address-personal.html.twig #}
{% sw_extends '@Storefront/storefront/component/address/address-personal.html.twig' %}

{% block component_address_personal_account_type %}
    {% set checkoutType = app.request.query.get('checkoutType') ?: app.request.request.get('checkoutType') %}
    
    {% if checkoutType == 'guest' %}
        {# Allow Private/Business selection during Guest Checkout #}
        {{ parent() }}
    {% else %}
        {# Force Business Account Type on standard registration / non-guest checkout registration #}
        <input type="hidden" name="{% if prefix %}{{ prefix }}[accountType]{% else %}accountType{% endif %}" value="{{ constant('Shopware\\Core\\Checkout\\Customer\\CustomerEntity::ACCOUNT_TYPE_BUSINESS') }}">
    {% endif %}
{% endblock %}
```

### Phase 6: Service Registration
We will register the new decorators in the dependency injection container.

```xml
<!-- [MODIFY] src/Resources/config/services.xml -->
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>
        <service id="Topdata\TopdataBetterCheckoutSW6\Controller\StorefrontExampleController" public="true">
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
            <call method="setTwig">
                <argument type="service" id="twig"/>
            </call>
        </service>

        <service id="Topdata\TopdataBetterCheckoutSW6\Resources\snippet\de_DE\SnippetFile_de_DE">
            <tag name="shopware.snippet.file"/>
        </service>

        <service id="Topdata\TopdataBetterCheckoutSW6\Resources\snippet\en_GB\SnippetFile_en_GB">
            <tag name="shopware.snippet.file"/>
        </service>

        <service id="Topdata\TopdataBetterCheckoutSW6\Controller\AdminApiExampleController" public="true">
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>

        <!-- Decorators -->
        <service id="Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel\RegisterRouteDecorator" 
                 decorates="Shopware\Core\Checkout\Customer\SalesChannel\RegisterRoute" 
                 public="true">
            <argument type="service" id=".inner"/>
            <argument type="service" id="customer.repository"/>
            <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService"/>
            <argument type="service" id="Symfony\Component\HttpFoundation\RequestStack"/>
            <argument type="service" id="translator"/>
        </service>

        <service id="Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteDecorator" 
                 decorates="Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRoute" 
                 public="true">
            <argument type="service" id=".inner"/>
            <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService"/>
        </service>
    </services>
</container>
```

---

## Phase 7: Verification & Testing Plan
1. **Normal Registration Verification**:
   - Access the checkout page and click "Create an account" (normal registration).
   - Verify that the Business/Private account selection dropdown is missing.
   - Fill in the registration form and submit. Verify in database/administration that the created customer account type is `business`.
2. **Guest Registration Verification**:
   - Access the checkout page and click "Order as guest".
   - Verify that the Private/Business selection dropdown is present on the form.
3. **Guest Email Check Verification**:
   - Register a normal account with email `test-registered@example.com`.
   - Start a guest checkout and enter `test-registered@example.com` in the guest registration form.
   - Click submit and verify that registration is blocked, an alert banner is displayed saying: *"Sie sind bereits als Kunde registriert - bitte loggen Sie sich ein."*, and the email input field highlights as invalid.
4. **Dynamic Payment Filtering Verification**:
   - In the administration system config under the plugin settings, assign a payment method (e.g., "Kauf auf Rechnung" / Invoice) to the blocked list for Private Guest Checkouts.
   - Start a guest checkout selecting "Private". Verify that Invoice is not available in the payment options.
   - Start a guest checkout selecting "Business". Verify that Invoice is available.

---

## Phase 8: Implementation Report
The final step will write an implementation report to:
`_ai/backlog/reports/260522_1200__IMPLEMENTATION_REPORT__better_checkout_extensions.md`
```

