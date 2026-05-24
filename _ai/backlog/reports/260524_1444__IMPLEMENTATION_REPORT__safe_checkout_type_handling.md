---
filename: "_ai/backlog/reports/260524_1444__IMPLEMENTATION_REPORT__safe_checkout_type_handling.md"
title: "Report: Safe checkoutType variable handling in Twig templates"
createdAt: 2026-05-24 14:44
updatedAt: 2026-05-24 14:44
planFile: "_ai/backlog/active/260524_1444__IMPLEMENTATION_PLAN__safe_checkout_type_handling.md"
project: "topdata-better-checkout-sw6"
status: completed
filesCreated: 1
filesModified: 4
filesDeleted: 0
tags: [shopware, twig, checkout, bugfix]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
The templates within the Better Checkout plugin were modified to safely extract the transient request parameter `checkoutType`. This fixes a `PropertyNotFoundException` raised in Shopware 6.7 during address editing flows when a database address entity is present in the Twig template context.

## 2. Files Changed
### New Files
- `_ai/backlog/reports/260524_1444__IMPLEMENTATION_REPORT__safe_checkout_type_handling.md`: This post-implementation report.

### Modified Files
- `src/Resources/views/storefront/component/account/register.html.twig`
- `src/Resources/views/storefront/component/address/address-personal.html.twig`
- `src/Resources/views/storefront/page/checkout/address/index.html.twig`
- `src/Resources/views/storefront/page/checkout/address/register.html.twig`

## 3. Key Changes
- Replaced the generic `data.get is defined` validation with a structural `data.all is defined` condition.
- Isolated logic so that any `CustomerAddressEntity` objects bound to the `data` variable are bypassed safely.
- Harmonized the `checkoutType` set block across all four modified templates.

## 4. Technical Decisions
- **Structural contract verification**: Used `data.all is defined` to distinguish request-level containers from DAL entities without requiring complex class name matching in Twig templates.
- **SOLID Principles**: Adhered to the Single Responsibility Principle, ensuring templates limit transient parameter inspection to appropriate request-level containers.

## 5. Testing Notes
- Cleared caches via `bin/console cache:clear`.
- Successfully registered and completed guest checkouts.
- Triggered address modifications during checkout and confirmed that the selection modals and edit forms rendered successfully.

## 6. Documentation Updates
No merchant-facing documentation changes were necessary as the settings and features remain unchanged.