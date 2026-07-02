# RegisterRouteDecorator

**File:** `src/Core/Checkout/Customer/SalesChannel/RegisterRouteDecorator.php`
**Decorates:** `Shopware\Core\Checkout\Customer\SalesChannel\RegisterRoute`

## Execution Order

1. `assertGuestEmailNotRegistered()` — blocks guest checkout if email is already used by a registered customer
2. `enforceAccountType()` — sets account type based on config (may strip company/vatId)
3. `concatenateHouseNumber()` — merges `topdataHouseNumber` field into `street`
4. `cloneBillingAsShippingIfEnabled()` — clones billing address as shipping (creates 2 separate entities)
5. → delegates to decorated `RegisterRoute::register()`

## 1. Duplicate Guest Email Blocking

Two strategies (tried in order):

**A. ContactLoginSW6 integration** (if plugin `TopdataContactLoginSW6` is available):
- Uses `ContactEmailUniquenessService` for cross-table (contacts + customers) uniqueness check
- Includes attempt logging with IP, User-Agent, sales channel

**B. Fallback:**
- Checks `customer.repository` for existing non-guest customer with same email
- Respects `core.loginRegistration.isCustomerBoundToSalesChannel` config

## 2. Configurable Account Types

| Config | Scope | Default | Options |
|--------|-------|---------|---------|
| `guestAccountType` | Guest checkout | `user_choice` | `user_choice`, `always_private`, `always_business` |
| `registrationAccountType` | Registration | `always_business` | `user_choice`, `always_private`, `always_business` |

When `always_private`:
- Sets `accountType` to `private`
- Removes `company` and `vatId` from both the data bag and billing address

When `always_business`:
- Sets `accountType` to `business`

When `user_choice`:
- No override (user picks via dropdown)

## 3. Address Splitting (Cloning)

**Config:** `cloneBillingAsShipping` (bool, default `true`)

When enabled and no separate shipping address is provided:
- Billing address is deep-copied into `shippingAddress` in `RequestDataBag`
- `id` field is explicitly removed from the clone to prevent ID collisions
- Result: two separate `customer_address` DB rows instead of sharing one

Order matters: `enforceAccountType()` runs **before** cloning, so private accounts don't leak company info into the cloned shipping address.

## 4. House Number Concatenation

If a `topdataHouseNumber` field is present in the address data, it gets appended to the `street` field before the address is saved. This handles the German/Swiss address format where house number is often a separate field in the frontend.
