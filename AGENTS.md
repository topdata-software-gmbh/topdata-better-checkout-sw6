# AGENTS.md — topdata-better-checkout-sw6

## Spec reference

Full feature spec at `_ai/SPEC.md` — read first for architecture, feature list, template overrides, and decorated routes.

## Architecture

- **Pure PHP 8.1+** (`declare(strict_types=1)`) + Twig + JSON snippets. No JS, no CSS, no npm/webpack, no build step.
- **No plugin lifecycle overrides** — `TopdataBetterCheckoutSW6.php` is empty. All wiring is in `src/Resources/config/services.xml`.
- **DI decorator pattern** (not inheritance) — 4 decorators registered with `<service decorates="...">` in services.xml:
  - `RegisterRouteDecorator` → account type enforcement, guest email blocking, address splitting (config-gated cloning)
  - `PaymentMethodRouteDecorator` → guest payment filtering
  - `SetDefaultBillingAddressRouteDecorator` → billing address lock (403)
  - `ContextSwitchRouteDecorator` → billing address context protection
- **2 event subscribers**: `AddressValidationSubscriber` (company validation), `AddressCertificationSubscriber` (dispatches `ValidateAddressMessage` for async Swiss Post validation)
- **1 async message/handler**: `ValidateAddressMessage` / `ValidateAddressHandler` (processed by `bin/console messenger:consume async`)
- **8 Twig overrides** — all use `{% sw_extends %}` to extend core blocks
- **Controllers** use PHP 8 `#[Route]` attributes; auto-loaded via `routes.xml` with `type="attribute"`
- **No DB migrations** — all state is `system_config` via `config.xml`
- **11 active/in-progress backlog plans** in `_ai/backlog/active/`

## Testing

- **No automated tests** — `tests/` directory is empty (only `.gitkeep`).
- **Manual QA checklist**: `TEST-CHECKLIST.md` (German, 13 categories). Run through relevant scenarios after changes.

## Configuration

| Config key | Default | Description |
|---|---|---|
| `guestAccountType` | `user_choice` | `user_choice` / `always_private` / `always_business` |
| `registrationAccountType` | `always_business` | `user_choice` / `always_private` / `always_business` |
| `blockedPrivateGuestPayments` | — | entity-multi-id-select |
| `blockedBusinessGuestPayments` | — | entity-multi-id-select |
| `companyValidationBilling` | `core` | `core` / `required` / `optional` |
| `companyValidationShipping` | `optional` | `core` / `required` / `optional` |
| `cloneBillingAsShipping` | `true` | `true` / `false` |
| `logoutRedirectRoute` | `frontend.home.page` | route name, URL path, or multi-line locale map (see below) |

  > **Multi‑line locale map** – each line: `locale=target`. Lines starting with `#` are ignored.  
  > Fallback: exact `de-DE` → `de` (2‑letter) → `_default`.  
  > Example:  
  > ```text
  > de-DE=/Newsletter
  > fr-FR=/fr/Newsletter
  > _default=frontend.home.page
  > ```

Config keys always prefixed with `TopdataBetterCheckoutSW6.config.` in code.

## Snippets

5 languages: en-GB, de-DE, fr-FR, fr-CH, pt-PT. Implemented via `SnippetFileInterface` in `src/Resources/snippet/`. Keys: `better-checkout.*`, `checkout.confirmChangeBillingAddress`.

- **RegisterRouteDecorator execution order**: `assertGuestEmailNotRegistered()` → `enforceAccountType()` → `cloneBillingAsShippingIfEnabled()` → delegate to decorated route. The order matters because `enforceAccountType()` may strip company/vatId before cloning.

## Code conventions

- Constructor property promotion with `private readonly` where possible
- Private methods for extracted logic, named descriptively
- No PHPDoc on trivial methods
- `SystemConfigService::getString()` for scalar config, `get()` for booleans/entity IDs
- Service arguments resolved via FQCN — check `services.xml` before adding new dependencies
