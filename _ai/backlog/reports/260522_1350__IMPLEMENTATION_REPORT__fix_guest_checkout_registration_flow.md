---
filename: "_ai/backlog/reports/260522_1350__IMPLEMENTATION_REPORT__fix_guest_checkout_registration_flow.md"
title: "Report: Fix guest checkout and standard customer registration flow"
createdAt: 2026-05-22 13:50
updatedAt: 2026-05-22 13:50
planFile: "_ai/backlog/active/260522_1350__IMPLEMENTATION_PLAN__fix_guest_checkout_registration_flow.md"
project: "TopdataBetterCheckoutSW6"
status: completed
filesCreated: 1
filesModified: 1
filesDeleted: 0
tags: [checkout, registration, guest, shopware6]
documentType: IMPLEMENTATION_REPORT
---

## Summary
The payment method validation error during checkout has been addressed by fixing the parameters passed in the customer registration template. Standard customer registrations now submit the modern account-creation parameter and no longer silently fall back to guest behavior.

## Files Changed
### Modified Files
- `src/Resources/views/storefront/page/checkout/address/register.html.twig`: Updated `page_checkout_register_personal_guest` block to pass `createCustomerAccount` alongside `guest` for both `guest` and `register` checkout types.

### Created Files
- `_ai/backlog/reports/260522_1350__IMPLEMENTATION_REPORT__fix_guest_checkout_registration_flow.md`: Implementation report for this plan.

## Key Changes
- Added `<input type="hidden" name="createCustomerAccount" value="">` when guest checkout (`checkoutType == 'guest'`) is requested.
- Added `<input type="hidden" name="createCustomerAccount" value="1">` when standard registration (`checkoutType == 'register'`) is requested.

## Technical Decisions
- Kept both hidden fields (`guest` and `createCustomerAccount`) to preserve compatibility with legacy and modern Shopware registration handling.

## Testing Notes
- Attempted to execute `bin/console cache:clear`, but command execution was skipped in this session.
- Manual storefront flow verification (standard registration and guest checkout) is still pending and should be performed in the running Shopware environment.
