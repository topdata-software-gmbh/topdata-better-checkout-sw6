---
filename: "_ai/backlog/active/260526_2330__IMPLEMENTATION_PLAN__fix-missing-checkout-fields.md"
title: "Fix missing password and company fields on account registration"
createdAt: 2026-05-26 23:30
updatedAt: 2026-05-26 23:30
status: draft
priority: high
tags: [checkout, registration, bugfix]
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---

# Problem Statement
The Shopware 6 plugin `TopdataBetterCheckoutSW6` overrides the registration forms during the checkout process and standard registration. Currently, when attempting to create a new account (e.g., at `/account/register` or checkout registration), the `Password` and `Company` fields are missing. Additionally, while the standard Shopware behavior includes a "Kontotyp" (Account type) dropdown, the requirement is to hide it entirely and force all non-guest registrations to be "business accounts" (`Gewerblich`).

# Executive Summary
The missing fields are caused by incorrect DOM manipulation and JS toggling setup in the plugin's Twig templates:
1. **Password Field**: The plugin was incorrectly leaving the hidden "guest" checkbox as `checked="checked"` during standard registration. Shopware's JavaScript toggle evaluates this state and intentionally hides the password field as if the user was a guest. We will fix the toggle logic to correctly uncheck the dummy checkbox for normal registrations so the password field shows.
2. **Company Fields**: The plugin correctly forces the business account type but failed to remove the `.d-none` CSS class from the company fields wrapper. Because the account type dropdown was intentionally hidden, the JS toggle never fired to make the company fields visible. We will update the Twig template to explicitly assign `d-block` for company fields on non-guest registrations.

# Project Environment
- **Framework:** Shopware 6.7.x
- **Plugin:** TopdataBetterCheckoutSW6
- **Key Files:**
  - `src/Resources/views/storefront/page/checkout/address/register.html.twig`
  - `src/Resources/views/storefront/component/address/address-personal-company.html.twig`

# Implementation Plan

## Phase 1: Fix Password Field Visibility
**Objective**: Correct the JS form toggle for the guest checkbox so the password field is visible when registering a new customer account.

Modify `src/Resources/views/storefront/page/checkout/address/register.html.twig`.
- For `checkoutType == 'register'`, the hidden checkbox mimicking the guest toggle must be UNCHECKED (removed `checked="checked"`) so that Shopware's JS toggle accurately evaluates `false === "false"` to `true`, which forces the password block to become visible.
- Ensure the `name` attribute conforms to Shopware defaults (`guest`).
- For `checkoutType == 'guest'`, the hidden checkbox must be properly `checked="checked"` to ensure the password block remains hidden.

```twig
[MODIFY] src/Resources/views/storefront/page/checkout/address/register.html.twig
{% sw_extends '@Storefront/storefront/page/checkout/address/register.html.twig' %}

{% block page_checkout_register_personal_guest %}
    {% set checkoutType = app.request.query.get('checkoutType')
        ?: app.request.request.get('checkoutType')
        ?: (data is defined and data is not null and data.all is defined ? data.get('checkoutType') : null) %}
    
    {% if checkoutType == 'guest' %}
        {# Enforce Guest and hide the checkbox to avoid confusion while allowing JS toggles to execute #}
        <div class="d-none">
            <input type="checkbox"
                   name="guest"
                   value="true"
                   id="personalGuest"
                   checked="checked"
                   data-form-field-toggle="true"
                   data-form-field-toggle-target=".js-form-field-toggle-guest-mode"
                   data-form-field-toggle-value="false">
            <input type="hidden" name="guest" value="1">
        </div>
        {# Fail-safe style to prevent layout shifts/flicker of password fields on load #}
        <style>
            .js-form-field-toggle-guest-mode {
                display: none !important;
            }
        </style>
    {% elseif checkoutType == 'register' %}
        {# Enforce normal registration and hide the checkbox #}
        <div class="d-none">
            <input type="checkbox"
                   name="guest"
                   value="true"
                   id="personalGuest"
                   data-form-field-toggle="true"
                   data-form-field-toggle-target=".js-form-field-toggle-guest-mode"
                   data-form-field-toggle-value="false">
        </div>
    {% else %}
        {{ parent() }}
    {% endif %}
{% endblock %}
```

## Phase 2: Fix Company Fields Visibility
**Objective**: Ensure the company fields are unconditionally visible for business registrations without relying on a hidden Account Type dropdown to trigger JS toggles.

Modify `src/Resources/views/storefront/component/address/address-personal-company.html.twig`.
- Simplify the `forceBusinessCompanyFieldsVisible` variable to broadly check `checkoutType != 'guest'`. (Removing the `prefix == 'shippingAddress'` restriction).
- Update the condition for the wrapper class. If `forceBusinessCompanyFieldsVisible` is true, directly assign `address-contact-type-company d-block` to bypass Shopware's JS toggle layer and force the fields to appear correctly.

```twig
[MODIFY] src/Resources/views/storefront/component/address/address-personal-company.html.twig
{% sw_extends '@Storefront/storefront/component/address/address-personal-company.html.twig' %}

{% block component_account_register_company_fields %}
    {% set checkoutType = app.request.query.get('checkoutType')
        ?: app.request.request.get('checkoutType')
        ?: (data is defined and data is not null and data.all is defined ? data.get('checkoutType') : null) %}
    
    {% set forceBusinessCompanyFieldsVisible = checkoutType != 'guest' %}
    {% set accountTypeRequired = config('core.loginRegistration.showAccountTypeSelection') %}

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
                            {% sw_include '@Storefront/storefront/component/form/form-input.html.twig' with {
                                label: 'address.companyNameLabel'|trans|sw_sanitize,
                                id: idPrefix ~ prefix ~ 'company',
                                name: prefix ? prefix ~ '[company]' : 'company',
                                value: address.get('company'),
                                autocomplete: 'section-personal organization',
                                violationPath: violationPath,
                                validationRules: 'required',
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
{% endblock %}
```

## Phase 3: Post-Implementation Reporting
Generate an implementation report to `_ai/backlog/reports/` describing the technical decisions and exact files altered during the fix. Ensure caching and theme compiling paths are referenced for manual testing procedures.
```
