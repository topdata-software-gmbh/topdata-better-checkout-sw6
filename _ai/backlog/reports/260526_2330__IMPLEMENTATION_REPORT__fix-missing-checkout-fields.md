---
filename: "_ai/backlog/reports/260526_2330__IMPLEMENTATION_REPORT__fix-missing-checkout-fields.md"
title: "Report: Fix missing password and company fields on account registration"
createdAt: 2026-05-26 23:30
updatedAt: 2026-05-27 00:00
planFile: "_ai/backlog/active/260526_2330__IMPLEMENTATION_PLAN__fix-missing-checkout-fields.md"
project: "topdata-better-checkout-sw6"
status: completed
filesCreated: 0
filesModified: 2
filesDeleted: 0
tags: [shopware, twig, checkout, registration, bugfix]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
The password and company fields were missing during account registration within the Better Checkout plugin. Two Twig templates were patched:
1. Password field reappears because the hidden guest toggle checkbox is now correctly unchecked for standard registrations.
2. Company fields are now unconditionally visible (`d-block`) for non-guest registrations without relying on a hidden account type dropdown to trigger JS toggles.

## 2. Files Changed
### Modified Files
- `src/Resources/views/storefront/page/checkout/address/register.html.twig` — Fixed the hidden guest checkbox toggle to correctly show/hide the password field based on checkoutType.
- `src/Resources/views/storefront/component/address/address-personal-company.html.twig` — Forced company fields to `d-block` on non-guest registrations and broadened the visibility condition.

## 3. Key Changes
- **register.html.twig**: Changed `name="createCustomerAccount"` to `name="guest"` and `value="1"` to `value="true"` on the hidden checkbox. Removed `checked="checked"` from the register block (so JS toggle evaluates to show password). Kept `checked="checked"` on the guest block (so JS toggle hides password). Removed the extraneous `<input type="hidden" name="guest" value="">` from the register block.
- **address-personal-company.html.twig**: Simplified `forceBusinessCompanyFieldsVisible` to `checkoutType != 'guest'` (removed `prefix == 'shippingAddress'` restriction). Added `forceBusinessCompanyFieldsVisible` to the outer `if` condition. Updated the wrapper div class to use `address-contact-type-company d-block` when `forceBusinessCompanyFieldsVisible` is true, bypassing JS toggle layers.

## 4. Technical Decisions
- **Checkbox naming**: Shopware's frontend JavaScript toggle logic keys off the `name="guest"` attribute with `value="true"`. Using `name="createCustomerAccount"` was incorrect and prevented the JS from evaluating the guest state properly.
- **Removing hidden input on register**: The hidden `<input name="guest" value="">` was conflicting with the checkbox's `value="true"` when unchecked. Removing it lets the JS toggle correctly detect not-a-guest state.
- **Direct CSS classes for company fields**: Instead of relying on the account type dropdown JS to add `d-block`, the template now unconditionally assigns `d-block` for non-guest registrations. This works even when the account type dropdown is hidden.

## 5. Testing Notes
- Run `bin/console cache:clear` inside the container to clear template caches.
- Run `./bin/build-storefront.sh` to rebuild the storefront assets.
- Test at `/account/register` — password and company fields should be visible.
- Test checkout registration — password and company fields should be visible.
- Test guest checkout — password field should remain hidden, company fields should be hidden.
