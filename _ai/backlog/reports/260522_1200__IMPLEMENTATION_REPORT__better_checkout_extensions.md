---
filename: "_ai/backlog/reports/260522_1200__IMPLEMENTATION_REPORT__better_checkout_extensions.md"
title: "Implementation Report: Better Checkout Extension for Shopware 6.7"
createdAt: 2026-05-22 12:00
updatedAt: 2026-05-22 12:00
status: completed
priority: high
tags: [shopware6, checkout, payment-restrictions, validation]
documentType: IMPLEMENTATION_REPORT
sourcePlan: "_ai/backlog/active/260522_1200__IMPLEMENTATION_PLAN__better_checkout_extensions.md"
---

## Summary
Implemented all planned phases for `TopdataBetterCheckoutSW6`:
- Added configurable payment restriction fields for private/business guest checkouts.
- Added snippet keys for guest registration duplicate-email messaging (de/en).
- Added `RegisterRoute` decorator to block guest registration when a non-guest customer with same email exists.
- Added `PaymentMethodRoute` decorator to dynamically filter blocked payment methods per guest account type.
- Updated account type storefront override to show selector only for `checkoutType=guest`, otherwise force business account type.
- Registered both decorators in DI container.

## Implemented Changes

### 1. Plugin Configuration
Updated `src/Resources/config/config.xml`:
- Added card `Payment Restrictions for Guest Checkouts`.
- Added `blockedPrivateGuestPayments` (`sw-entity-multi-id-select`, `payment_method`).
- Added `blockedBusinessGuestPayments` (`sw-entity-multi-id-select`, `payment_method`).

### 2. Snippets
Updated snippet files:
- `src/Resources/snippet/de_DE/storefront.de-DE.json`
- `src/Resources/snippet/en_GB/storefront.en-GB.json`

Added key:
- `better-checkout.register.emailAlreadyRegistered`

### 3. Guest Email Validation (Register Route Decorator)
Created:
- `src/Core/Checkout/Customer/SalesChannel/RegisterRouteDecorator.php`

Behavior:
- Detects guest registration (`guest` flag or no password).
- Checks for existing non-guest customer by email.
- Honors sales-channel binding via `core.loginRegistration.isCustomerBoundToSalesChannel`.
- Adds danger flash (when available) and throws `ConstraintViolationException` on `email` field.

### 4. Dynamic Guest Payment Filtering
Created:
- `src/Core/Checkout/Payment/SalesChannel/PaymentMethodRouteDecorator.php`

Behavior:
- Runs on payment method load.
- Applies only for authenticated guest customers.
- Resolves blocked payment IDs by account type from system config:
  - `TopdataBetterCheckoutSW6.config.blockedPrivateGuestPayments`
  - `TopdataBetterCheckoutSW6.config.blockedBusinessGuestPayments`
- Removes matching methods from route response collection.

### 5. Account Type Template Logic
Updated:
- `src/Resources/views/storefront/component/address/address-personal.html.twig`

Behavior:
- If `checkoutType == 'guest'`: renders native private/business selector (`parent()`).
- Otherwise: injects hidden `accountType` with `ACCOUNT_TYPE_BUSINESS`.

### 6. Service Registration
Updated:
- `src/Resources/config/services.xml`

Added decorators:
- `Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel\RegisterRouteDecorator`
  - decorates `Shopware\Core\Checkout\Customer\SalesChannel\RegisterRoute`
- `Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteDecorator`
  - decorates `Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRoute`

## Validation Performed
- PHP syntax checks:
  - `src/Core/Checkout/Customer/SalesChannel/RegisterRouteDecorator.php`
  - `src/Core/Checkout/Payment/SalesChannel/PaymentMethodRouteDecorator.php`
- JSON decode validation:
  - `src/Resources/snippet/de_DE/storefront.de-DE.json`
  - `src/Resources/snippet/en_GB/storefront.en-GB.json`
- XML parsing validation:
  - `src/Resources/config/config.xml`
  - `src/Resources/config/services.xml`
- Language diagnostics: no remaining reported errors in changed PHP/Twig/XML files.

## Notes
- Runtime verification steps (storefront flows and admin config behavior) were not executed in-browser in this implementation pass and should be completed in Shopware UI per the test plan.
