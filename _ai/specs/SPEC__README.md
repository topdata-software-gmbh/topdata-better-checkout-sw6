# Topdata Better Checkout SW6 — Specifications

This directory contains detailed specifications reverse-engineered from the codebase.

## Files

| File | Description |
|------|-------------|
| `00-overview.md` | Plugin overview, version, requirements, architecture |
| `01-checkout-selection.md` | 3-box checkout selection screen |
| `02-register-decorator.md` | RegisterRouteDecorator — account types, guest email blocking, address splitting |
| `03-billing-address-lock.md` | Billing address lock (SetDefaultBillingAddressRouteDecorator, ContextSwitchRouteDecorator) |
| `04-billing-address-edit.md` | Edit billing address on confirm page (BillingAddressEditController) |
| `05-payment-restrictions.md` | Payment method restrictions for guest checkouts |
| `06-company-validation.md` | Granular company name validation (AddressValidationSubscriber) |
| `07-company-rename-requests.md` | Company name change requests — full workflow |
| `08-address-isolation.md` | CustomerAddressIsolationSubscriber — address isolation enforcement |
| `09-swiss-post.md` | Swiss Post address validation and autocomplete |
| `10-logout-redirect.md` | Logout redirect subscriber |
| `11-address-book.md` | Address book UI modifications |
| `12-admin-ui.md` | Administration UI modifications |
| `13-snippets.md` | Multilingual snippets |
| `14-configuration.md` | Full configuration reference |
| `15-commands.md` | CLI commands |
