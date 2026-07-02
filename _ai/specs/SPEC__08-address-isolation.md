# Address Isolation

## Overview

Ensures billing and shipping addresses are always separate entities. Enforced at the database write level.

## Implementation

**File:** `src/Core/Checkout/Customer/Subscriber/CustomerAddressIsolationSubscriber.php`

Listens on `PreWriteValidationEvent` and checks all write commands for `customer` and `customer_address` entities.

### Enforced Rules

**1. Cannot change default_billing_address_id**
- If an UPDATE on `customer` changes `default_billing_address_id` → 403

**2. Billing and shipping addresses must differ**
- If after the write, `default_billing_address_id` would equal `default_shipping_address_id` → 403

**3. Cannot delete a default address**
- Before DELETE on `customer_address`, checks if the address is referenced by any customer as `default_billing_address_id` or `default_shipping_address_id`
- If so → 403

### Related

- The address cloning during registration (in `RegisterRouteDecorator`) ensures two separate entities are created from the start via `cloneBillingAsShippingIfEnabled()`
