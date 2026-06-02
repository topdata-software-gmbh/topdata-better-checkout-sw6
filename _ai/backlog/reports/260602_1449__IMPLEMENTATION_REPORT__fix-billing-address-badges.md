---
filename: "_ai/backlog/reports/260602_1449__IMPLEMENTATION_REPORT__fix-billing-address-badges.md"
title: "Report: Fix billing address badges in account address book"
createdAt: 2026-06-02 14:49
updatedAt: 2026-06-02 14:49
planFile: "_ai/backlog/active/260602_1449__IMPLEMENTATION_PLAN__fix-billing-address-badges.md"
project: "TopdataBetterCheckoutSW6"
status: completed
filesCreated: 1
filesModified: 2
filesDeleted: 0
tags: [storefront, twig, templates, bugfix]
documentType: IMPLEMENTATION_REPORT
---

## Summary
Fixed two visual bugs in the customer account address book (`/account/address`) where billing and shipping address badges were incorrectly displayed due to hardcoded boolean flags in the Twig templates. The billing address badge has been intentionally suppressed from the general address list to maintain a clean UI, as the billing address is already prominently displayed in its own dedicated section.

## Files Changed
- **Created:**
  - `_ai/backlog/reports/260602_1449__IMPLEMENTATION_REPORT__fix-billing-address-badges.md`: This report.
- **Modified:**
  - `src/Resources/views/storefront/page/account/addressbook/address-manager.html.twig`
  - `src/Resources/views/storefront/page/account/addressbook/address-item.html.twig`

## Key Changes

### `address-manager.html.twig`
1. **Fixed default billing address shipping flag:** Changed `defaultShipping: true` to `defaultShipping: defaultShippingAddress.id == defaultBillingAddress.id`. This ensures the shipping badge only appears on the default billing address if it is actually the same as the default shipping address.
2. **Fixed general address list billing flag:** Changed `defaultBilling: true` to `defaultBilling: false` inside the loop that renders available addresses (excluding the billing address). This prevents all addresses from incorrectly showing the billing badge.

### `address-item.html.twig`
1. **Suppressed billing badge:** Replaced the custom `address_item_badge` block logic with a context manipulation approach. The block temporarily sets `defaultBilling = false`, calls `parent()` only when `defaultShipping` is true, then restores the original value. This ensures:
   - The billing address badge is never rendered (by design, to avoid redundancy with the dedicated billing section).
   - The shipping badge is still correctly rendered by native Shopware core behavior when appropriate.

## Deviations from Plan
None. All changes were implemented exactly as specified in the plan.

## Testing Notes
1. Log into the storefront as a customer.
2. Navigate to `My Account -> Addresses` (`/account/address`).
3. Verify that the default billing address in the dedicated top section does **not** show a "Rechnungsadresse" badge inside its card.
4. Verify that addresses in the general list below do **not** show a "Rechnungsadresse" badge.
5. If the default shipping address is different from the billing address, verify it correctly shows the "Lieferadresse" badge.
6. If the billing and shipping addresses are the same, verify that address shows only the "Lieferadresse" badge (not both).
