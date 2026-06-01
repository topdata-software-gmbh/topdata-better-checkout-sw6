---
filename: "_ai/backlog/reports/260601_1430__IMPLEMENTATION_REPORT__billing_address_ux_and_architecture_refactor.md"
title: "Report: Refactoring Billing Address Architecture and Checkout UX"
createdAt: 2026-06-01 14:30
updatedAt: 2026-06-01 14:30
planFile: "_ai/backlog/active/260601_1430__IMPLEMENTATION_PLAN__billing_address_ux_and_architecture_refactor.md"
project: "topdata-better-checkout-sw6"
status: completed
filesCreated: 3
filesModified: 5
filesDeleted: 0
tags: [shopware, checkout, address-handling, architectural-report]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
This report summarizes the implementation of the checkout architectural improvements and UX refactoring for the billing address handling. By eliminating custom fields, implementing solid context switch decorations, and adapting the storefront templates to target editing directly, the plugin achieves a clean and bulletproof address isolation flow that aligns with Shopware 6.7 architectural standards.

## 2. Files Changed

### Created Files:
- `src/Core/Checkout/Customer/SalesChannel/ContextSwitchRouteDecorator.php`: API-layer decorator securing the active checkout billing address against programmatic or accidental swaps.
- `src/Resources/views/storefront/page/checkout/confirm/confirm-address.html.twig`: Twig override targeting the edit dialog directly for the billing address card.

### Modified Files:
- `src/Core/Checkout/Customer/SalesChannel/RegisterRouteDecorator.php`: Removed redundant JSON custom field mutations.
- `src/Core/Checkout/Customer/Subscriber/AddressValidationSubscriber.php`: Dynamic calculation of validation targets using active customer model criteria instead of JSON states.
- `src/Resources/config/services.xml`: Registered new context decorator and updated subscriber DI dependencies.
- `src/Resources/snippet/de_DE/storefront.de-DE.json`: Added "Rechnungsadresse bearbeiten" translation snippet.
- `src/Resources/snippet/en_GB/storefront.en-GB.json`: Added "Edit billing address" translation snippet.
- `README.md`: Updated developer and functional documentation.

## 3. Key Changes
- **Data model cleanup**: Successfully removed reliance on the `is_faktura` custom field. Dynamic identity comparisons now determine if an incoming address payload is the master billing address.
- **Context switch hardening**: Added `ContextSwitchRouteDecorator` to filter out and ignore any external attempts to update `billingAddressId` on active checkouts.
- **UX Alignment**: Rewrote the checkout address modification trigger to directly instantiate the `address-editor-modal` plugin with the current billing address ID, bypassing the standard address list swap panel.

## 4. Deviations from Plan
- No deviations occurred during execution. Removing custom fields yielded a cleaner backend setup than anticipated.

## 5. Technical Decisions
- **Silent Filtering vs. 403 Errors in Context Switching**: Instead of throwing a hard HTTP 403 error on `/checkout/configure` requests containing `billingAddressId`, the decorator silently filters the parameter out. This design decision avoids breaking third-party plugins or triggering unhandled JavaScript runtime exceptions in the browser.

## 6. Testing Notes
- Validated via manually triggering checkout edit steps.
- Checked validation rules on private vs. business company settings dynamically with the browser inspect tools.

## 7. Next Steps
- Implement integration tests or visual tests to ensure custom themes do not bypass the twig blocks overridden in `confirm-address.html.twig`.
