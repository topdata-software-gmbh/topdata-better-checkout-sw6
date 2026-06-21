---
title: "Implementation Report — Company Name Change Request UX Redesign"
createdAt: 2026-06-21 19:17
planFile: "_ai/backlog/active/260621_1908__IMPLEMENTATION_PLAN__company-name-change-request-ux-redesign.md"
status: completed
---

## Summary

Replaced the fragile route-decorator-based company change interception with a robust UX redesign:
company name fields are now **read-only on edit pages** with a dedicated "Request Change" modal flow.

## Files Changed / Created

| Action | File | Status |
|---|---|---|
| MODIFY | `src/Controller/BillingAddressEditController.php` | ✅ Removed company interception from `saveBillingAddress()`, added GET/POST routes for the change request modal, passes pending request entity instead of bool |
| MODIFY | `src/Resources/config/services.xml` | ✅ Added `AccountProfilePageSubscriber` service registration |
| MODIFY | `src/Resources/snippet/en_GB/storefront.en-GB.json` | ✅ Added 14 new translation keys under `better-checkout.companyChange` |
| MODIFY | `src/Resources/snippet/de_DE/storefront.de-DE.json` | ✅ Added German translations |
| MODIFY | `src/Resources/snippet/fr_FR/storefront.fr-FR.json` | ✅ Added French translations |
| MODIFY | `src/Resources/snippet/fr_CH/storefront.fr-CH.json` | ✅ Added Swiss French translations |
| MODIFY | `src/Resources/snippet/pt_PT/storefront.pt-PT.json` | ✅ Added Portuguese translations |
| MODIFY | `src/Resources/views/storefront/component/address/address-personal-company.html.twig` | ✅ Conditionally shows read-only company widget on edit pages, normal input on create/shipping |
| MODIFY | `src/Resources/views/storefront/component/address/billing-address-edit-modal.html.twig` | ✅ Removed standalone pending warning (now integrated into company field) |
| NEW | `src/Resources/views/storefront/component/address/company-name-change-request-modal.html.twig` | ✅ Modal for requesting a company name change |
| NEW | `src/Resources/views/storefront/component/address/company-field-readonly.html.twig` | ✅ Read-only company display with "Request Change" button |
| NEW | `src/Core/Checkout/Customer/Subscriber/AccountProfilePageSubscriber.php` | ✅ Adds pending change extension to profile page |
| NEW | `src/Resources/views/storefront/page/account/profile/index.html.twig` | ✅ Replaces company input with read-only display on profile page |
| UNCHANGED | `src/Core/Checkout/Customer/SalesChannel/UpsertAddressRouteDecorator.php` | ✅ Already clean (no company interception) |
| UNCHANGED | `src/Core/Checkout/Customer/SalesChannel/ChangeCustomerProfileRouteDecorator.php` | ✅ Already deleted (never existed in repo) |
| UNCHANGED | `src/Core/Checkout/Customer/Subscriber/AccountAddressPageSubscriber.php` | ✅ No changes needed |
| UNCHANGED | `src/Resources/views/storefront/page/checkout/confirm/index.html.twig` | ✅ Checkout blocking logic intact |

## Key Design Decisions

1. **No route decorators needed** — company field is physically prevented from being edited by replacing the `<input>` with read-only text + hidden field on edit pages
2. **EDIT vs CREATE detection** — uses `address.get('id')` — if the address has an ID, it's an edit context
3. **Shipping addresses excluded** — only billing addresses show the read-only widget; shipping addresses keep the normal input
4. **Profile page handled separately** — uses `showCompanyFields: false` in the included template + manual read-only company field
5. **Modal loads pending request dynamically** — the `data-ajax-modal` GET endpoint queries by address ID, no need for template-level pending check
6. **All 5 languages** — snippets added for en-GB, de-DE, fr-FR, fr-CH, pt-PT
7. **No automated tests** — existing situation; manual QA per TEST-CHECKLIST.md

## Verification

- PHP syntax check: ✅ All modified/new files pass
- JSON validation: ✅ All 5 snippet files are valid
- XML validation: ✅ services.xml is valid
- Clear cache: `bin/console cache:clear` required before testing
