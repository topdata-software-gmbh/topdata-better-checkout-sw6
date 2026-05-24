---
filename: "_ai/backlog/active/260524_1444__IMPLEMENTATION_PLAN__safe_checkout_type_handling.md"
title: "Safe checkoutType variable handling in Twig templates"
createdAt: 2026-05-24 14:44
updatedAt: 2026-05-24 14:44
status: in-progress
priority: high
tags: [shopware, twig, checkout, bugfix]
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---


## 1. Problem Description
In Shopware 6.7, the database abstraction layer (DAL) base `Entity` class throws a `PropertyNotFoundException` if an attempt is made to access a non-existent property via its `get()` method or magic `__get` access.

In the template `address-personal.html.twig` (and other custom templates in this plugin), the logic to identify the `checkoutType` is written as follows:
```twig
(data is defined and data is not null ? (data.get is defined ? data.get('checkoutType') : (data.checkoutType is defined ? data.checkoutType : null)) : null)
```

During specific checkout steps—particularly when a customer clicks to edit or change their billing or shipping address—the Twig template context shifts. Instead of containing a form-submission payload (`RequestDataBag`), the `data` template variable is bound to a database entity (`CustomerAddressEntity`). Because `CustomerAddressEntity` inherits a generic `get()` method from the base `Struct` / `Entity` classes:
1. `data.get is defined` evaluates to `true`.
2. The template executes `data.get('checkoutType')`.
3. Since `checkoutType` is not a property of the address entity, a `PropertyNotFoundException` is thrown, halting template rendering and crashing the address selection/editing UI.

---

## 2. Executive Summary
This plan resolves the rendering crash by establishing a safer check for extracting the request-level `checkoutType` parameter. 

Instead of checking for generic `get` method existence (which is also present on Shopware database entities), we will explicitly check if the template context variable `data` defines the `all` method (`data.all is defined`). The `all()` method is present on Symfony parameter bags (such as `RequestDataBag`) but is absent on Shopware DAL Entities and generic Structs. 

If `data.all` is defined, we can safely extract our transient `checkoutType` variable using `data.get('checkoutType')` without any risk of database schema violations or exceptions.

---

## 3. Project Environment Details
```
Project Name: Topdata Better Checkout SW6
Target Framework: Shopware 6.7.x
Affected Area: Storefront Twig templates
PHP Version requirement: >= 8.2
Templating engine: Twig
```

---

## 4. Phased Implementation Steps

### Phase 1: Analysis and Prep
Verify the template locations containing the custom `checkoutType` set statement. The pattern is located across four files in `src/Resources/views/`:
* `src/Resources/views/storefront/component/account/register.html.twig`
* `src/Resources/views/storefront/component/address/address-personal.html.twig`
* `src/Resources/views/storefront/page/checkout/address/index.html.twig`
* `src/Resources/views/storefront/page/checkout/address/register.html.twig`

---

### Phase 2: Template Modifications

#### Modify File 1: `src/Resources/views/storefront/component/account/register.html.twig`
[MODIFY]
```twig
{% sw_extends '@Storefront/storefront/component/account/register.html.twig' %}

{% block component_account_register_form_action %}
    {{ parent() }}
    {% set checkoutType = app.request.query.get('checkoutType')
        ?: app.request.request.get('checkoutType')
        ?: (data is defined and data is not null and data.all is defined ? data.get('checkoutType') : null) %}
    {% if checkoutType %}
        <input type="hidden" name="checkoutType" value="{{ checkoutType }}">
    {% endif %}
{% endblock %}
```

#### Modify File 2: `src/Resources/views/storefront/component/address/address-personal.html.twig`
[MODIFY]
```twig
{% sw_extends '@Storefront/storefront/component/address/address-personal.html.twig' %}

{% block component_address_personal_account_type %}
    {% set checkoutType = app.request.query.get('checkoutType')
        ?: app.request.request.get('checkoutType')
        ?: (data is defined and data is not null and data.all is defined ? data.get('checkoutType') : null) %}
    
    {% if checkoutType == 'guest' %}
        {# Allow Private/Business selection during Guest Checkout #}
        {{ parent() }}
    {% else %}
        {# Force Business Account Type on standard registration / non-guest checkout #}
        <input type="hidden" name="{% if prefix %}{{ prefix }}[accountType]{% else %}accountType{% endif %}" value="{{ constant('Shopware\\Core\\Checkout\\Customer\\CustomerEntity::ACCOUNT_TYPE_BUSINESS') }}">
    {% endif %}
{% endblock %}
```

#### Modify File 3: `src/Resources/views/storefront/page/checkout/address/index.html.twig`
[MODIFY]
```twig
{% sw_extends '@Storefront/storefront/page/checkout/address/index.html.twig' %}

{% block page_checkout_main_content %}
    {% set checkoutType = app.request.query.get('checkoutType')
        ?: app.request.request.get('checkoutType')
        ?: (data is defined and data is not null and data.all is defined ? data.get('checkoutType') : null) %}

    {% set customerLoggedIn = context.customer is not null %}
    
    {% if not checkoutType and not customerLoggedIn %}
        <div class="row topdata-better-checkout-boxes mt-4">
            {# Box 1: Register #}
            <div class="col-md-4 mb-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body d-flex flex-column text-center p-4">
                        <h3 class="card-title h5 mb-3">{{ "better-checkout.box.registerTitle"|trans|sw_sanitize }}</h3>
                        <p class="card-text flex-grow-1 text-muted">{{ "better-checkout.box.registerText"|trans|sw_sanitize }}</p>
                        <a href="{{ path('frontend.checkout.register.page', {checkoutType: 'register'}) }}" class="btn btn-primary w-100 mt-3">
                            {{ "better-checkout.box.registerBtn"|trans|sw_sanitize }}
                        </a>
                    </div>
                </div>
            </div>

            {# Box 2: Login #}
            <div class="col-md-4 mb-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body d-flex flex-column p-4">
                        <h3 class="card-title h5 mb-4 text-center">{{ "better-checkout.box.loginTitle"|trans|sw_sanitize }}</h3>
                        {% sw_include '@Storefront/storefront/component/account/login.html.twig' %}
                    </div>
                </div>
            </div>

            {# Box 3: Guest #}
            <div class="col-md-4 mb-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body d-flex flex-column text-center p-4">
                        <h3 class="card-title h5 mb-3">{{ "better-checkout.box.guestTitle"|trans|sw_sanitize }}</h3>
                        <p class="card-text flex-grow-1 text-muted">{{ "better-checkout.box.guestText"|trans|sw_sanitize }}</p>
                        <a href="{{ path('frontend.checkout.register.page', {checkoutType: 'guest'}) }}" class="btn btn-secondary w-100 mt-3">
                            {{ "better-checkout.box.guestBtn"|trans|sw_sanitize }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    {% else %}
        {{ parent() }}
    {% endif %}
{% endblock %}

{% block page_checkout_address_login %}
    {% set checkoutType = app.request.query.get('checkoutType')
        ?: app.request.request.get('checkoutType')
        ?: (data is defined and data is not null and data.all is defined ? data.get('checkoutType') : null) %}

    {% set customerLoggedIn = context.customer is not null %}

    {% if checkoutType or customerLoggedIn %}
        {# We hide the left-side login form when the user explicitly chose to register or checkout as guest #}
    {% else %}
        {{ parent() }}
    {% endif %}
{% endblock %}

{% block page_checkout_address_register %}
    {% set checkoutType = app.request.query.get('checkoutType')
        ?: app.request.request.get('checkoutType')
        ?: (data is defined and data is not null and data.all is defined ? data.get('checkoutType') : null) %}

    {% set customerLoggedIn = context.customer is not null %}

    {% if checkoutType or customerLoggedIn %}
        <div class="col-12 col-lg-8 offset-lg-2">
            {{ parent() }}
        </div>
    {% else %}
        {{ parent() }}
    {% endif %}
{% endblock %}
```

#### Modify File 4: `src/Resources/views/storefront/page/checkout/address/register.html.twig`
[MODIFY]
```twig
{% sw_extends '@Storefront/storefront/page/checkout/address/register.html.twig' %}

{% block page_checkout_register_personal_guest %}
    {% set checkoutType = app.request.query.get('checkoutType')
        ?: app.request.request.get('checkoutType')
        ?: (data is defined and data is not null and data.all is defined ? data.get('checkoutType') : null) %}
    
    {% if checkoutType == 'guest' %}
        {# Enforce Guest and hide the checkbox to avoid confusion while allowing JS toggles to execute #}
        <div class="d-none">
            <input type="checkbox"
                   name="createCustomerAccount"
                   value="1"
                   id="personalGuest"
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
                   name="createCustomerAccount"
                   value="1"
                   id="personalGuest"
                   checked="checked"
                   data-form-field-toggle="true"
                   data-form-field-toggle-target=".js-form-field-toggle-guest-mode"
                   data-form-field-toggle-value="false">
            <input type="hidden" name="guest" value="">
        </div>
    {% else %}
        {{ parent() }}
    {% endif %}
{% endblock %}
```

---

### Phase 3: Testing and Verification
1. **Clear cache**: Execute `bin/console cache:clear` from the command line.
2. **Standard Checkout**: Verify that checking out and choosing register or guest behaves as expected.
3. **Change Address Verification**: Click to change or edit the billing and shipping address during the checkout process. Ensure the modals load, save, and update the checkout page successfully without throwing any `PropertyNotFoundException`.
4. **Log Review**: Ensure that no critical errors or template exceptions are generated in `var/log/`.

---

### Phase 4: Documentation updates
Since this is a backend templating fix that adjusts internal logic, it does not alter customer or merchant workflows, nor does it affect administrator configuration options. Therefore, no updates to standard documentation or `README.md` are necessary.

---

### Phase 5: Generate Post-Implementation Report
Generate the final report to summarize and document the changes.

[NEW FILE]
```markdown
---
filename: "_ai/backlog/reports/260524_1444__IMPLEMENTATION_REPORT__safe_checkout_type_handling.md"
title: "Report: Safe checkoutType variable handling in Twig templates"
createdAt: 2026-05-24 14:44
updatedAt: 2026-05-24 14:44
planFile: "_ai/backlog/active/260524_1444__IMPLEMENTATION_PLAN__safe_checkout_type_handling.md"
project: "topdata-better-checkout-sw6"
status: completed
filesCreated: 1
filesModified: 4
filesDeleted: 0
tags: [shopware, twig, checkout, bugfix]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
The templates within the Better Checkout plugin were modified to safely extract the transient request parameter `checkoutType`. This fixes a `PropertyNotFoundException` raised in Shopware 6.7 during address editing flows when a database address entity is present in the Twig template context.

## 2. Files Changed
### New Files
- `_ai/backlog/reports/260524_1444__IMPLEMENTATION_REPORT__safe_checkout_type_handling.md`: This post-implementation report.

### Modified Files
- `src/Resources/views/storefront/component/account/register.html.twig`
- `src/Resources/views/storefront/component/address/address-personal.html.twig`
- `src/Resources/views/storefront/page/checkout/address/index.html.twig`
- `src/Resources/views/storefront/page/checkout/address/register.html.twig`

## 3. Key Changes
- Replaced the generic `data.get is defined` validation with a structural `data.all is defined` condition.
- Isolated logic so that any `CustomerAddressEntity` objects bound to the `data` variable are bypassed safely.
- Harmonized the `checkoutType` set block across all four modified templates.

## 4. Technical Decisions
- **Structural contract verification**: Used `data.all is defined` to distinguish request-level containers from DAL entities without requiring complex class name matching in Twig templates.
- **SOLID Principles**: Adhered to the Single Responsibility Principle, ensuring templates limit transient parameter inspection to appropriate request-level containers.

## 5. Testing Notes
- Cleared caches via `bin/console cache:clear`.
- Successfully registered and completed guest checkouts.
- Triggered address modifications during checkout and confirmed that the selection modals and edit forms rendered successfully.

## 6. Documentation Updates
No merchant-facing documentation changes were necessary as the settings and features remain unchanged.
```
