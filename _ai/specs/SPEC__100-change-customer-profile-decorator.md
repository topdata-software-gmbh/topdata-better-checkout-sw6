# ChangeCustomerProfileRouteDecorator

**File:** `src/Core/Checkout/Customer/SalesChannel/ChangeCustomerProfileRouteDecorator.php`
**Decorates:** `Shopware\Core\Checkout\Customer\SalesChannel\AbstractChangeCustomerProfileRoute`

## Purpose

Protects the `customer.company` field from being emptied via the profile edit page.

## preserveCompany()

The company field on the profile edit page is read-only (company name change request mechanism) and never submitted with the form. Shopware's core `ChangeCustomerProfileRoute` either:
- Adds a `NotBlank` constraint on `company` for business accounts → validation failure
- Or overwrites `customer.company` with the missing/empty value

**Logic:**
- If submitted `company` is non-empty: let it pass through
- If submitted `company` is empty/falsy: fetch existing customer from DB and re-inject its `company` value
