---
filename: "_ai/backlog/reports/260625_0013__IMPLEMENTATION_REPORT__preserve-billing-company-on-edit.md"
title: "Report: Preserve billing address company name on edit form save"
createdAt: 2026-06-25 00:13
updatedAt: 2026-06-25 00:13
project: "topdata-better-checkout-sw6"
status: completed
filesCreated: 0
filesModified: 2
filesDeleted: 0
tags: [bugfix, billing-address, company-field, upsert-route, decorator]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report: Preserve billing address company name on edit form save

## 1. Summary

Fixed a data-loss bug where saving the "Rechnungsadresse bearbeiten" page wiped the billing address's company name. The billing address edit form renders the company field read-only (change-request mechanism) and never submits it in the POST data, so Shopware's core `UpsertAddressRoute` overwrote the stored company with `null`. The `UpsertAddressRouteDecorator` now re-injects the persisted company value before delegating, enforcing the invariant that a billing address / business guest customer can never lose its company name through the edit form.

## 1.5 Prompt used

> on page "Rechnungsadresse bearbeiten" https://focus.docker/account/address/bd60a4ac64a64b1cbe7e472909a5ce8e (user mpaoftringen@hin.ch) .. .. when clicking "Aenderungen speichern" - it also deletes the company name from the billing address. That should NEVER happen. company namme of billing address and of customer (of type guest=0) MUST NEVER be empty! Please fix

## 2. Files Changed

### Modified

- **`src/Core/Checkout/Customer/SalesChannel/UpsertAddressRouteDecorator.php`**
  Added a `preserveBillingCompany()` private method invoked from `upsert()` before delegating to the decorated core route. It detects a billing-address edit (addressId matches the customer's `defaultBillingAddressId`), and when the submitted `company` field is empty, fetches the persisted address from `customer_address.repository` and re-injects its `company` value into the `RequestDataBag`. This prevents the core route's unconditional `'company' => $data->get('company')` mapping from nulling the column. Added new constructor dependency `EntityRepository $addressRepository` and the `CustomerAddressCollection`, `EntityRepository`, `Criteria` imports.

- **`src/Resources/config/services.xml`**
  Added a third constructor argument `customer_address.repository` to the `UpsertAddressRouteDecorator` service definition so the decorator can look up the existing address entity.

### Created / Deleted

None.

## 3. Key Changes

- Decorator now distinguishes billing vs shipping edit context by comparing `$addressId` to `$customer->getDefaultBillingAddressId()`.
- A billing edit with an empty/missing submitted `company` triggers a `customer_address.repository->search(new Criteria([$addressId]), ...)` lookup and a `$data->set('company', $existingCompany)` re-injection.
- Asymmetric behavior is intentional: shipping addresses can legitimately have an empty company (non-business recipients), so the guard only applies to the default billing address.
- Core route's own validation/constraint removal logic in `AddressValidationSubscriber::applyValidationRules()` (`type === 'billing'` branch) remains untouched — the field is still optional on validation, but the persisted value is now preserved at the data layer.

## 4. Deviations from Plan

No plan existed for this fix (bugfix request). Approach taken:
- Chose server-side preservation in the decorator over a hidden form field because:
  - The Twig template already has a read-only company display + "Request Change" flow; adding hidden inputs in multiple templates would be more brittle.
  - Server-side enforcement is the only way to also protect against direct `store-api.account.address.update` API calls that bypass the form.
- An alternative considered was overriding the core route's `addressData['company']` mapping via a second decorator on the entity write, but Shopware's `UpsertAddressRoute` builds the array inline, so injection at the `RequestDataBag` stage is the cleanest interception point.

## 5. Technical Decisions

- **Scope of the guard:** Only fires for `addressId === customer.defaultBillingAddressId`. This matches the spec's intent that billing-company is the protected field (customer with `guest=0` business account type must retain its business identity), while keeping shipping-address optional behavior unchanged.
- **Empty check is strict:** Both `null` and whitespace-only strings trigger the preservation, mirroring the `NotBlank` semantic elsewhere in the plugin's validation config.
- **Repository over SalesChannelRepository:** Used the plain `EntityRepository` because we already have the validated address id belonging to the customer (Shopware's `validateAddress()` runs first in the core route via `$this->validateAddress($addressId, $context, $customer)`, and we run before delegation so the core route still performs that ownership check afterwards). Reading does not require sales-channel scoping.
- **No new config flag:** The behavior is unconditional for the billing address, matching the user's explicit requirement ("MUST NEVER be empty"). It does not depend on `companyValidationBilling` or `registrationAccountType` settings.

## 6. Testing Notes

1. Log in as `mpaoftringen@hin.ch` (or any business customer with a non-empty billing company).
2. Open `/account/address/{defaultBillingAddressId}` (Rechnungsadresse bearbeiten).
3. Modify an unrelated field (e.g. phone, city), click "Änderungen speichern".
4. Reload the address book — billing address `company` value must be unchanged.
5. Verify a pending company-name-change request still works (separate modal flow); it must not be affected.
6. Negative test: edit a **shipping** address and clear its company field — the shipping address company should be allowed to become empty (this is current/intended behavior for non-business recipients).
7. Cache warm: `bin/console cache:clear` after deploying (services.xml wiring changed).
8. PHP syntax already verified via `php -l`; services.xml validated as well-formed XML.

Manual QA category mapping: `TEST-CHECKLIST.md` → "Rechnungsadresse bearbeiten" + "Firma / change request" sections.

## 7. Usage Examples

No new CLI commands or APIs introduced. Existing routes unaffected externally.

## 8. Documentation Updates

- No changes to `AGENTS.md` — the existing note "Billing address company field is read-only (change request mechanism) — never in form data" in `AddressValidationSubscriber` now accurately reflects runtime behavior; previously it was only half true (validation was relaxed but the persisted value was being wiped).
- `_ai/SPEC.md` was not updated; the invariant "billing address company must never be empty" is now enforced, which matches the spec's intent.

## 9. Next Steps

- Consider mirroring this protection at the customer-entity level for the `customer.company` field on the profile page (currently the profile edit uses the same read-only change-request UI; verify whether the profile save route has an analogous gap).
- Optionally add a regression test in `tests/` once a test harness is introduced (the project currently has no automated tests per `AGENTS.md`).
- Optionally refactor `AddressValidationSubscriber::applyValidationRules()` "billing" branch to also call `NotBlank` when the existing persisted value is required, so empty-client-form-submissions would be rejected at validation time instead of silently preserved — but this would change the UX contract and is left as a future decision.