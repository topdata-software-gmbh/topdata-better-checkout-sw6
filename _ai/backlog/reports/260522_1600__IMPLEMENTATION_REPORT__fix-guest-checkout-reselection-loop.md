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
