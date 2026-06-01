# Topdata Better Checkout SW6 — Specification

**Version:** 1.1.0
**Author:** TopData Software GmbH
**Requires:** Shopware 6.7

---

## 1. Purpose

Replace the default mixed login/register/guest-checkout page with a conversion-optimized 3-box selection screen. Add deep billing address isolation, granular company name validation, and payment method restrictions for guest checkouts.

---

## 2. Features

### 2.1 3-Box Checkout Selection Screen
- Replaces `/checkout/address` with three selection cards: **Register**, **Login** (inline form), **Guest**
- Shown only when no `checkoutType` is set and customer is not logged in
- `checkoutType` parameter (`register`|`guest`) flows through the entire registration process (query param → hidden form input → `RequestDataBag`)

### 2.2 Guest Checkout Password Management
- Guest: hidden checked `guest=1` + hidden `createCustomerAccount` checkbox → Shopware JS hides password fields
- Register: hidden unchecked `createCustomerAccount` → password fields visible

### 2.3 Configurable Account Types
| Setting | Default | Options |
|---|---|---|
| `guestAccountType` | `user_choice` | `user_choice`, `always_private`, `always_business` |
| `registrationAccountType` | `always_business` | `user_choice`, `always_private`, `always_business` |

Template hides account-type dropdown when forced; backend (`RegisterRouteDecorator`) enforces value on `RequestDataBag`.

### 2.4 Duplicate Guest Email Blocking
- Blocks guest checkout when email is already used by a registered customer
- Respects `isCustomerBoundToSalesChannel` config

### 2.5 Isolated Billing & Shipping Addresses (Address Splitting)
- When no separate shipping address is provided, billing address is **cloned** into a distinct `shippingAddress` entry
- Creates two separate database rows instead of sharing one address entity

### 2.6 Billing Address Lock
- Blocks `PATCH /store-api/account/customer/{id}/default-billing-address/{addressId}` (403 via `SetDefaultBillingAddressRouteDecorator`)
- Silently removes `billingAddressId` from context switch requests (`ContextSwitchRouteDecorator`)
- Removes "Set as default billing address" button from the storefront address book

### 2.7 "Edit Billing Address" on Confirm Page
- Replaces "Change billing address" (modal selection) with direct link to edit the current billing address

### 2.8 Payment Method Restrictions for Guest Checkouts
| Setting | Type | Description |
|---|---|---|
| `blockedPrivateGuestPayments` | entity-multi-id-select | Payment methods blocked for private guest checkouts |
| `blockedBusinessGuestPayments` | entity-multi-id-select | Payment methods blocked for business guest checkouts |

- Registered customers are unaffected
- Filtering at the API route level (`PaymentMethodRouteDecorator`)

### 2.9 Granular Company Name Validation
| Setting | Default | Options |
|---|---|---|
| `companyValidationBilling` | `core` | `core`, `required`, `optional` |
| `companyValidationShipping` | `optional` | `core`, `required`, `optional` |

- Frontend: HTML5 `required` attribute set dynamically per address type
- Backend: `AddressValidationSubscriber` adds/removes `NotBlank` Symfony constraints on `company` for customer and address create/update events
- Billing vs. shipping identification via `defaultBillingAddressId` comparison

### 2.10 Multilingual Snippets
- **Languages:** de-DE, en-GB, fr-FR, fr-CH, pt-PT
- Covers 3-box UI labels, email-already-registered error, confirm-page billing address edit link

---

## 3. Technical Architecture

### Decorated Routes (Symfony DI)

| Decorator | Decorates | Purpose |
|---|---|---|
| `RegisterRouteDecorator` | `RegisterRoute` | Account type enforcement, email blocking, address splitting |
| `PaymentMethodRouteDecorator` | `PaymentMethodRoute` | Guest payment method filtering |
| `SetDefaultBillingAddressRouteDecorator` | `SwitchDefaultAddressRoute` | Billing address lock (403) |
| `ContextSwitchRouteDecorator` | `ContextSwitchRoute` | Billing address context protection |

### Event Subscribers

| Subscriber | Events |
|---|---|
| `AddressValidationSubscriber` | `framework.validation.customer.create`, `framework.validation.customer.update`, `framework.validation.address.create`, `framework.validation.address.update` |

### Twig Template Overrides (8 files)

| Template | Block Override | Purpose |
|---|---|---|
| `page/checkout/address/index.html.twig` | `page_checkout_main_content`, login, register blocks | 3-box UI |
| `page/checkout/address/register.html.twig` | `page_checkout_register_personal_guest` | Guest/register checkbox management |
| `page/checkout/confirm/confirm-address.html.twig` | `page_checkout_confirm_address_billing_actions` | Edit instead of change billing address |
| `component/account/register.html.twig` | `component_account_register_form_action` | Hidden `checkoutType` input |
| `component/address/address-personal.html.twig` | `component_address_personal_account_type` | Config-driven account type hidden input |
| `component/address/address-personal-company.html.twig` | `component_account_register_company_fields` | Dynamic company validation |
| `component/address/address-default.html.twig` | `component_address_default_billing` | Remove default billing button |

---

## 4. Configuration Summary

| Card | Fields |
|---|---|
| Account Type Settings | `guestAccountType`, `registrationAccountType` |
| Payment Restrictions (Guest) | `blockedPrivateGuestPayments`, `blockedBusinessGuestPayments` |
| Company Name Validation | `companyValidationBilling`, `companyValidationShipping` |

---

## 5. Limitations & Dependencies

- **Zero custom CSS/JS** — relies entirely on Shopware core Bootstrap and native JS behavior
- **No database migrations** — all state is in `system_config` via `config.xml`
- **No third-party integrations** — only depends on `shopware/core: 6.7.*`
- **No Storefront API or SPA support** beyond standard Storefront template overrides
