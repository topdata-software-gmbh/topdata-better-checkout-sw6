# Billing Address Lock

## Overview

The default billing address is locked — customers cannot change which address is their billing address. Instead, they must edit the existing billing address in-place.

## SetDefaultBillingAddressRouteDecorator

**File:** `src/Core/Checkout/Customer/SalesChannel/SetDefaultBillingAddressRouteDecorator.php`
**Decorates:** `Shopware\Core\Checkout\Customer\SalesChannel\SwitchDefaultAddressRoute`

- Blocks `TYPE_BILLING` swaps with **403 AccessDeniedHttpException**
- Blocks setting the billing address as the default shipping address (also 403)
- Shipping address swaps still work (except for billing → shipping)

## ContextSwitchRouteDecorator

**File:** `src/Core/Checkout/Customer/SalesChannel/ContextSwitchRouteDecorator.php`
**Decorates:** `Shopware\Core\System\SalesChannel\SalesChannel\ContextSwitchRoute`

- Silently removes `billingAddressId` from context switch requests when a customer is logged in
- Prevents changing billing address via context switch (which would bypass the main lock)

## CustomerAddressIsolationSubscriber

**File:** `src/Core/Checkout/Customer/Subscriber/CustomerAddressIsolationSubscriber.php`

Listens on `PreWriteValidationEvent` to enforce two invariants:

1. **Default billing address cannot be changed:**
   - Detects `UPDATE` commands on `customer` entity with `default_billing_address_id` change
   - Throws 403 if someone tries to change it

2. **Billing and shipping addresses must never be the same:**
   - If both `default_billing_address_id` and `default_shipping_address_id` are being set to the same value → 403

3. **Cannot delete a default address:**
   - Before allowing `DELETE` on `customer_address`, checks if the address is referenced as `default_billing_address_id` or `default_shipping_address_id`
   - If so → 403

## Admin UI

The administration templates override billing address selection:

- `sw-customer-address-form-options.html.twig`: Removes the "Set as default billing address" checkbox entirely
- `sw-customer-detail-addresses.html.twig`: Disables the billing address radio button and context menu option

## Storefront UI

The `address-default.html.twig` template override removes the "Set as default billing address" button from the storefront address book.
