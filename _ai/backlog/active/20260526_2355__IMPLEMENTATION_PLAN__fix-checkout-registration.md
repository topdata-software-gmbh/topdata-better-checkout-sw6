---
filename: "_ai/backlog/active/20260526_2355__IMPLEMENTATION_PLAN__fix-checkout-registration.md"
title: "Fix checkout registration to show password and create regular customer"
createdAt: 2026-05-26 23:55
updatedAt: 2026-05-26 23:55
status: draft
priority: high
tags: [checkout, registration, shopware-6.7, bugfix]
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Statement
When a user selects "Ein Konto erstellen" (Create an account) during the custom checkout flow provided by the `TopdataBetterCheckoutSW6` plugin, the registration form is missing the password field. Furthermore, upon submitting the form, the customer is saved to the database as a "guest" instead of a regular customer. This happens because the current Twig template override uses the outdated `name="guest"` checkbox approach, whereas Shopware 6.7 core relies on the `name="createCustomerAccount"` field and its associated `data-form-field-toggle` logic.

## 2. Executive Summary
The solution involves modifying the `src/Resources/views/storefront/page/checkout/address/register.html.twig` template override. We will replace the obsolete `guest` checkbox with the standard Shopware 6 `createCustomerAccount` checkbox. By ensuring this field is rendered as `checked` and passed with the correct toggle configurations (`data-form-field-toggle-value="true"`) when `checkoutType == 'register'`, the JavaScript will properly display the password field, and the Shopware backend will successfully register the user as a standard customer. 

## 3. Project Environment Details
- **Plugin Name**: TopdataBetterCheckoutSW6
- **Shopware Version Requirement**: 6.7.*
- **Core Area**: Storefront Checkout Address Registration Template
- **Dependencies**: Shopware's built-in `form-field-toggle` JavaScript plugin.

## 4. Implementation Steps

### Update Register Twig Template
We will modify the file `src/Resources/views/storefront/page/checkout/address/register.html.twig` to reflect the Shopware 6.7 standards for account creation.

```twig
[MODIFY] src/Resources/views/storefront/page/checkout/address/register.html.twig
{% sw_extends '@Storefront/storefront/page/checkout/address/register.html.twig' %}

{% block page_checkout_register_personal_guest %}
    {% set checkoutType = app.request.query.get('checkoutType')
        ?: app.request.request.get('checkoutType')
        ?: (data is defined and data is not null and data.all is defined ? data.get('checkoutType') : null) %}
    
    {% if checkoutType == 'guest' %}
        <div class="d-none">
            <input type="checkbox"
                   name="createCustomerAccount"
                   value="1"
                   id="personalGuest"
                   data-form-field-toggle="true"
                   data-form-field-toggle-target=".js-form-field-toggle-guest-mode"
                   data-form-field-toggle-value="true">
            <input type="hidden" name="guest" value="1">
        </div>
        <style>
            .js-form-field-toggle-guest-mode {
                display: none !important;
            }
        </style>
    {% elseif checkoutType == 'register' %}
        <div class="d-none">
            <input type="checkbox"
                   name="createCustomerAccount"
                   value="1"
                   id="personalGuest"
                   checked="checked"
                   data-form-field-toggle="true"
                   data-form-field-toggle-target=".js-form-field-toggle-guest-mode"
                   data-form-field-toggle-value="true">
        </div>
    {% else %}
        {{ parent() }}
    {% endif %}
{% endblock %}
```

### Explanation of Changes
- **`name="createCustomerAccount"`**: Modern Shopware versions no longer respond solely to the `guest` field for account creation intent. It now strictly checks `createCustomerAccount`.
- **`checked="checked"` & `data-form-field-toggle-value="true"`**: When the customer chooses to register, this combination aligns with the Storefront JS plugin to un-hide the password field correctly.
- **`<input type="hidden" name="guest" value="1">`**: Left intact for the guest condition to satisfy backwards-compatibility and internal plugin validation (specifically `RegisterRouteDecorator.php`).

---

## 5. Report Template

```yaml
---
filename: "_ai/backlog/reports/20260526_2355__IMPLEMENTATION_REPORT__fix-checkout-registration.md"
title: "Report: Fix checkout registration to show password and create regular customer"
createdAt: 2026-05-26 23:55
updatedAt: 2026-05-26 23:55
planFile: "_ai/backlog/active/20260526_2355__IMPLEMENTATION_PLAN__fix-checkout-registration.md"
project: "TopdataBetterCheckoutSW6"
status: completed
filesCreated: 0
filesModified: 1
filesDeleted: 0
tags: [checkout, registration, shopware-6.7, bugfix]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
The checkout registration flow has been successfully fixed. Users selecting "Ein Konto erstellen" now see the password field and are correctly saved as standard customers instead of guests.

## 2. Files Changed
- **Modified**: `src/Resources/views/storefront/page/checkout/address/register.html.twig`
  - Replaced the deprecated `name="guest"` functionality with the required `name="createCustomerAccount"` checkbox.
  - Set appropriate `data-form-field-toggle-value` attributes to properly interface with Shopware's native Storefront JavaScript to display the password input.

## 3. Key Changes
- Shifted the input intent handler to `createCustomerAccount`.
- Updated the boolean toggle checking mechanism for the password toggle (`data-form-field-toggle-value="true"`).

## 4. Deviations from Plan
None.

## 5. Technical Decisions
- Preserved `<input type="hidden" name="guest" value="1">` exclusively for the `checkoutType == 'guest'` state to maintain compatibility with the plugin's `RegisterRouteDecorator` logic without affecting the standard core data logic.

## 6. Testing Notes
1. Navigate to the checkout address selector.
2. Select **"Ein Konto erstellen"**.
3. Verify that the form correctly displays the **password** field.
4. Fill out the registration and complete the submission.
5. In the backend Administration (or Database), verify the newly registered customer has `guest = false`.
6. Repeat the process using **"Bestellung als Gast"** to ensure standard guest functionality remains completely intact (password hidden and `guest = true`).

## 7. Next Steps
- Consider a regression test around checkout flows during the next major Shopware update to ensure JS toggle classes and input names are kept in sync with core architectural changes.
```

