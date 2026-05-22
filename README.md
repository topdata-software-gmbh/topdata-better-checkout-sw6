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
 - Enforces the Business account type on the standard registration page (route: `frontend.account.login.page`) while allowing customers to choose Private or Business during guest checkout.
 - Adds storefront snippets for English (en-GB) and German (de-DE) to make the boxes translatable.

 How it works (technical summary)

 - Template overrides are provided under `src/Resources/views/storefront/page/checkout/address` and `src/Resources/views/storefront/component`.
 - The main selection UI is injected into the checkout address index template. When a user clicks one of the boxes the plugin appends `?checkoutType=register` or `?checkoutType=guest` to the register route so the chosen flow is preserved.
 - The registration form receives a hidden `checkoutType` input when a choice was made, and the guest-registration checkbox is enforced/hidden by the register template override.
 - The account type field is forced to Business on the standard registration page by injecting a hidden input with the Business account constant.

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
 3. Clear cache / rebuild storefront if necessary.

 Requirements

 - Shopware 6.7.x

 Support

 For issues or questions, open an issue against this repository or contact TopData Software GmbH: https://www.topdata.de

 License

 MIT
