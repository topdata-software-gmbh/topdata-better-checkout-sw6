# Granular Company Name Validation

## Overview

Provides per-address-type (billing vs. shipping) control over company name validation, with three modes.

## Implementation

**File:** `src/Core/Checkout/Customer/Subscriber/AddressValidationSubscriber.php`

Listens on 4 validation events:
- `framework.validation.customer.create`
- `framework.validation.customer.update`
- `framework.validation.address.create`
- `framework.validation.address.update`

### Config

| Config | Default | Options | Description |
|--------|---------|---------|-------------|
| `companyValidationBilling` | `core` | `core`, `required`, `optional` | Billing address company field |
| `companyValidationShipping` | `optional` | `core`, `required`, `optional` | Shipping address company field |

### Mode Behavior

| Mode | Effect |
|------|--------|
| `core` | Shopware default behavior. For business accounts: `NotBlank` constraint is kept. For private accounts: `NotBlank` is removed. |
| `required` | `NotBlank` constraint is added for business accounts. |
| `optional` | `NotBlank` constraint is removed (company becomes optional). |

### Billing Address Special Case

The billing address `company` field is **read-only** (handled via company name change request mechanism). Therefore, the subscriber **always removes** `NotBlank` from billing address validation — the company field is never in the submitted form data.

### ZIP/Country Validation

A custom `Callback` constraint `validateZipcodeCountry` is added to all address validations:

- **Liechtenstein ZIPs (9480–9499) with country CH:** error — "This is a Liechtenstein ZIP code"
- **Swiss ZIP with country LI:** error — "This ZIP code belongs to Switzerland"
- Validation only applies to 4-digit numeric ZIPs
