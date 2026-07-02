# UpsertAddressRouteDecorator

**File:** `src/Core/Checkout/Customer/SalesChannel/UpsertAddressRouteDecorator.php`
**Decorates:** `Shopware\Core\Checkout\Customer\SalesChannel\AbstractUpsertAddressRoute`

## Purpose

Intercepts address create/update operations to enforce business rules.

## Execution Order

1. `enforceAccountType()` — sets account type and strips company/vatId for `always_private` registration
2. `concatenateHouseNumber()` — merges `topdataHouseNumber` into `street`
3. `preserveBillingCompany()` — protects billing address company from being emptied
4. → delegates to decorated `UpsertAddressRoute::upsert()`

## preserveBillingCompany()

The billing address `company` field is read-only on the edit form (handled via company name change request). Since Shopware's core `UpsertAddressRoute` overwrites the stored `company` with submitted data, the decorator re-injects the persisted company value whenever the submitted one is empty.

**Logic:**
- Only applies when `addressId` matches the customer's `defaultBillingAddressId`
- If submitted `company` is non-empty: let it pass through
- If submitted `company` is empty/falsy: fetch existing address from DB and re-inject its `company` value

## enforceAccountType()

Same logic as `RegisterRouteDecorator::enforceAccountType()` but only for `registrationAccountType`. Applied when customers edit their addresses after registration.

## concatenateHouseNumber()

Same logic as `RegisterRouteDecorator::concatenateHouseNumber()` — appends `topdataHouseNumber` to `street` if present.
