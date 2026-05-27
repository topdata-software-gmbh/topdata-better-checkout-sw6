---
filename: "_ai/backlog/active/260527_0235__IMPLEMENTATION_PLAN__fix_account_type_on_login_page.md"
title: "Fix Account Type Display on Default Login Page"
createdAt: 2026-05-27 02:35
updatedAt: 2026-05-27 02:35
status: draft
priority: high
tags: [bugfix, template, account-type, login]
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---

# Problem Statement
The plugin provides settings to enforce specific account types during registration (e.g., "Immer Gewerblich" / "Always Business"). While this setting is respected during the intermediate 3-box checkout step, the default Shopware login and registration page (`/account/login`) still presents a dropdown for users to select their account type. This happens because the Twig template overrides in the plugin fail to identify the `/account/login` route (`frontend.account.login.page`) as a registration route, rendering the default account type selector instead.

# Executive Summary
The proposed solution fixes this behavior by updating the route checks within the plugin's storefront template overrides. We will add the `frontend.account.login.page` route to the list of recognized registration routes in `address-personal.html.twig` and `address-personal-company.html.twig`. When users visit the standard login page, the system will now apply the plugin's configuration rules for registration account types, hiding the dropdown and correctly substituting it with the configured underlying value (e.g., hidden inputs for `ACCOUNT_TYPE_BUSINESS`).

# Project Environment Details
- **Platform**: Shopware 6.7.*
- **Plugin**: TopdataBetterCheckoutSW6
- **Architecture**: The plugin utilizes Twig block overrides and System Config injection to modify the frontend checkout and registration processes. The fix touches only storefront view files.

# Phase 1: Update Address Personal Template

We need to add `frontend.account.login.page` to the `isRegistrationRoute` check in the personal address template so that the frontend hides the Account Type selection dropdown when configured.

[MODIFY] src/Resources/views/storefront/component/address/address-personal.html.twig
```twig
{% sw_extends '@Storefront/storefront/component/address/address-personal.html.twig' %}

{% block component_address_personal_account_type %}
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

        {% if accountTypeSetting == 'user_choice' %}
            {{ parent() }}
        {% elseif accountTypeSetting == 'always_business' %}
            {% if prefix != 'shippingAddress' %}
                <input type="hidden" name="{% if prefix %}{{ prefix }}[accountType]{% else %}accountType{% endif %}" value="{{ constant('Shopware\\Core\\Checkout\\Customer\\CustomerEntity::ACCOUNT_TYPE_BUSINESS') }}">
            {% endif %}
        {% elseif accountTypeSetting == 'always_private' %}
            {% if prefix != 'shippingAddress' %}
                <input type="hidden" name="{% if prefix %}{{ prefix }}[accountType]{% else %}accountType{% endif %}" value="{{ constant('Shopware\\Core\\Checkout\\Customer\\CustomerEntity::ACCOUNT_TYPE_PRIVATE') }}">
            {% endif %}
        {% endif %}
    {% endif %}
{% endblock %}
```

# Phase 2: Update Address Company Template

Similarly, we need to add `frontend.account.login.page` to the `isRegistrationRoute` array in the company fields template to ensure company fields (like Company Name, VAT ID) are automatically shown when the enforced account type is "always_business".

[MODIFY] src/Resources/views/storefront/component/address/address-personal-company.html.twig
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
        {% endif %}
    {% endif %}
{% endblock %}
```

# Phase 3: Reporting

---
filename: "_ai/backlog/reports/260527_0235__IMPLEMENTATION_REPORT__fix_account_type_on_login_page.md"
title: "Report: Fix Account Type Display on Default Login Page"
createdAt: 2026-05-27 02:35
updatedAt: 2026-05-27 02:35
planFile: "_ai/backlog/active/260527_0235__IMPLEMENTATION_PLAN__fix_account_type_on_login_page.md"
project: "TopdataBetterCheckoutSW6"
status: completed
filesCreated: 0
filesModified: 2
filesDeleted: 0
tags: [bugfix, template, account-type, login]
documentType: IMPLEMENTATION_REPORT
---

### 1. Summary
Modified the storefront template conditions to correctly identify the standard Shopware `/account/login` route (`frontend.account.login.page`) as a valid registration route. This ensures that the plugin's configured default account type logic (e.g. Always Business) evaluates properly and successfully replaces the user-choice dropdown.

### 2. Files Changed
- **Modified**: 
    - `src/Resources/views/storefront/component/address/address-personal.html.twig` - Added `frontend.account.login.page` to the `isRegistrationRoute` logic condition.
    - `src/Resources/views/storefront/component/address/address-personal-company.html.twig` - Added `frontend.account.login.page` to the `isRegistrationRoute` logic condition.

### 3. Key Changes
- Expanded template logic array to properly cover Shopware's standard login page route alongside checkout registration routes, bridging the gap between normal signups and plugin-driven checkout signups.
- Ensured backend configuration is actively reflected on both typical onboarding entry points.

### 4. Deviations from Plan
- None. The implementation straightforwardly matches the plan.

### 5. Technical Decisions
- Adding `frontend.account.login.page` into the existing `isRegistrationRoute` array rather than rewriting the variable assignment avoids redundant markup and retains backwards compatibility with the original scope of the plugin. By strictly testing routes, we also prevent affecting standard "edit address" views (`frontend.account.address.edit.page`) that rely on the same components.

### 6. Testing Notes
- Visit `/account/login`. 
- Ensure that the dropdown menu for account types ("Privat" / "Gewerblich") is hidden.
- If configured to "Always Business", ensure fields like "Firma", "Abteilung", and "USt-IdNr" are exposed automatically and mandatory validations persist successfully during submit.
- Creating an account from `/account/login` should write a customer entry with Account Type strictly bound to `Business`.

### 7. Documentation Updates
- N/A.

### 8. Next Steps
- Rebuild Storefront (`bin/console theme:compile`) to render updated templates and verify.
```
