---
filename: "_ai/backlog/reports/260529_1431__IMPLEMENTATION_REPORT__separate_faktura_address.md"
title: "Report: Separate Faktura Address and Lock Default Billing Address"
createdAt: 2026-05-29 14:31
updatedAt: 2026-05-29 14:31
planFile: "_ai/backlog/active/260529_1431__IMPLEMENTATION_PLAN__separate_faktura_address.md"
project: "topdata-better-checkout-sw6"
status: completed
filesCreated: 2
filesModified: 3
filesDeleted: 0
tags: [shopware6, checkout, address-management, erp-integration]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
Implemented isolated address tracking for billing and shipping addresses during customer registration. Added `is_faktura` custom field flagging for ERP integration and locked the billing address from being swapped within the customer's address book. The implementation spans three areas: backend address splitting during registration, API-level billing address lock, and Storefront UI removal of the "Set as default billing address" control.

## 2. Files Changed
### Created Files
- `src/Core/Checkout/Customer/SalesChannel/SetDefaultBillingAddressRouteDecorator.php` — Decorates `AbstractSetDefaultBillingAddressRoute` to throw `AccessDeniedHttpException`, blocking any attempt to change the default billing address pointer.
- `src/Resources/views/storefront/component/address/address-default.html.twig` — Extends the core `address-default.html.twig` template and empties the `component_address_default_billing` block to remove the "Set as default billing address" button from the Storefront.

### Modified Files
- `src/Core/Checkout/Customer/SalesChannel/RegisterRouteDecorator.php` — Added `splitAddressesAndFlagBilling()` method that clones the billing address when no separate shipping address is provided and sets `is_faktura` custom field flags on both addresses.
- `src/Resources/config/services.xml` — Registered the new `SetDefaultBillingAddressRouteDecorator` as a decorating service with `.inner` argument.
- `README.md` — Added three new feature bullet points documenting isolated addresses, ERP integration flags, and billing address lock.

## 3. Key Changes
- **Address Splitting (RegisterRouteDecorator.php:56-78)**: When `billingAddress` is present in the `RequestDataBag` and `shippingAddress` is absent, the billing address is cloned to create a separate shipping address entity. This prevents both addresses from pointing to the same database row.
- **Faktura Flagging**: Both addresses receive `customFields.is_faktura` — `true` on the billing address, `false` on the shipping address — for easy ERP identification without database schema changes.
- **Billing Address Lock (SetDefaultBillingAddressRouteDecorator.php:26-28)**: `setDefaultBillingAddress()` unconditionally throws `AccessDeniedHttpException`, blocking API and Storefront calls to `PATCH /account/customer/{customerId}/default-billing-address`.
- **UI Removal (address-default.html.twig)**: The `component_address_default_billing` block is emptied, removing the form button from the customer's address book view.

## 4. Technical Decisions
- **Cloning over deep-copy**: Simple `clone` is sufficient because `RequestDataBag` holds scalar and bag references. The custom fields bag is separately cloned to ensure the `is_faktura` flag differs between billing (`true`) and shipping (`false`).
- **No database migration**: Custom field flags are injected dynamically via `RequestDataBag`, leveraging Shopware's existing custom fields persistence. No schema changes required.
- **Throwing exception vs returning error**: Throwing `AccessDeniedHttpException` is the most defensive approach — it ensures no path (API, Storefront, or admin) can change the default billing address. The Storefront template override is a secondary UI layer to inform the customer before the API call is attempted.
- **Guest checkouts unaffected**: The `splitAddressesAndFlagBilling` call runs for all registrations (guest and full), but for guests the shipping address is typically already separate or irrelevant, so the clone only triggers when genuinely needed.

## 5. Testing Notes
- Run `bin/console cache:clear` inside the www container.
- Run `./bin/build-storefront.sh` to rebuild storefront assets.
- Test a new customer registration without providing a separate shipping address — verify two separate `customer_address` rows are created with different `customFields.is_faktura` values.
- Test the API endpoint `PATCH /store-api/account/customer/{customerId}/default-billing-address/{addressId}` — should return 403.
- Verify the "Set as default billing address" button is absent from the Storefront address book.
- Verify that editing the billing address does not affect the shipping address and vice versa.
