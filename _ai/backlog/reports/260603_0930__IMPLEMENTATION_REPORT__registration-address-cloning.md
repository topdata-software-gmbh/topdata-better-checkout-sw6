---
filename: "_ai/backlog/reports/260603_0930__IMPLEMENTATION_REPORT__registration-address-cloning.md"
title: "Report: Registration Address Cloning"
createdAt: 2026-06-03 09:30
updatedAt: 2026-06-03 09:30
planFile: "_ai/backlog/active/260603_0930__IMPLEMENTATION_PLAN__registration-address-cloning.md"
project: "TopdataBetterCheckoutSW6"
status: completed
filesCreated: 1
filesModified: 9
filesDeleted: 0
tags: [checkout, addresses, registration, decorator, sw6.7]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary

Implemented robust address cloning at registration to ensure **two separate `customer_address` entities** are always created, even when billing and shipping are the same. This is required for the billing address isolation architecture to function correctly.

## 2. Files Changed

### New Files (1)

| Path | Description |
|------|-------------|
| `tests/Core/Checkout/Customer/SalesChannel/RegisterRouteDecoratorTest.php` | Unit tests for address cloning behavior |

### Modified Files (9)

| Path | Description |
|------|-------------|
| `src/Core/Checkout/Customer/SalesChannel/RegisterRouteDecorator.php` | Refactored `splitAddressesAndFlagBilling` → `cloneBillingAsShippingIfEnabled`, added config gate, deep copy via `new RequestDataBag()`, added `CONFIG_PREFIX` constant |
| `src/Resources/config/config.xml` | Added Address Cloning card with `cloneBillingAsShipping` switch (default: `true`) |
| `src/Resources/snippet/en_GB/storefront.en-GB.json` | Added `TopdataBetterCheckoutSW6.cloneBillingAsShipping`/`cloneBillingAsShippingHelp` |
| `src/Resources/snippet/de_DE/storefront.de-DE.json` | German translations |
| `src/Resources/snippet/fr_FR/storefront.fr-FR.json` | French translations |
| `src/Resources/snippet/fr_CH/storefront.fr-CH.json` | Swiss French translations |
| `src/Resources/snippet/pt_PT/storefront.pt-PT.json` | Portuguese translations |
| `_ai/SPEC.md` | Updated section 2.5 and 4 with cloning docs and config table |
| `AGENTS.md` | Added `cloneBillingAsShipping` to config table + execution order note |

## 3. Key Changes

- **Method renamed**: `splitAddressesAndFlagBilling` → `cloneBillingAsShippingIfEnabled` — more descriptive
- **Config-gated**: Cloning only happens when `cloneBillingAsShipping` is `true` (default)
- **Deep copy**: Replaced PHP `clone` (shallow) with `new RequestDataBag($billingAddress->all())` — ensures no shared references to nested `DataBag` objects
- **Explicit `unset($shippingData['id'])`**: Prevents ID collisions from any form-submitted `id` field
- **Order of operations preserved**: `enforceAccountType()` runs before cloning, so `always_private` strips company/vatId from both addresses consistently

## 4. Deviations from Plan

None — all phases implemented as specified.

## 5. Technical Decisions

| Decision | Rationale |
|---|---|
| `SystemConfigService::getBool()` for config check | Correctly returns `defaultValue` from config.xml when not explicitly set |
| `$billingAddress->all()` + `new RequestDataBag()` over `clone` | Creates true independence for nested DataBag objects |
| Config default `true` | Address isolation architecture requires separate entities; disabling breaks isolation |

## 6. Testing Notes

7 unit tests cover all core scenarios:
1. Clone creates shipping address when none provided
2. Existing shipping address is not overwritten
3. Cloned shipping is independent from billing (deep copy)
4. Clone disabled doesn't create shipping address
5. `always_private` strips company/vatId before cloning
6. `id` field removed from clone
7. No clone when billing address is absent

## 7. Documentation Updates

- SPEC.md: Expanded section 2.5 with detailed cloning behavior, config key added to section 4
- AGENTS.md: Added `cloneBillingAsShipping` to config table, updated execution order for `RegisterRouteDecorator`, updated architecture note about config-gated cloning
- All 5 snippet locales: Added `TopdataBetterCheckoutSW6.cloneBillingAsShipping` and `cloneBillingAsShippingHelp`
