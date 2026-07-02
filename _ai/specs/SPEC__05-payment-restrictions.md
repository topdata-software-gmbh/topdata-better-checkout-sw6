# Payment Method Restrictions for Guest Checkouts

## Overview

Allows blocking specific payment methods for guest checkouts, with different restrictions for private vs. business account types.

## Implementation

**File:** `src/Core/Checkout/Payment/SalesChannel/PaymentMethodRouteDecorator.php`
**Decorates:** `Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute`

### Behavior

1. Loads the original payment methods list via decorated route
2. If customer is NOT a guest: returns original list unchanged
3. If customer IS a guest:
   - Checks `accountType` (private or business)
   - Loads the appropriate config key
   - Filters out blocked payment methods from the response

### Config Keys

| Config Key | Type | Description |
|-----------|------|-------------|
| `blockedPrivateGuestPayments` | entity-multi-id-select | Payment methods blocked for `private` guest checkouts |
| `blockedBusinessGuestPayments` | entity-multi-id-select | Payment methods blocked for `business` guest checkouts |

### Scope

- Affects the **PaymentMethodRoute** only (API route listing)
- Registered customers are unaffected
- Blocked methods are removed from the list returned to the storefront
