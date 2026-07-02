# Configuration Reference

All config keys are prefixed with `TopdataBetterCheckoutSW6.config.` in code.

## Logout Settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `logoutRedirectRoute` | textarea | `frontend.home.page` | Route/URL after logout. Supports per-locale map: `locale=target` per line |

## Account Type Settings

| Key | Type | Default | Options | Description |
|-----|------|---------|---------|-------------|
| `guestAccountType` | single-select | `user_choice` | `user_choice`, `always_private`, `always_business` | Guest checkout account type |
| `registrationAccountType` | single-select | `always_business` | `user_choice`, `always_private`, `always_business` | Registration account type |

## Payment Restrictions

| Key | Type | Description |
|-----|------|-------------|
| `blockedPrivateGuestPayments` | entity-multi-id-select | Blocked payment methods for private guest checkouts |
| `blockedBusinessGuestPayments` | entity-multi-id-select | Blocked payment methods for business guest checkouts |

## Address Book Settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `addressDropdownIcon` | text | `paper-pencil` | Icon for address options dropdown |
| `showPhoneNumberOnAddressCards` | bool | `false` | Show phone number on address cards |

## Address Cloning

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `cloneBillingAsShipping` | bool | `true` | Clone billing address as separate shipping address entity |

## Company Name Validation

| Key | Type | Default | Options | Description |
|-----|------|---------|---------|-------------|
| `companyValidationBilling` | single-select | `core` | `core`, `required`, `optional` | Billing address company field validation |
| `companyValidationShipping` | single-select | `optional` | `core`, `required`, `optional` | Shipping address company field validation |

## Company Name Change Request

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `companyNameChangeNotificationEnabled` | bool | `true` | Enable admin notification emails |
| `companyNameChangeNotificationEmail` | text | (empty) | Admin email recipient (falls back to `core.basicInformation.email`) |

## Swiss Post Address Services

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `swissPostEnabled` | bool | `false` | Master enable switch |
| `swissPostValidationEnabled` | bool | `true` | Enable address validation |
| `swissPostAutocompleteEnabled` | bool | `true` | Enable autocomplete |
| `swissPostClientId` | text | — | DCAPI client ID |
| `swissPostClientSecret` | password | — | DCAPI client secret |
