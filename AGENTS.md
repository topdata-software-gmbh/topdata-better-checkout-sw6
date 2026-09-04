# AGENTS.md — topdata-better-checkout-sw6

Shopware 6.7 plugin that rewires the storefront checkout (3-box register/login/guest selector, isolated billing/shipping addresses, billing-address lock, guest payment restrictions, company-name validation, async Swiss Post address validation, company-name change requests).

## Read first

- `_ai/SPEC.md` — canonical feature/architecture reference.
- `_ai/lessons-learned.md` — non-obvious gotchas (e.g. theme templates shadowing plugin Twig overrides; `address-actions.html.twig` is dead in 6.7; use `config()` in Twig instead of full template copy-paste).
- `_ai/backlog/active/` — in-progress implementation plans.

## Architecture

- **PHP 8.1+** (`declare(strict_types=1)`), Twig, Vue 3 (admin), JSON snippets. No build step, no npm/webpack.
- **Plugin class is NOT empty** — `src/TopdataBetterCheckoutSW6.php` installs/removes the `topdata_swiss_post_address_validation` custom-field set on `customer_address` (install + postUpdate + uninstall). Idempotent (skips if set already exists).
- **DI decorators** (`<service decorates="...">` in `src/Resources/config/services.xml`) — 6 of them:
  - `RegisterRouteDecorator` → `Shopware\Core\Checkout\Customer\SalesChannel\RegisterRoute` — account-type enforcement, guest-email blocking, billing→shipping cloning.
  - `SetDefaultBillingAddressRouteDecorator` → `SwitchDefaultAddressRoute` — 403 on default-billing change.
  - `ContextSwitchRouteDecorator` → `ContextSwitchRoute` — strips `billingAddressId` from context switches.
  - `PaymentMethodRouteDecorator` → `PaymentMethodRoute` — filters blocked guest payment methods.
  - `UpsertAddressRouteDecorator` → `UpsertAddressRoute` — company-name validation enforcement.
  - `ChangeCustomerProfileRouteDecorator` → `ChangeCustomerProfileRoute`.
- **Event subscribers** (7): `AddressValidationSubscriber`, `AddressCertificationSubscriber`, `LogoutRedirectSubscriber`, `CustomerAddressIsolationSubscriber`, `CheckoutConfirmBlockSubscriber`, `AccountAddressPageSubscriber`, `AccountProfilePageSubscriber`. Registered with `<tag name="kernel.event_subscriber"/>`.
- **Async message**: `ValidateAddressMessage` → `ValidateAddressHandler`. Dispatched by `AddressCertificationSubscriber` on `customer_address.written` events. Requires `bin/console messenger:consume async -vv` to process.
- **Custom DAL entity**: `CompanyNameChangeRequestDefinition` (`tdbc_company_name_change_request` repo) + table created by `src/Migration/Migration1748979000CreateCompanyNameChangeRequestTable.php`.
- **Controllers** (`#[Route]` attributes, auto-loaded via `routes.xml` `type="attribute"`):
  - `BillingAddressEditController` (storefront modal edit of billing address)
  - `SwissPostStorefrontController` (AJAX validation/autocomplete)
  - `CompanyNameChangeRequestController` (admin API)
  - `SwissPostAdminController` (admin API)
  - Example/scaffolding: `StorefrontExampleController`, `AdminApiExampleController`.
- **Console commands**: `topdata:better-checkout:test-swiss-post` (raw API probe — `--street`, `--raw`), `:backfill-address-quality`, `:certification-stats`, `:diff-fixed-addresses`, `:enforce-address-separation`. Plus example `ExampleCommand`.
- **Twig overrides** in `src/Resources/views/storefront/` — checkout (`page/checkout/address`, `page/checkout/confirm`), account (`page/account/addressbook`, `page/account/profile`), address components, modals, and email templates under `views/email/`. All use `{% sw_extends %}` / `{% sw_include %}`.
- **Admin Vue module** under `src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/` (list + detail pages) plus extensions to `sw-customer-detail-addresses` and `sw-customer-address-form-options`.

### RegisterRouteDecorator execution order

`assertGuestEmailNotRegistered()` → `enforceAccountType()` → `cloneBillingAsShippingIfEnabled()` → delegate to decorated route. **Order matters** — `enforceAccountType()` may strip `company`/`vatId` before cloning, so private accounts don't leak company info into the cloned shipping address.

### Template gotcha

Shopware Twig precedence is **core → plugin → theme**. Active theme wins. If a Twig override doesn't appear to take effect, check whether the active theme (or another higher-priority plugin) is copying the entire template — see `_ai/lessons-learned.md`.

## Configuration

All keys live under the `TopdataBetterCheckoutSW6.config.` prefix in code (system_config via `src/Resources/config/config.xml`).

| Key | Default | Type | Notes |
|---|---|---|---|
| `logoutRedirectRoute` | `frontend.home.page` | textarea | Route name, URL path, or multi-line `locale=target` map (`#` comments, `_default` fallback, exact `de-DE` → `de` (2-letter) → `_default`). |
| `guestAccountType` | `user_choice` | single-select | `user_choice` / `always_private` / `always_business`. |
| `registrationAccountType` | `always_business` | single-select | same options. |
| `blockedPrivateGuestPayments` | — | sw-entity-multi-id-select (`payment_method`) | |
| `blockedBusinessGuestPayments` | — | sw-entity-multi-id-select (`payment_method`) | |
| `addressDropdownIcon` | `paper-pencil` | text | Twig icon name (e.g. `more-vertical`). |
| `showPhoneNumberOnAddressCards` | `false` | bool | |
| `cloneBillingAsShipping` | `true` | bool | Required for billing-address isolation. |
| `companyValidationBilling` | `core` | single-select | `core` / `required` / `optional`. |
| `companyValidationShipping` | `optional` | single-select | same options. |
| `companyNameChangeNotificationEnabled` | `true` | bool | |
| `companyNameChangeNotificationEmail` | `` | text | Empty → fallback to `core.basicInformation.email` / `core.mailerSettings.mailerSender`. |
| `swissPostEnabled` | `false` | bool | Master switch for Swiss Post DCAPI. |
| `swissPostValidationEnabled` | `true` | bool | |
| `swissPostAutocompleteEnabled` | `true` | bool | |
| `swissPostClientId` | — | text | OAuth2 client-credentials flow. |
| `swissPostClientSecret` | — | password | |

## Snippets

5 locales: `en_GB`, `de_DE`, `fr_FR`, `fr_CH`, `pt_PT` under `src/Resources/snippet/`. Implemented via `SnippetFileInterface`. Keys include `better-checkout.*` and `checkout.confirmChangeBillingAddress`.

## Testing

- **No automated tests** — `tests/` is scaffolding only. Validate changes against `TEST-CHECKLIST.md` (German, 13 categories) by hand.
- The `tests/Core/Checkout/Customer/` directory exists but is empty.

## Code conventions

- Constructor property promotion with `private readonly` where possible.
- Private methods for extracted logic, named descriptively.
- No PHPDoc on trivial methods.
- `SystemConfigService::getString()` for scalar config, `get()` for booleans / entity IDs.
- Service arguments resolved via FQCN — **check `services.xml` first** before adding constructor params (decorators are wired manually there, not by autowire).

## Operational notes

- **Async validation requires a worker** — run `bin/console messenger:consume async -vv` (or via supervisor) or address certifications pile up in the queue.
- **Swiss Post credentials** are configured per sales channel in the admin; the API client (`Core/Content/SwissPost/SwissPostApiService`) is autowired.
- **Theme interactions** are the #1 source of "my change didn't show" reports — verify with the default Shopware theme before debugging the plugin.
