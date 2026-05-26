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
  - Replaced the deprecated `name="guest"` checkbox with the required `name="createCustomerAccount"` checkbox.
  - Set appropriate `data-form-field-toggle-value="true"` attributes to properly interface with Shopware's native Storefront JavaScript to display the password input.
  - Added `checked="checked"` to the register case to ensure the password field is shown by default.

## 3. Key Changes
- Shifted the input intent handler to `createCustomerAccount`.
- Updated the boolean toggle checking mechanism for the password toggle (`data-form-field-toggle-value="true"` in both guest and register cases).
- Removed `checked="checked"` from guest case (unchecked by default, password hidden).
- Added `checked="checked"` to register case (checked by default, password shown).

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
