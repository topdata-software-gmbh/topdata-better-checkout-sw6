# Swiss Post Address Services

## Overview

Integrates Swiss Post DCAPI for address validation and autocomplete, limited to Switzerland (CH) and Liechtenstein (LI) addresses.

## Configuration

| Config | Default | Description |
|--------|---------|-------------|
| `swissPostEnabled` | `false` | Master enable switch |
| `swissPostValidationEnabled` | `true` | Enable address validation |
| `swissPostAutocompleteEnabled` | `true` | Enable address autocomplete |
| `swissPostClientId` | — | DCAPI client ID |
| `swissPostClientSecret` | — | DCAPI client secret |

## API Client

**File:** `src/Core/Content/SwissPost/SwissPostApiService.php`

### Authentication

- OAuth2 client credentials grant
- Token cached in filesystem (`logs/.swiss-post-oauth-token_{channelId}`)
- Automatic token refresh on expiry or 401 response

### Endpoints

| Feature | API Endpoint | Method |
|---------|-------------|--------|
| Address validation | `/address/v1/addresses/validation` | POST |
| ZIP autocomplete | `/address/v1/zips?zipCity={query}&type=DOMICILE` | GET |
| Street autocomplete | `/address/v1/streets?name={query}&zip={zip}` | GET |
| House number autocomplete | `/address/v1/houses?zip={zip}&streetname={street}&number={query}` | GET |

### Caching

- ZIP, street, and house number autocomplete results cached for 24 hours using Symfony Cache (PSR-6)
- Street splitting: regex-based (`/^(.+?)\s+(\d[\d\s\-\/]*(?:[a-zA-Z])?)$/u`)

## Async Address Validation

**File:** `src/Core/Checkout/Customer/Subscriber/AddressCertificationSubscriber.php`
**Message:** `src/Message/ValidateAddressMessage.php`
**Handler:** `src/Message/ValidateAddressHandler.php`

### Flow

1. `AddressCertificationSubscriber` listens on `customer_address.written`
2. If Swiss Post validation is enabled and address is CH/LI:
   - Dispatches `ValidateAddressMessage` via Symfony Messenger (async)
   - **Does NOT block** the registration/address save
3. `ValidateAddressHandler` processes the message:
   - Calls Swiss Post DCAPI validation
   - Stores quality in `customer_address.custom_fields.topdata_swiss_post_certification_status`
4. Quality values: `CERTIFIED`, `DOMICILE_CERTIFIED`, `USABLE`, `UNUSABLE`, `FIXED`, `INVALID`, `_NOT_APPLICABLE`
5. If API is unreachable, the message is retried by Symfony Messenger's retry strategy

### Running the Worker

```bash
bin/console messenger:consume async -vv
```

## Storefront Controller

**File:** `src/Controller/SwissPostStorefrontController.php`

| Route | Method | Purpose |
|-------|--------|---------|
| `/bettercheckoutsw6/swiss-post/validate` | POST | Real-time address validation |
| `/bettercheckoutsw6/swiss-post/autocomplete` | GET | ZIP/city autocomplete |
| `/bettercheckoutsw6/swiss-post/autocomplete-street` | GET | Street autocomplete |
| `/bettercheckoutsw6/swiss-post/autocomplete-house-number` | GET | House number autocomplete |
| `/bettercheckoutsw6/swiss-post/country-ids` | GET | Returns CH + LI country UUIDs |

### Validation Request Flow

1. Request includes address data with `countryId`
2. Resolves country ISO from DB
3. Checks CH/LI only, validates ZIP range
4. Calls Swiss Post API via `SwissPostApiService::validateAddress()`
5. Handles errors with `errorKey` for translation

### Autocomplete Flow

1. ZIP autocomplete returns zip + city from Swiss Post API
2. Street autocomplete returns street names filtered by zip
3. House number autocomplete returns house numbers for a street + zip
4. ZIP → maps to CH or LI country ID based on numeric range (9480–9499 = LI)
5. All results cached with 24h TTL

## Admin Controller

**Route:** `POST /api/topdata-better-checkout/swiss-post/test-credentials`
**Purpose:** Test Swiss Post API credentials from admin config UI

## Custom Fields

A custom field set `topdata_swiss_post_address_validation` is installed on plugin install/update with field `topdata_swiss_post_certification_status` on `customer_address` entity.
