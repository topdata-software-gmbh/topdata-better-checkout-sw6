---
filename: "_ai/backlog/reports/260621_1833__ANALYSIS_REPORT__company-name-change-approval-status.md"
title: "Analysis: Company Name Change Approval Feature — Implementation Status"
createdAt: 2026-06-21 18:33
updatedAt: 2026-06-21 18:33
project: "topdata-better-checkout-sw6"
status: completed
tags: [analysis, company-name-change, feature-review]
documentType: ANALYSIS_REPORT
---

# Analysis: Company Name Change Approval Feature — Implementation Status

## Executive Summary

The "company name change request" feature is **fully implemented** — not incomplete as assumed. It consists of 35 files spanning database migration, DAL entities, a core service with email notifications, storefront interceptors (controller + subscribers), Twig overrides, an Admin Vue.js module with approve/reject UI, and storefront snippets in 5 languages. The implementation plan from `_ai/backlog/archive/260603_1951__IMPLEMENTATION_PLAN__company-name-change-approval.md` was marked `completed`, and an implementation report exists at `_ai/backlog/reports/260603_2020__IMPLEMENTATION_REPORT__company-name-change-approval.md`. However, the main `_ai/SPEC.md` has not been updated to document this feature.

## Scope

Investigated all code related to company name change request functionality across the entire plugin codebase, including PHP backend, Twig templates, Admin Vue.js module, configuration, snippets, and planning documents.

## Methodology

Manual code review and file inventory of all 35+ files identified as part of the feature, cross-referenced against the archived implementation plan.

## Findings

### Current State — Fully Implemented

| Layer | Files | Status |
|---|---|---|
| **Database Migration** | `src/Migration/Migration1748979000CreateCompanyNameChangeRequestTable.php` | ✅ Creates `tdbc_company_name_change_request` table with FK to `customer` and `customer_address` |
| **DAL (Entity/Definition/Collection)** | 3 files in `src/Core/Content/CompanyNameChangeRequest/` | ✅ Entity with `#[Entity]` attribute, Definition with full FieldCollection, Collection class |
| **Struct Extension** | `CompanyNameChangePendingExtension.php` | ✅ Passes pending request data to Twig pages |
| **Core Service** | `CompanyNameChangeRequestService.php` | ✅ `createChangeRequest()`, `approveChangeRequest()`, `rejectChangeRequest()`, `hasPendingChangeRequest()`, auto-cancellation logic |
| **Email Service** | `CompanyNameChangeRequestEmailService.php` | ✅ Admin notification + customer approval/rejection emails via Symfony Mailer |
| **Storefront Controller** | `BillingAddressEditController.php` | ✅ Intercepts company field changes, creates change request, strips company from upsert data |
| **Admin API Controller** | `CompanyNameChangeRequestController.php` | ✅ Approve/reject endpoints |
| **Event Subscribers** | `CheckoutConfirmBlockSubscriber.php`, `AccountAddressPageSubscriber.php` | ✅ Block checkout if pending, show warnings on address pages |
| **Twig Templates (Storefront)** | 4 templates | ✅ Blocking notice on checkout confirm, warning on billing edit modal, address book alert |
| **Email Templates (Twig)** | 3 templates | ✅ Admin notification, customer-approved, customer-rejected |
| **Admin Vue.js Module** | 10 files | ✅ Module registration, list page, detail page with approve/reject actions, search config, snippets (DE/EN) |
| **Storefront Snippets** | 5 language files | ✅ `companyChange.*` keys in all 5 supported languages |
| **Service Registration** | `src/Resources/config/services.xml` | ✅ All 7 services registered with proper DI |
| **Implementation Plan** | `_ai/backlog/archive/260603_1951__IMPLEMENTATION_PLAN__.md` | ✅ Marked completed |
| **Implementation Report** | `_ai/backlog/reports/260603_2020__IMPLEMENTATION_REPORT__.md` | ✅ Written, includes QA checklist |

### Identified Gaps

1. **`_ai/SPEC.md` not updated** — The main specification document does not mention the company name change approval feature. This is a documentation gap.
2. **No config toggle** — The approval workflow is always active. If a merchant wants to disable it, there's no configuration option.
3. **Shipping address not covered** — Only billing address company changes are intercepted (via `BillingAddressEditController`). Shipping address company changes bypass the approval workflow.
4. **Direct Symfony Mailer** — Emails use `Symfony\Component\Mailer\MailerInterface` directly rather than Shopware's mail template system, meaning templates are not editable via Admin.
5. **No automated tests** — Consistent with the plugin's policy (`tests/` is empty), but this feature has complex business logic (auto-cancellation, edge cases) that would benefit from testing.

### Code References

Key files:
- `src/Migration/Migration1748979000CreateCompanyNameChangeRequestTable.php:1` — DB schema
- `src/Core/Content/CompanyNameChangeRequest/CompanyNameChangeRequestService.php:1` — Core business logic
- `src/Controller/BillingAddressEditController.php:55` — Company field interception + stripping
- `src/Core/Checkout/Customer/Subscriber/CheckoutConfirmBlockSubscriber.php:1` — Checkout blocking
- `src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/page/topdata-company-name-change-detail/index.js:1` — Admin approve/reject UI
- `src/Resources/config/services.xml:28` — Service wiring

## Options & Alternatives

### Option A: Update SPEC.md and close (Recommended)
Describe the feature in `_ai/SPEC.md` and mark it as complete.
- **Pros**: Low effort, closes the gap, accurate documentation
- **Cons**: Doesn't address config toggle or shipping address gap

### Option B: Add config toggle
Add a `config.xml` entry and service condition to enable/disable the approval workflow.
- **Pros**: Merchants can opt out
- **Cons**: Medium effort, additional code complexity

### Option C: Extend to shipping addresses
Apply the same interception logic to shipping address edits.
- **Pros**: Consistent behavior across address types
- **Cons**: Requires new controller/subscriber for shipping edit route

## Recommendation

**Option A** — Update `_ai/SPEC.md` to document the feature. The implementation is complete and functional. The other gaps (config toggle, shipping coverage) are scope enhancements, not missing implementation. The feature works as designed per the archived implementation plan.

**Estimated complexity**: Low (15 minutes of documentation work)

## Risks & Trade-offs

- No risks in accepting the current implementation as complete.
- If the plugin is distributed without SPEC.md being updated, future maintainers may also assume the feature is incomplete.

## Next Steps

1. ✓ This report documents the current state.
2. Update `_ai/SPEC.md` with the company name change approval feature documentation.
3. Optionally create backlog items for:
   - Config toggle to enable/disable the approval workflow
   - Extending approval workflow to shipping addresses
   - Migrating emails to Shopware's mail template system
