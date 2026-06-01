---
filename: "_ai/backlog/reports/260601_1430__IMPLEMENTATION_REPORT__billing-address-edit-modal.md"
title: "Report: Billing Address Edit Modal on Confirm Page"
createdAt: 2026-06-01 14:30
createdBy: AI [opencode]
updatedAt: 2026-06-01 14:30
updatedBy: AI [opencode]
planFile: "_ai/backlog/active/260601_1430__IMPLEMENTATION_PLAN__billing-address-edit-modal.md"
project: "topdata-better-checkout-sw6"
status: completed
filesCreated: 2
filesModified: 8
filesDeleted: 0
tags: [checkout, modal, billing-address, storefront]
documentType: IMPLEMENTATION_REPORT
---

**Summary:**
Implemented an AJAX modal for editing the billing address on the checkout confirm page. The modal replaces the previous page-navigation behavior, allowing users to edit their billing address inline. The save operation updates only the `CustomerAddress` entity without modifying the `defaultBillingAddress`.

**Files Changed:**

- **New files:**
  - `src/Controller/BillingAddressEditController.php` — GET/POST endpoints for the modal form
  - `src/Resources/views/storefront/component/address/billing-address-edit-modal.html.twig` — Modal template with address form

- **Modified files:**
  - `src/Resources/config/services.xml` — Registered new controller with `AbstractListAddressRoute` and `AbstractUpsertAddressRoute` arguments
  - `src/Resources/views/storefront/page/checkout/confirm/confirm-address.html.twig` — Changed `<a>` to `<button>` with `data-ajax-modal` and `data-url` attributes
  - `src/Resources/snippet/de_DE/storefront.de-DE.json` — Added `billingAddressEdit` snippets
  - `src/Resources/snippet/en_GB/storefront.en-GB.json` — Added `billingAddressEdit` snippets
  - `src/Resources/snippet/fr_FR/storefront.fr-FR.json` — Added `billingAddressEdit` snippets
  - `src/Resources/snippet/fr_CH/storefront.fr-CH.json` — Added `billingAddressEdit` snippets
  - `src/Resources/snippet/pt_PT/storefront.pt-PT.json` — Added `billingAddressEdit` snippets
  - `_ai/SPEC.md` — Updated section 2.7

**Key Changes:**
- New `BillingAddressEditController` with GET (render form) and POST (save address) endpoints
- Modal template uses `js-pseudo-modal-template-root-element` for full modal content replacement
- Form uses `data-form-handler="true"` for Shopware's built-in AJAX form submission
- POST returns HTTP 204 on success (triggers page reload) or 422 with validation errors
- Address update uses `AbstractUpsertAddressRoute` which never touches `defaultBillingAddress`

**Technical Decisions:**
- Used Shopware's built-in `AjaxModalPlugin` instead of writing custom JS — the `[data-ajax-modal][data-url]` pattern automatically handles modal opening, AJAX loading, and plugin re-initialization
- Used `js-pseudo-modal-template-root-element` pattern (same as Shopware's address manager) for full control over modal header/body/footer
- Chose page reload after save (via 204 response) instead of partial DOM update — simpler, more reliable, and consistent with Shopware's address manager behavior
- Did NOT create a custom JS plugin — Shopware's `FormHandlerPlugin` handles AJAX form submission automatically when `data-form-handler="true"` is set

**Testing Notes:**
1. Navigate to checkout confirm page as a logged-in customer
2. Click "Rechnungsadresse bearbeiten" button
3. Verify modal opens with the current billing address pre-filled
4. Modify address fields and click "Speichern"
5. Verify modal closes and the billing address display updates
6. Verify the `defaultBillingAddress` has NOT changed (check in admin or address book)
7. Test validation errors by clearing required fields
8. Test with different account types (private/business) and company validation settings

**Documentation Updates:**
- Updated `_ai/SPEC.md` section 2.7 to reflect modal behavior instead of page navigation

**Next Steps:**
- Consider adding a loading spinner during form submission for better UX
- Consider partial page update instead of full reload (would require custom JS plugin)
- Add TEST-CHECKLIST.md entries for the new modal behavior
