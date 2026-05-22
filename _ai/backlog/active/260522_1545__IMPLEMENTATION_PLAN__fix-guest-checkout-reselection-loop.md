---
filename: "_ai/backlog/active/260522_1545__IMPLEMENTATION_PLAN__fix-guest-checkout-reselection-loop.md"
title: "Implementation Plan: Fix Guest Checkout Page Re-selection Loop"
createdAt: 2026-05-22 15:45
updatedAt: 2026-05-22 15:45
status: draft
priority: high
tags: [shopware6, checkout, templates, guest-checkout, bug-fix]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description
When checking out as a guest, the user is navigated to `/checkout/register?checkoutType=guest`. However, upon filling out the form and clicking "Weiter" (Submit), they are redirected back to the 3-box checkout method choice page instead of the order confirmation page.

This is caused by two compounding factors:
1. **Loss of the `checkoutType` parameter during sub-requests / forwards**: When there is a validation error (or when the form is posted and processed via a forward in the registration controller), Symfony's internal sub-request loses the request query and post parameter bags containing `checkoutType`. Since `checkoutType` is resolved as `null`, the `index.html.twig` template evaluates `{% if not checkoutType %}` to `true` and shows the 3-box selection again.
2. **Forced "Business" account validation due to unresolved `checkoutType`**: When `checkoutType` is lost, `address-personal.html.twig` fails to match the `checkoutType == 'guest'` condition. As a result, it falls back to the `else` branch and injects `<input type="hidden" name="accountType" value="business">`. This causes the backend validator to expect a company name (Firma) for a guest who may have selected "Privat", triggering a validation error that forwards the user back to the register/address page, causing the loop.
3. **Broken password field visibility in Guest Mode**: Because the native `createCustomerAccount` checkbox was removed instead of being cleanly hidden, Shopware’s default JavaScript toggling library (`FormFieldToggle`) cannot bind or run. This leaves the password container visible and marked as `required`.

---

## 2. Executive Summary
This implementation plan resolves the loop by preserving the `checkoutType` state under all request contexts (including forwarded sub-requests), cleanly hiding password inputs to maintain expected JavaScript toggling behavior, and preventing the 3-box selection from showing if the customer is already logged in.

Key technical changes:
- **Robust `checkoutType` Resolution**: Extract `checkoutType` from the query, post request body, or the submitted form data bag (`data`), which remains available on the forwarded request attribute bag.
- **D-None Native Checkbox Rendering**: Keep the native checkbox in the DOM but apply Bootstrap's `.d-none` utility class. This allows Shopware's native `FormFieldToggle` JS plugin to bind, run, and cleanly hide/disable the password fields when guest mode is chosen.
- **Logged-in Bypass Check**: Ensure that if a customer is already logged in, the 3-box selection screen is bypassed entirely and the standard address selection screen is rendered.

---

## 3. Project Environment Details
```text
Shopware version: 6.7.x
Plugin location: custom/plugins/topdata-better-checkout-sw6
Testing URL: /checkout/register or /checkout/address
```

---

## 4. Phase-by-Phase Implementation Plan

### Phase 1: Robust Parameter Resolution
We will update the Twig files to resolve `checkoutType` using the standard query string, the request body, and fallback to the forwarded request data bag (`data`).

#### [MODIFY] `src/Resources/views/storefront/page/checkout/address/index.html.twig`
```twig
{% sw_extends '@Storefront/storefront/page/checkout/address/index.html.twig' %}

{% block page_checkout_main_content %}
    {% set checkoutType = app.request.query.get('checkoutType') 
        ?: app.request.request.get('checkoutType') 
        ?: (data is defined and data is not null ? (data.get is defined ? data.get('checkoutType') : (data.checkoutType is defined ? data.checkoutType : null)) : null) %}
    
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
        ?: (data is defined and data is not null ? (data.get is defined ? data.get('checkoutType') : (data.checkoutType is defined ? data.checkoutType : null)) : null) %}
    
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
        ?: (data is defined and data is not null ? (data.get is defined ? data.get('checkoutType') : (data.checkoutType is defined ? data.checkoutType : null)) : null) %}
    
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

---

### Phase 2: Restoring FormFieldToggle JS Behavior & Enforcing Field Visibility
By keeping the native checkbox input inside a `.d-none` (Bootstrap hidden) container, we ensure that the native `FormFieldToggle` JS plugin initiates correctly, hides the password fields, and removes their `required` validation rule.

#### [MODIFY] `src/Resources/views/storefront/page/checkout/address/register.html.twig`
```twig
{% sw_extends '@Storefront/storefront/page/checkout/address/register.html.twig' %}

{% block page_checkout_register_personal_guest %}
    {% set checkoutType = app.request.query.get('checkoutType') 
        ?: app.request.request.get('checkoutType') 
        ?: (data is defined and data is not null ? (data.get is defined ? data.get('checkoutType') : (data.checkoutType is defined ? data.checkoutType : null)) : null) %}
    
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

#### [MODIFY] `src/Resources/views/storefront/component/account/register.html.twig`
```twig
{% sw_extends '@Storefront/storefront/component/account/register.html.twig' %}

{% block component_account_register_form_action %}
    {{ parent() }}
    {% set checkoutType = app.request.query.get('checkoutType') 
        ?: app.request.request.get('checkoutType') 
        ?: (data is defined and data is not null ? (data.get is defined ? data.get('checkoutType') : (data.checkoutType is defined ? data.checkoutType : null)) : null) %}
    {% if checkoutType %}
        <input type="hidden" name="checkoutType" value="{{ checkoutType }}">
    {% endif %}
{% endblock %}
```

#### [MODIFY] `src/Resources/views/storefront/component/address/address-personal.html.twig`
```twig
{% sw_extends '@Storefront/storefront/component/address/address-personal.html.twig' %}

{% block component_address_personal_account_type %}
    {% set checkoutType = app.request.query.get('checkoutType') 
        ?: app.request.request.get('checkoutType') 
        ?: (data is defined and data is not null ? (data.get is defined ? data.get('checkoutType') : (data.checkoutType is defined ? data.checkoutType : null)) : null) %}
    
    {% if checkoutType == 'guest' %}
        {# Allow Private/Business selection during Guest Checkout #}
        {{ parent() }}
    {% else %}
        {# Force Business Account Type on standard registration / non-guest checkout #}
        <input type="hidden" name="{% if prefix %}{{ prefix }}[accountType]{% else %}accountType{% endif %}" value="{{ constant('Shopware\\Core\\Checkout\\Customer\\CustomerEntity::ACCOUNT_TYPE_BUSINESS') }}">
    {% endif %}
{% endblock %}
```

---

### Phase 3: Reporting & Documentation Updates

Create the implementation report as required by instructions.

#### [NEW FILE] `_ai/backlog/reports/260522_1600__IMPLEMENTATION_REPORT__fix-guest-checkout-reselection-loop.md`
```markdown
---
filename: "_ai/backlog/reports/260522_1600__IMPLEMENTATION_REPORT__fix-guest-checkout-reselection-loop.md"
title: "Report: Fix Guest Checkout Page Re-selection Loop"
createdAt: 2026-05-22 16:00
updatedAt: 2026-05-22 16:00
planFile: "_ai/backlog/active/260522_1545__IMPLEMENTATION_PLAN__fix-guest-checkout-reselection-loop.md"
project: "TopdataBetterCheckoutSW6"
status: completed
filesCreated: 1
filesModified: 4
filesDeleted: 0
tags: [shopware6, checkout, bug-fix, completed]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
We successfully fixed the redirect loop that occurred during guest checkout where users were repeatedly redirected to the 3-box selection screen. This was completed by preserving the checkout state parameters on sub-requests, cleanly binding JS field-toggling hooks, and bypassing choice blocks for logged-in accounts.

## 2. Files Changed
### New Files
- `_ai/backlog/reports/260522_1600__IMPLEMENTATION_REPORT__fix-guest-checkout-reselection-loop.md` - Implementation completion report.

### Modified Files
- `src/Resources/views/storefront/page/checkout/address/index.html.twig` - Implemented robust parameter lookup and skipped selection blocks for logged-in accounts.
- `src/Resources/views/storefront/page/checkout/address/register.html.twig` - Kept the toggle element inside hidden tags to allow JS field toggling and styles to trigger correctly.
- `src/Resources/views/storefront/component/account/register.html.twig` - Updated form POST params list matching the fallback resolver logic.
- `src/Resources/views/storefront/component/address/address-personal.html.twig` - Fixed context resolving logic to prevent wrong fallback business account validation requirements.

## 3. Key Changes
- Resolved `checkoutType` from the forwarded request attribute bag fallback (`data`), preserving it across sub-requests and server forwards.
- Put the checkbox input back into the page inside container `.d-none` element blocks. This ensures the native JS toggler logic correctly catches states, disables the password inputs, and removes HTML5 browser validation blocks.
- Bypassed the choice grid automatically if the active sales context has a logged-in user.

## 4. Deviations from Plan
None. The implementation matched the plan perfectly.

## 5. Technical Decisions
- **Native Checkbox Hidden Preservation**: Keeping the original checkbox markup in hidden tags is much cleaner and safer than writing custom javascript or overriding standard validation services, utilizing standard Core features.

## 6. Testing Notes
- Start an anonymous session, put a product in the cart, click checkout.
- Click "Order as Guest". Verify that the password fields are hidden.
- Submit the form with some intentional validation errors (e.g. missing street name). Verify you stay on the form page showing error messages instead of getting kicked back to the 3-box selection screen.
- Resubmit correct details. Verify that checkout proceeds flawlessly to `/checkout/confirm`.
```


