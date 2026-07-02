# Plugin Overview

**Plugin:** Topdata Better Checkout SW6
**Package:** `topdata/topdata-better-checkout-sw6`
**Version:** v1.3.0
**Author:** TopData Software GmbH
**License:** MIT
**Requires:** Shopware 6.7 (`shopware/core: 6.7.*`)
**Optional:** `topdata/contact-login-sw6` — enables cross-table email uniqueness checking

## Purpose

Enhance the Shopware 6 checkout with:
- Conversion-optimized 3-box registration/login/guest selection
- Billing address isolation and lock
- Granular company name validation (per address type)
- Company name change request workflow (admin approval)
- Payment method restrictions for guest checkouts
- Swiss Post address validation & autocomplete (CH/LI)
- Configurable logout redirect

## Architecture

- **Pure PHP 8.1+** with `declare(strict_types=1)`, Twig, JSON snippets. No JS/CSS/npm/build step.
- **DI decorator pattern** (6 decorators) registered via `services.xml`
- **4 event subscribers** for validation, page extensions, address isolation
- **1 async message/handler** for Swiss Post address certification
- **Controllers** use PHP 8 `#[Route]` attributes; auto-loaded via `routes.xml` with `type="attribute"`
- **1 DB migration** — creates `tdbc_company_name_change_request` table
- **Admin UI** — Vue.js component for managing company name change requests

## File Structure

```
src/
├── TopdataBetterCheckoutSW6.php          # Plugin bootstrap (custom field setup)
├── Command/                              # CLI commands
├── Controller/                           # Storefront + Admin API controllers
│   ├── AdminApi/                         # Admin API controllers
│   ├── BillingAddressEditController.php  # Billing address edit + company name change
│   ├── SwissPostStorefrontController.php # Swiss Post validation/autocomplete
│   ├── StorefrontExampleController.php   # Example controller
│   └── AdminApiExampleController.php     # Example admin controller
├── Core/
│   ├── Checkout/
│   │   ├── Customer/
│   │   │   ├── SalesChannel/             # Route decorators (6)
│   │   │   └── Subscriber/               # Event subscribers (6)
│   │   └── Payment/
│   │       └── SalesChannel/
│   │           └── PaymentMethodRouteDecorator.php
│   └── Content/
│       ├── CompanyNameChangeRequest/     # Entity, Definition, Service
│       └── SwissPost/                    # API client, AddressQualityService
├── Message/
│   ├── ValidateAddressMessage.php        # Async message
│   └── ValidateAddressHandler.php        # Async handler
├── Migration/                            # DB migrations
├── Resources/
│   ├── app/administration/              # Admin Vue.js templates
│   ├── config/                           # services.xml, config.xml, routes.xml
│   ├── snippet/                          # i18n snippets (5 languages)
│   └── views/                            # Twig template overrides
└── Service/
    └── SwissPost/Dto/                    # SwissPost API DTOs
_ai/
├── SPEC.md                               # Original spec (outdated)
├── backlog/                              # Active/archived implementation plans
├── specs/                                # This directory
└── lessons-learned.md
```

## Decorated Routes (6)

| Decorator | Decorates | Purpose |
|-----------|-----------|---------|
| `RegisterRouteDecorator` | `RegisterRoute` | Account type enforcement, guest email blocking, address splitting |
| `UpsertAddressRouteDecorator` | `UpsertAddressRoute` | Billing company preservation, account type enforcement, house number concatenation |
| `ChangeCustomerProfileRouteDecorator` | `ChangeCustomerProfileRoute` | Customer company preservation on profile edit |
| `PaymentMethodRouteDecorator` | `PaymentMethodRoute` | Guest payment method filtering |
| `SetDefaultBillingAddressRouteDecorator` | `SwitchDefaultAddressRoute` | Billing address lock (403) |
| `ContextSwitchRouteDecorator` | `ContextSwitchRoute` | Billing address context protection |

## Event Subscribers (6)

| Subscriber | Events | Purpose |
|-----------|--------|---------|
| `AddressValidationSubscriber` | `framework.validation.customer.create/update`, `framework.validation.address.create/update` | Company field validation, zip/country validation |
| `AddressCertificationSubscriber` | `customer_address.written` | Dispatches `ValidateAddressMessage` for async Swiss Post validation |
| `CustomerAddressIsolationSubscriber` | `PreWriteValidationEvent` | Prevents billing address change/deletion |
| `LogoutRedirectSubscriber` | `KernelEvents::RESPONSE` | Configurable logout redirect |
| `CheckoutConfirmBlockSubscriber` | `CheckoutConfirmPageLoadedEvent` | Attaches pending company name change to confirm page |
| `AccountAddressPageSubscriber` | `AddressListingPageLoadedEvent`, `AddressDetailPageLoadedEvent` | Attaches pending company name change to address pages |
| `AccountProfilePageSubscriber` | `AccountProfilePageLoadedEvent` | Attaches pending company name change to profile page |

## Async Messages

| Message | Handler | Purpose |
|---------|---------|---------|
| `ValidateAddressMessage` | `ValidateAddressHandler` | Validates address via Swiss Post DCAPI, stores quality in custom_fields |

## Twig Template Overrides (25+)

Storefront templates in `src/Resources/views/storefront/` — override checkout address pages, account address book, confirm page, and various address components.

Admin templates in `src/Resources/app/administration/` — company name change admin module and customer address extension.

## Testing

No automated tests. Manual QA checklist: `TEST-CHECKLIST.md` (German, 13 categories).
