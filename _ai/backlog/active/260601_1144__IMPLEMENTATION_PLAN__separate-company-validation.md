---
filename: "_ai/backlog/active/260601_1144__IMPLEMENTATION_PLAN__separate-company-validation.md"
title: "Implement Separate Company Validation for Billing and Shipping Addresses"
createdAt: 2026-06-01 11:44
updatedAt: 2026-06-01 11:44
status: completed
priority: medium
tags: [checkout, validation, shopware6]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Statement
Currently, Shopware 6 applies a single global validation constraint for the "company name" field for business customers. It does not distinguish between billing (faktura) and shipping addresses. Merchants frequently require the company name on the billing address for tax and invoicing purposes but prefer it to be optional on the shipping address to increase conversion rates or simplify the form for customers shipping to alternative private destinations.

## 2. Executive Summary
This implementation introduces a granular configuration for company name validation. Two new settings are added to the plugin configuration to independently control the required status of the company name for both Billing and Shipping addresses. The storefront form template is updated to dynamically apply frontend validation rules. Furthermore, a backend event subscriber intercepts the `BuildValidationEvent` to enforce these constraints strictly during registration and address editing, ensuring that default core constraints can be circumvented or enforced as configured.

## 3. Project Environment Details
- Target framework: Shopware 6.7.*
- Plugin: `TopdataBetterCheckoutSW6`
- Component: Checkout / Registration / Address Validation

## 4. Implementation Steps & Source Code Changes

### Phase 1: Plugin Configuration
Introduce new settings to control the validation behavior for billing and shipping addresses separately.

```xml
[MODIFY] src/Resources/config/config.xml
```
```xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/platform/trunk/src/Core/System/SystemConfig/Schema/config.xsd">
    <card>
        <title>Account Type Settings</title>
        <title lang="de-DE">Kontotyp-Einstellungen</title>

        <input-field type="single-select">
            <name>guestAccountType</name>
            <options>
                <option>
                    <id>user_choice</id>
                    <name>Let user choose</name>
                    <name lang="de-DE">Benutzer wählen lassen</name>
                </option>
                <option>
                    <id>always_private</id>
                    <name>Always Private</name>
                    <name lang="de-DE">Immer Privat</name>
                </option>
                <option>
                    <id>always_business</id>
                    <name>Always Business</name>
                    <name lang="de-DE">Immer Gewerblich</name>
                </option>
            </options>
            <defaultValue>user_choice</defaultValue>
            <label>Guest Checkout Account Type</label>
            <label lang="de-DE">Gastbestellung - Kontotyp</label>
        </input-field>

        <input-field type="single-select">
            <name>registrationAccountType</name>
            <options>
                <option>
                    <id>user_choice</id>
                    <name>Let user choose</name>
                    <name lang="de-DE">Benutzer wählen lassen</name>
                </option>
                <option>
                    <id>always_private</id>
                    <name>Always Private</name>
                    <name lang="de-DE">Immer Privat</name>
                </option>
                <option>
                    <id>always_business</id>
                    <name>Always Business</name>
                    <name lang="de-DE">Immer Gewerblich</name>
                </option>
            </options>
            <defaultValue>always_business</defaultValue>
            <label>Registration Account Type</label>
            <label lang="de-DE">Registrierung - Kontotyp</label>
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

    <card>
        <title>Company Name Validation</title>
        <title lang="de-DE">Firmenname Validierung</title>

        <input-field type="single-select">
            <name>companyValidationBilling</name>
            <options>
                <option>
                    <id>core</id>
                    <name>Shopware Default</name>
                    <name lang="de-DE">Shopware Standard</name>
                </option>
                <option>
                    <id>required</id>
                    <name>Mandatory (Business)</name>
                    <name lang="de-DE">Pflichtfeld (Gewerblich)</name>
                </option>
                <option>
                    <id>optional</id>
                    <name>Optional</name>
                    <name lang="de-DE">Optional</name>
                </option>
            </options>
            <defaultValue>core</defaultValue>
            <label>Billing Address Company Name</label>
            <label lang="de-DE">Rechnungsadresse Firmenname</label>
        </input-field>

        <input-field type="single-select">
            <name>companyValidationShipping</name>
            <options>
                <option>
                    <id>core</id>
                    <name>Shopware Default</name>
                    <name lang="de-DE">Shopware Standard</name>
                </option>
                <option>
                    <id>required</id>
                    <name>Mandatory (Business)</name>
                    <name lang="de-DE">Pflichtfeld (Gewerblich)</name>
                </option>
                <option>
                    <id>optional</id>
                    <name>Optional</name>
                    <name lang="de-DE">Optional</name>
                </option>
            </options>
            <defaultValue>optional</defaultValue>
            <label>Shipping Address Company Name</label>
            <label lang="de-DE">Lieferadresse Firmenname</label>
        </input-field>
    </card>
</config>
```

### Phase 2: Frontend Template Adjustments
Adjust the frontend template so the HTML5 `required` attributes sync automatically with the new settings.

```twig
[MODIFY] src/Resources/views/storefront/component/address/address-personal-company.html.twig
```
```twig
{% sw_extends '@Storefront/storefront/component/address/address-personal-company.html.twig' %}

{% block component_account_register_company_fields %}
    {% set isRegistrationRoute = app.request.attributes.get('_route') in ['frontend.checkout.register.page', 'frontend.account.register.page', 'frontend.account.login.page'] %}

    {% if not isRegistrationRoute %}
        {{ parent() }}
    {% else %}
        {% set checkoutType = app.request.query.get('checkoutType')
            ?: app.request.request.get('checkoutType')
            ?: (data is defined and data is not null and data.all is defined ? data.get('checkoutType') : null) %}

        {% set guestSetting = config('TopdataBetterCheckoutSW6.config.guestAccountType') ?: 'user_choice' %}
        {% set registerSetting = config('TopdataBetterCheckoutSW6.config.registrationAccountType') ?: 'always_business' %}

        {% set accountTypeSetting = checkoutType == 'guest' ? guestSetting : registerSetting %}

        {% set forceBusinessCompanyFieldsVisible = (accountTypeSetting == 'always_business') %}
        {% set forcePrivateCompanyFieldsHidden = (accountTypeSetting == 'always_private') %}
        {% set accountTypeRequired = config('core.loginRegistration.showAccountTypeSelection') %}

        {% if not forcePrivateCompanyFieldsHidden %}
            {% if accountTypeRequired or prefix == 'address' or prefix == 'shippingAddress' or hasSelectedBusiness or forceBusinessCompanyFieldsVisible %}
                <div class="{% if forceBusinessCompanyFieldsVisible %}address-contact-type-company d-block{% elseif hasSelectedBusiness %}address-contact-type-company{% elseif prefix == 'address' %}js-field-toggle-contact-type-company d-block{% else %}js-field-toggle-contact-type-company{% if customToggleTarget %}-{{ prefix }}{% endif %} d-none{% endif %}">
                    {% block component_address_form_company_fields %}
                        <div class="row g-2">
                            {% block component_address_form_company_name %}
                                {% if formViolations.getViolations('/company') is not empty %}
                                    {% set violationPath = '/company' %}
                                {% elseif formViolations.getViolations("/#{prefix}/company") is not empty %}
                                    {% set violationPath = "/#{prefix}/company" %}
                                {% endif %}

                                {% block component_address_form_company_name_input %}
                                    {% set isShipping = (prefix == 'shippingAddress') %}
                                    {% set companyValidationSetting = isShipping ? config('TopdataBetterCheckoutSW6.config.companyValidationShipping') : config('TopdataBetterCheckoutSW6.config.companyValidationBilling') %}
                                    
                                    {% if not companyValidationSetting %}
                                        {% set companyValidationSetting = isShipping ? 'optional' : 'core' %}
                                    {% endif %}

                                    {% set validationRules = 'required' %}
                                    {% if companyValidationSetting == 'optional' %}
                                        {% set validationRules = '' %}
                                    {% elseif companyValidationSetting == 'required' %}
                                        {% set validationRules = 'required' %}
                                    {% endif %}

                                    {% sw_include '@Storefront/storefront/component/form/form-input.html.twig' with {
                                        label: 'address.companyNameLabel'|trans|sw_sanitize,
                                        id: idPrefix ~ prefix ~ 'company',
                                        name: prefix ? prefix ~ '[company]' : 'company',
                                        value: address.get('company'),
                                        autocomplete: 'section-personal organization',
                                        violationPath: violationPath,
                                        validationRules: validationRules,
                                        additionalClass: 'col-12',
                                    } %}
                                {% endblock %}
                            {% endblock %}
                        </div>
                        <div class="row g-2">
                            {% block component_address_form_company_department %}
                                {% if formViolations.getViolations('/department') is not empty %}
                                    {% set violationPath = '/department' %}
                                {% elseif formViolations.getViolations("/#{prefix}/department") is not empty %}
                                    {% set violationPath = "/#{prefix}/department" %}
                                {% endif %}

                                {% block component_address_form_company_department_input %}
                                    {% sw_include '@Storefront/storefront/component/form/form-input.html.twig' with {
                                        label: 'address.companyDepartmentLabel'|trans|sw_sanitize,
                                        id: idPrefix ~ prefix ~ 'department',
                                        name: prefix ? prefix ~ '[department]' : 'department',
                                        value: address.get('department'),
                                        violationPath: violationPath,
                                        additionalClass: 'col-md-6',
                                    } %}
                                {% endblock %}
                            {% endblock %}

                            {% block component_address_form_company_vatId %}
                                {% if prefix != 'shippingAddress' %}
                                    {% sw_include '@Storefront/storefront/component/address/address-personal-vat-id.html.twig' with {
                                        vatIds: data.get('vatIds')
                                    } %}
                                {% endif %}
                            {% endblock %}
                        </div>
                    {% endblock %}
                </div>
            {% endif %}
        {% endif %}
    {% endif %}
{% endblock %}
```

### Phase 3: Backend Validation Event Subscriber
Create an event subscriber to dynamically rewrite Shopware's `DataValidationDefinition` instances based on the new configurations. This ensures data integrity by rejecting validation for business customers without required company names, and overriding Shopware defaults when it's made optional.

```php
[NEW FILE] src/Core/Checkout/Customer/Subscriber/AddressValidationSubscriber.php
```
```php
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

        // Validate billing address
        if (isset($subDefinitions['billingAddress'])) {
            $this->applyValidationRules($subDefinitions['billingAddress'], 'billing', $salesChannelId, $isBusiness);
            
            // Apply billing rule to root company field to prevent root-level constraint from blocking
            $billingSetting = $this->systemConfigService->getString('TopdataBetterCheckoutSW6.config.companyValidationBilling', $salesChannelId);
            if ($billingSetting === 'optional') {
                $this->removeConstraint($definition, 'company', NotBlank::class);
            } elseif ($billingSetting === 'required' && $isBusiness) {
                $this->addConstraintIfNotExists($definition, 'company', new NotBlank());
            }
        }

        // Validate shipping address
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

        $type = 'billing'; // Default fallback
        $customFields = $data->get('customFields');

        // Determine if it is a shipping address from custom fields or default customer settings
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
                return; // Already exists
            }
        }

        $definition->add($fieldName, $newConstraint);
    }
}
```

### Phase 4: Register the new Subscriber in DI container
```xml
[MODIFY] src/Resources/config/services.xml
```
```xml
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

        <service id="Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber\AddressValidationSubscriber">
            <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService"/>
            <tag name="kernel.event_subscriber"/>
        </service>

        <service id="Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel\RegisterRouteDecorator"
                 decorates="Shopware\Core\Checkout\Customer\SalesChannel\RegisterRoute"
                 public="true">
            <argument type="service" id=".inner"/>
            <argument type="service" id="customer.repository"/>
            <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService"/>
            <argument type="service" id="Symfony\Component\HttpFoundation\RequestStack"/>
            <argument type="service" id="translator"/>
        </service>

        <service id="Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel\SetDefaultBillingAddressRouteDecorator"
                 decorates="Shopware\Core\Checkout\Customer\SalesChannel\SetDefaultBillingAddressRoute"
                 public="true">
            <argument type="service" id=".inner"/>
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

### Phase 5: Update Documentation

```markdown
[MODIFY] README.md
```
```markdown
# Topdata Better Checkout SW6

![Plugin Icon](src/Resources/config/plugin.png)

Overview

Topdata Better Checkout SW6 improves the Shopware storefront checkout by replacing the default single-entry flow with a lightweight, native 3-box selection screen that lets customers choose between:
- Register as a new customer
- Login with an existing account
- Continue as guest

The plugin is intentionally small and implemented as Storefront template overrides and storefront snippets so it integrates cleanly with Shopware 6.7.

Features

- Shows a 3-box selection on the checkout address page when no explicit choice was made.
- Preserves the normal checkout flow once a choice is selected (uses a `checkoutType` request parameter).
- Hides or forces the guest/registration checkbox depending on the chosen flow to avoid confusion.
- Configurable account type for guest checkout (user choice / always private / always business).
- Configurable account type for registration (user choice / always private / always business, defaults to always business).
- Backend enforcement of account type via `RegisterRoute` decoration ensures the correct value is persisted regardless of template quirks.
- Blocks registered guest checkout with an email already used by a full customer account.
- Payment method restrictions for guest customers based on account type (private vs business).
- **Isolated Billing & Shipping Addresses**: Automatically forces the creation of separate database entities for billing and shipping addresses during registration, even if the customer selects "same as billing".
- **ERP Integration Flags**: Automatically sets a custom field (`is_faktura`: `true`) on the billing address to easily identify the correct address object for ERP systems.
- **Billing Address Lock**: Prevents customers from switching their default billing address to a different address book entry (via Storefront UI removal and API blocking). Customers can edit their existing billing address without accidentally breaking the shipping address.
- **Granular Company Name Validation**: Allows granular control to make the company name input mandatory or optional independently for billing and shipping addresses.
- Adds storefront snippets for English (en-GB) and German (de-DE) to make the boxes translatable.

Configuration

In the Shopware Administration under the plugin settings:
- **Guest Checkout Account Type** — controls the `accountType` for guest orders (`user_choice` / `always_private` / `always_business`, default: user choice).
- **Registration Account Type** — controls the `accountType` for new customer registrations (`user_choice` / `always_private` / `always_business`, default: always business).
- **Blocked payment methods for Private Guest Checkouts** — payment methods hidden for guests with a private account type.
- **Blocked payment methods for Business Guest Checkouts** — payment methods hidden for guests with a business account type.
- **Billing Address Company Name** — controls if the company name is mandatory for business customers on the billing address (Shopware Default / Mandatory / Optional).
- **Shipping Address Company Name** — controls if the company name is mandatory for business customers on the shipping address (Shopware Default / Mandatory / Optional, default: Optional).

How it works (technical summary)

- Template overrides are provided under `src/Resources/views/storefront/page/checkout/address` and `src/Resources/views/storefront/component`.
- The main selection UI is injected into the checkout address index template. When a user clicks one of the boxes the plugin appends `?checkoutType=register` or `?checkoutType=guest` to the register route so the chosen flow is preserved.
- The registration form receives a hidden `checkoutType` input when a choice was made, and the guest-registration checkbox is enforced/hidden by the register template override.
- The account type field on registration pages reads the plugin config: for `always_business` or `always_private` a hidden input is rendered; for `user_choice` the native Shopware selector is displayed.
- `RegisterRouteDecorator` enforces the configured account type on the `RequestDataBag` before passing it to the core `RegisterRoute`, ensuring the correct value is always persisted to the database regardless of what the template or upstream controller sends.
- `PaymentMethodRouteDecorator` filters out blocked payment methods for guest customers based on their account type.
- `AddressValidationSubscriber` listens to `framework.validation.customer.*` and `framework.validation.address.*` events to dynamically rewrite the backend validation definition fields (adding or removing `NotBlank` constraints based on config).
- Template overrides are scoped to registration routes only (`frontend.checkout.register.page`, `frontend.account.register.page`) to avoid interfering with standard address editing.

Usage / Examples

- Open the checkout address page (normally `/checkout/address`). If no flow is selected the 3-box chooser appears.
- Clicking "Create an account" navigates to the register page with `?checkoutType=register`.
- Clicking "Order as guest" navigates to the register page with `?checkoutType=guest` and submits the form as guest.

Snippets (translations)

- English: `src/Resources/snippet/en_GB/storefront.en-GB.json`
- German:  `src/Resources/snippet/de_DE/storefront.de-DE.json`

Installation

1. Copy or upload the plugin folder to your Shopware `custom/plugins` directory (or install via your preferred method).
2. Install and activate the plugin in the Shopware Administration.
3. Review and adjust the plugin configuration under the plugin settings.
4. Clear cache / rebuild storefront if necessary.

Requirements

- Shopware 6.7.x

Support

For issues or questions, open an issue against this repository or contact TopData Software GmbH: https://www.topdata.de

License

MIT
```

<br>

---

<br>

```markdown
---
filename: "_ai/backlog/reports/260601_1144__IMPLEMENTATION_REPORT__separate-company-validation.md"
title: "Report: Implement Separate Company Validation for Billing and Shipping Addresses"
createdAt: 2026-06-01 11:44
updatedAt: 2026-06-01 11:44
planFile: "_ai/backlog/active/260601_1144__IMPLEMENTATION_PLAN__separate-company-validation.md"
project: "TopdataBetterCheckoutSW6"
status: completed
filesCreated: 1
filesModified: 4
filesDeleted: 0
tags: [checkout, validation, shopware6]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
The requested feature has been successfully implemented. A new event subscriber alongside configuration mappings and frontend template adjustments now accurately enforces (or removes) the necessity of the company name field dynamically for distinct billing and shipping addresses.

## 2. Files Changed
- **New Files**:
    - `src/Core/Checkout/Customer/Subscriber/AddressValidationSubscriber.php`: Hooks into core validation events (`framework.validation.customer.*` and `framework.validation.address.*`) to dynamically inject or remove `NotBlank` rules for company inputs depending on the configuration and address context.
- **Modified Files**:
    - `src/Resources/config/config.xml`: Added configuration dropdowns to select validation behavior for `Billing` and `Shipping` company names.
    - `src/Resources/views/storefront/component/address/address-personal-company.html.twig`: Fetched the new setting depending on the view prefix context and altered the `validationRules` for HTML5 frontend validation dynamically.
    - `src/Resources/config/services.xml`: Registered the new EventSubscriber in the Symfony service container.
    - `README.md`: Updated to accurately reflect the new administrative configurations and architectural capabilities.

## 3. Key Changes
- Allowed merchants granular control to deviate from strict, global Shopware 6 `NotBlank` constraint logic.
- The system checks custom fields (`is_faktura`) created previously by the `RegisterRouteDecorator` to distinguish billing vs. shipping contexts deep within the validation validation phase natively.
- Added strict failsafe rules to ensure `NotBlank` is **only** added for business account contexts.
- Both Frontend (HTML-5 required attributes) and Backend (Symfony DataValidation constraints) are kept harmonized to prevent user frustration/desync.

## 4. Deviations from Plan
No significant deviations occurred. The approach of overriding the `DataValidationDefinition` constraint properties was successfully drafted out natively through Symfony event structures instead of trying to manipulate route parameters explicitly, which scales correctly for Account page edits as well as registrations.

## 5. Technical Decisions
- **Modifying Core Definitions via Event Subscriptions**: Instead of passing temporary custom properties to the main decorators or throwing errors post-validation, subscribing to the underlying `BuildValidationEvent` provides surgical precision. We can fetch `$definition->getProperties()`, filter out `NotBlank::class`, and unpack the modified constraints back using `$definition->set()`.
- **Handling Main Customer DataBag Properties**: `CustomerValidationFactory` sets the constraint simultaneously on `billingAddress.company` and `company` inside the root definition. The subscriber checks for this and replicates the billing logic to the root `company` property to prevent the registration API layer from blocking on the main bag validation.

## 6. Testing Notes
To test these changes:
1. Re-build the storefront (`bin/console theme:compile`).
2. Navigate to Shopware admin > Plugins > Settings > Topdata Better Checkout SW6.
3. Configure **Billing Address Company Name** to `Mandatory` and **Shipping Address Company Name** to `Optional`.
4. Proceed to Storefront checkout or registration, select a business account.
5. Provide a company name for billing, but leave it empty for the different shipping address. The registration should succeed immediately.
6. Reverse the configuration rules and attempt again. Omitting the shipping company should now yield a `ConstraintViolationException` mapped accurately onto the form.

## 7. Documentation Updates
New bullet points have been added to the plugin's `README.md` defining the new configuration inputs, describing exactly how granular company validations can now be utilized.
```
