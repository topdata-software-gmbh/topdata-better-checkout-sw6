# Edit Billing Address on Confirm Page

## Overview

Replaces Shopware's "Change billing address" (modal selection from existing addresses) with a direct-edit modal that updates the existing billing address in-place.

## Controller: BillingAddressEditController

**File:** `src/Controller/BillingAddressEditController.php`

### Routes

| Method | Path | Name | Purpose |
|--------|------|------|---------|
| GET | `/widgets/checkout/billing-address-edit/{addressId}` | `frontend.checkout.billing-address.edit.get` | Show edit modal |
| POST | `/widgets/checkout/billing-address-edit/{addressId}` | `frontend.checkout.billing-address.edit.save` | Save edited address |

### How it Works

1. Customer clicks "Edit billing address" on the confirm page
2. AJAX request loads the edit modal with the current address, countries, salutations
3. Customer edits and submits the form
4. `AbstractUpsertAddressRoute::upsert()` updates the **existing** `CustomerAddress` entity (using `addressId`)
5. The `defaultBillingAddress` is NEVER changed — only the address content is modified
6. On success: redirect to confirm page (address changes visible on page reload)
7. On validation error: re-render modal with violations (HTTP 422)

### Integration

- **Template:** `src/Resources/views/storefront/page/checkout/confirm/confirm-address.html.twig`
  - Overrides `page_checkout_confirm_address_billing_actions` block
  - Replaces "Change" (address selection) with "Edit" (direct edit modal) link
- **Template:** `src/Resources/views/storefront/component/address/billing-address-edit-modal.html.twig` — the modal content
