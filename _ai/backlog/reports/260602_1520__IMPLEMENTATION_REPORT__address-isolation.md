---
filename: "_ai/backlog/reports/260602_1520__IMPLEMENTATION_REPORT__address-isolation.md"
title: "Report: Implement Billing and Shipping Address Isolation"
createdAt: 2026-06-02 15:20
updatedAt: 2026-06-02 15:20
planFile: "_ai/backlog/active/260602_1516__IMPLEMENTATION_PLAN__address-isolation.md"
project: "TopdataBetterCheckoutSW6"
status: completed
filesCreated: 1
filesModified: 2
filesDeleted: 0
tags: [checkout, addresses, api-validation, storefront, sw6.7]
documentType: IMPLEMENTATION_REPORT
---

## Summary

Successfully implemented full isolation between billing and shipping addresses:
1. Added a DAL-level event subscriber that prevents `default_billing_address_id` and `default_shipping_address_id` from being set to the same value, throwing a 403 Forbidden error.
2. Updated the addressbook address-item Twig template to hide the "Set as default shipping" option for the default billing address.

## Files Created

1. `src/Core/Checkout/Customer/Subscriber/CustomerAddressIsolationSubscriber.php`
   - Subscribes to `PreWriteValidationEvent`
   - Intercepts `UpdateCommand` for `CustomerDefinition`
   - Fetches current address IDs from the database via `Doctrine\DBAL\Connection`
   - Compares new billing and shipping address IDs (considering partial updates)
   - Throws `AccessDeniedHttpException` with a clear message when isolation is violated

## Files Modified

1. `src/Resources/config/services.xml`
   - Registered `CustomerAddressIsolationSubscriber` as a new kernel event subscriber
   - Injected `Doctrine\DBAL\Connection` as a constructor argument

2. `src/Resources/views/storefront/page/account/addressbook/address-item.html.twig`
   - Changed the condition for showing "Set as default shipping" from `{% if not defaultShipping %}` to `{% if not defaultShipping and not defaultBilling %}`
   - This ensures the default billing address no longer displays the option to be set as the default shipping address
   - The plugin already never showed "Set as default billing", so no additional Twig changes were required for the reverse direction

## Deviations from Plan

- The subscriber was placed in `src/Core/Checkout/Customer/Subscriber/` instead of `src/Subscriber/` to align with the existing project structure (same directory as `AddressValidationSubscriber`).
- The fallback Twig override (`storefront/component/address/address-item.html.twig`) was not needed because the plugin already uses a custom address-item template for the addressbook (`storefront/page/account/addressbook/address-item.html.twig`), which was modified directly.
- Added a null-check for `$primaryKey['id']` in the subscriber to make it more robust.

## QA Notes

- Manual testing should verify that attempting to set the default billing address as the default shipping address via the storefront results in the option being hidden in the dropdown.
- API-level enforcement should be tested via Store-API or Admin-API calls that attempt to update a customer with identical billing and shipping address IDs — a 403 response is expected.
- The existing `SetDefaultBillingAddressRouteDecorator` and `ContextSwitchRouteDecorator` continue to operate as before; the new subscriber adds a lower-level safety net.
