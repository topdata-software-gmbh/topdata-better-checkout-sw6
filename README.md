# Topdata Better Checkout SW6

![Plugin Icon](src/Resources/config/plugin.png)

Overview

Topdata Better Checkout SW6 improves the Shopware storefront checkout by replacing the default single-entry flow with a lightweight, native 3-box selection screen that lets customers choose between:
- Register as a new customer
- Login with an existing account
- Continue as guest

The plugin is intentionally small and implemented as Storefront template overrides and storefront snippets so it integrates cleanly with Shopware 6.7.

Features

- Shows a 3-box selection on the checkout address page when no explicit choice was made.
- Preserves the normal checkout flow once a choice is selected (uses a `checkoutType` request parameter).
- Hides or forces the guest/registration checkbox depending on the chosen flow to avoid confusion.
- Configurable account type for guest checkout (user choice / always private / always business).
- Configurable account type for registration (user choice / always private / always business, defaults to always business).
- Backend enforcement of account type via `RegisterRoute` decoration ensures the correct value is persisted regardless of template quirks.
- Blocks registered guest checkout with an email already used by a full customer account.
- Payment method restrictions for guest customers based on account type (private vs business).
- Adds storefront snippets for English (en-GB) and German (de-DE) to make the boxes translatable.

Configuration

In the Shopware Administration under the plugin settings:
- **Guest Checkout Account Type** — controls the `accountType` for guest orders (`user_choice` / `always_private` / `always_business`, default: user choice).
- **Registration Account Type** — controls the `accountType` for new customer registrations (`user_choice` / `always_private` / `always_business`, default: always business).
- **Blocked payment methods for Private Guest Checkouts** — payment methods hidden for guests with a private account type.
- **Blocked payment methods for Business Guest Checkouts** — payment methods hidden for guests with a business account type.

How it works (technical summary)

- Template overrides are provided under `src/Resources/views/storefront/page/checkout/address` and `src/Resources/views/storefront/component`.
- The main selection UI is injected into the checkout address index template. When a user clicks one of the boxes the plugin appends `?checkoutType=register` or `?checkoutType=guest` to the register route so the chosen flow is preserved.
- The registration form receives a hidden `checkoutType` input when a choice was made, and the guest-registration checkbox is enforced/hidden by the register template override.
- The account type field on registration pages reads the plugin config: for `always_business` or `always_private` a hidden input is rendered; for `user_choice` the native Shopware selector is displayed.
- `RegisterRouteDecorator` enforces the configured account type on the `RequestDataBag` before passing it to the core `RegisterRoute`, ensuring the correct value is always persisted to the database regardless of what the template or upstream controller sends.
- `PaymentMethodRouteDecorator` filters out blocked payment methods for guest customers based on their account type.
- Template overrides are scoped to registration routes only (`frontend.checkout.register.page`, `frontend.account.register.page`) to avoid interfering with standard address editing.

Usage / Examples

- Open the checkout address page (normally `/checkout/address`). If no flow is selected the 3-box chooser appears.
- Clicking "Create an account" navigates to the register page with `?checkoutType=register`.
- Clicking "Order as guest" navigates to the register page with `?checkoutType=guest` and submits the form as guest.

Snippets (translations)

- English: `src/Resources/snippet/en_GB/storefront.en-GB.json`
- German:  `src/Resources/snippet/de_DE/storefront.de-DE.json`

Installation

1. Copy or upload the plugin folder to your Shopware `custom/plugins` directory (or install via your preferred method).
2. Install and activate the plugin in the Shopware Administration.
3. Review and adjust the plugin configuration under the plugin settings.
4. Clear cache / rebuild storefront if necessary.

Requirements

- Shopware 6.7.x

Support

For issues or questions, open an issue against this repository or contact TopData Software GmbH: https://www.topdata.de

License

MIT
