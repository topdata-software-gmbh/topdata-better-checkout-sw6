---
filename: "_ai/backlog/active/260601_1430__IMPLEMENTATION_PLAN__billing-address-edit-modal.md"
title: "Billing Address Edit Modal on Confirm Page"
createdAt: 2026-06-01 14:30
updatedAt: 2026-06-01 14:30
status: draft
priority: high
tags: [checkout, modal, billing-address, storefront, twig, javascript]
project: topdata-better-checkout-sw6
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

# Billing Address Edit Modal on Confirm Page

## Problem Statement

On the checkout confirm page, clicking the "Rechnungsadresse bearbeiten" (Edit billing address) button currently navigates the user to a separate page (`frontend.account.address.edit.page`). This disrupts the checkout flow and creates friction. The user must navigate back to the confirm page after editing, potentially losing context.

**Goal:** Replace the page navigation with an inline modal that allows editing the billing address directly on the confirm page. After saving, the modal closes and the billing address display updates without a full page reload.

**Critical Constraint:** The save operation must ONLY update the existing `CustomerAddress` entity. It must NEVER change the `customer.defaultBillingAddress` field.

---

## Implementation Notes

### Project Structure

```
src/
├── Controller/
│   └── BillingAddressEditController.php          [NEW FILE]
├── Resources/
│   ├── config/
│   │   └── services.xml                          [MODIFY]
│   ├── snippet/
│   │   ├── de_DE/storefront.de-DE.json           [MODIFY]
│   │   ├── en_GB/storefront.en-GB.json           [MODIFY]
│   │   ├── fr_FR/storefront.fr-FR.json           [MODIFY]
│   │   ├── fr_CH/storefront.fr-CH.json           [MODIFY]
│   │   └── pt_PT/storefront.pt-PT.json           [MODIFY]
│   └── views/
│       └── storefront/
│           ├── component/
│           │   └── address/
│           │       └── billing-address-edit-modal.html.twig  [NEW FILE]
│           └── page/checkout/confirm/
│               └── confirm-address.html.twig     [MODIFY]
└── Resources/app/storefront/src/
    └── plugin/
        └── billing-address-modal/
            └── billing-address-modal.plugin.js   [NEW FILE]
```

### Key Architecture Decisions

1. **Shopware's AjaxModalPlugin**: Leverages the built-in `[data-ajax-modal][data-url]` pattern. No custom modal JS needed for opening the modal — Shopware's core `AjaxModalPlugin` handles it automatically.

2. **`js-pseudo-modal-template-root-element`**: The modal response template uses this CSS class to replace the entire modal content (header, body, footer), giving full control over the modal structure.

3. **`data-form-handler="true"`**: Shopware's built-in form handler plugin intercepts form submissions inside modals and submits them via AJAX.

4. **`AbstractUpsertAddressRoute`**: Uses Shopware's core address update route (same as the address manager) to save the address. This route upserts the address without changing the default billing address.

5. **Page reload after save**: After successful save, the page reloads to show the updated billing address. This is the simplest and most reliable approach — the address is saved in the database, and the page reload fetches the fresh context.

### Shopware Modal Flow (Reference)

The Shopware storefront provides a complete AJAX modal infrastructure:

1. **Trigger**: Any element with `data-ajax-modal="true"` and `data-url="/some/endpoint"`
2. **Plugin**: `AjaxModalPlugin` (registered for `[data-ajax-modal][data-url]`) intercepts the click
3. **Fetch**: GETs the URL with `X-Requested-With: XMLHttpRequest` header
4. **Render**: `PseudoModalUtil` creates a Bootstrap 5 modal from the response
5. **Re-init**: `window.PluginManager.initializePlugins()` re-binds plugins to new DOM

For form submission inside modals:
1. Form has `data-form-handler="true"` attribute
2. Shopware's `FormHandlerPlugin` intercepts the submit
3. POSTs form data via AJAX
4. On success (204 response): page reloads
5. On error (validation): replaces modal content with error response

### Backend Route Design

```
GET  /widgets/checkout/billing-address-edit/{addressId}  → Renders address form HTML
POST /widgets/checkout/billing-address-edit/{addressId}  → Saves address, returns 204 or validation errors
```

Both endpoints are AJAX-only (`XmlHttpRequest: true`) and require authentication.

---

## Phase 1: Create the BillingAddressEditController

### Objective

Create a new controller with GET and POST endpoints for the billing address edit modal.

### Tasks

1. Create `src/Controller/BillingAddressEditController.php`
2. The GET endpoint loads the address and renders the modal template
3. The POST endpoint saves the address via `AbstractUpsertAddressRoute` and returns 204 on success

### Deliverables

#### `src/Controller/BillingAddressEditController.php` [NEW FILE]

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Controller;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\Exception\AddressNotFoundException;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractListAddressRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractUpsertAddressRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Routing\RoutingException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class BillingAddressEditController extends StorefrontController
{
    public function __construct(
        private readonly AbstractListAddressRoute $listAddressRoute,
        private readonly AbstractUpsertAddressRoute $upsertAddressRoute,
    ) {
    }

    #[Route(
        path: '/widgets/checkout/billing-address-edit/{addressId}',
        name: 'frontend.checkout.billing-address.edit.get',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true, '_loginRequired' => true],
        methods: ['GET']
    )]
    public function getBillingAddressForm(
        string $addressId,
        SalesChannelContext $context,
        CustomerEntity $customer,
    ): Response {
        $address = $this->getCustomerAddress($addressId, $context, $customer);

        $response = $this->renderStorefront(
            '@TopdataBetterCheckoutSW6/storefront/component/address/billing-address-edit-modal.html.twig',
            [
                'address' => $address,
            ],
        );

        $response->headers->set('x-robots-tag', 'noindex');

        return $response;
    }

    #[Route(
        path: '/widgets/checkout/billing-address-edit/{addressId}',
        name: 'frontend.checkout.billing-address.edit.save',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true, '_loginRequired' => true],
        methods: ['POST']
    )]
    public function saveBillingAddress(
        string $addressId,
        RequestDataBag $data,
        SalesChannelContext $context,
        CustomerEntity $customer,
    ): Response {
        $this->getCustomerAddress($addressId, $context, $customer);

        /** @var RequestDataBag $addressData */
        $addressData = $data->get('address');
        $addressData->set('id', $addressId);

        try {
            $this->upsertAddressRoute->upsert(
                $addressId,
                $addressData->toRequestDataBag(),
                $context,
                $customer,
            );

            return new Response('', 204);
        } catch (ConstraintViolationException $formViolations) {
            $address = $this->getCustomerAddress($addressId, $context, $customer);

            $response = $this->renderStorefront(
                '@TopdataBetterCheckoutSW6/storefront/component/address/billing-address-edit-modal.html.twig',
                [
                    'address' => $address,
                    'formViolations' => $formViolations,
                    'postedData' => $addressData,
                ],
            );

            $response->setStatusCode(422);
            $response->headers->set('x-robots-tag', 'noindex');

            return $response;
        }
    }

    private function getCustomerAddress(
        string $addressId,
        SalesChannelContext $context,
        CustomerEntity $customer,
    ): CustomerAddressEntity {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $addressId));
        $criteria->addFilter(new EqualsFilter('customerId', $customer->getId()));

        $address = $this->listAddressRoute
            ->load($criteria, $context, $customer)
            ->getAddressCollection()
            ->get($addressId);

        if (!$address) {
            throw AddressNotFoundException::byId($addressId);
        }

        return $address;
    }
}
```

---

## Phase 2: Create the Modal Twig Template

### Objective

Create a Twig template that renders the address edit form inside a modal structure compatible with Shopware's `PseudoModalUtil`.

### Tasks

1. Create the modal template at `src/Resources/views/storefront/component/address/billing-address-edit-modal.html.twig`
2. Use `js-pseudo-modal-template-root-element` class for full modal replacement
3. Include address-personal and address-form components with proper prefixes
4. Use `data-form-handler="true"` for AJAX form submission

### Deliverables

#### `src/Resources/views/storefront/component/address/billing-address-edit-modal.html.twig` [NEW FILE]

```twig
<div class="js-pseudo-modal-template-root-element billing-address-edit-modal">
    <div class="modal-header pb-0 align-items-start">
        {% block billing_address_edit_modal_title %}
            <h1 class="fs-2">
                {{ 'better-checkout.billingAddressEdit.title'|trans|sw_sanitize }}
            </h1>
        {% endblock %}

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="modal"
            aria-label="{{ 'global.default.close'|trans|striptags }}"
        ></button>
    </div>

    <div class="modal-body">
        {% block billing_address_edit_modal_form %}
            <form
                method="post"
                action="{{ path('frontend.checkout.billing-address.edit.save', { addressId: address.id }) }}"
                id="billing-address-edit-form"
                data-form-handler="true"
            >
                {% block billing_address_edit_modal_personal %}
                    {% sw_include '@Storefront/storefront/component/address/address-personal.html.twig' with {
                        data: postedData ?? address,
                        prefix: 'address',
                        showFormCompany: true,
                    } %}
                {% endblock %}

                {% block billing_address_edit_modal_address %}
                    {% sw_include '@Storefront/storefront/component/address/address-form.html.twig' with {
                        data: postedData ?? address,
                        prefix: 'address',
                        showFormCompany: true,
                    } %}
                {% endblock %}

                {% block billing_address_edit_modal_required %}
                    <p class="address-required-info required-fields">
                        {{ 'general.requiredFields'|trans|sw_sanitize }}
                    </p>
                {% endblock %}
            </form>
        {% endblock %}
    </div>

    <div class="modal-footer justify-content-end">
        {% block billing_address_edit_modal_actions %}
            <button
                type="button"
                class="btn btn-outline-dark"
                data-bs-dismiss="modal"
            >
                {{ 'better-checkout.billingAddressEdit.cancel'|trans|sw_sanitize }}
            </button>

            <button
                type="submit"
                form="billing-address-edit-form"
                class="btn btn-primary"
                title="{{ 'better-checkout.billingAddressEdit.save'|trans|striptags }}"
            >
                {{ 'better-checkout.billingAddressEdit.save'|trans|sw_sanitize }}
            </button>
        {% endblock %}
    </div>
</div>
```

---

## Phase 3: Update the Confirm Address Template

### Objective

Modify the existing confirm-address template to use the AJAX modal trigger instead of a direct page link.

### Tasks

1. Update `src/Resources/views/storefront/page/checkout/confirm/confirm-address.html.twig`
2. Replace the `<a>` tag with a `<button>` that has `data-ajax-modal="true"` and `data-url` attributes
3. The `data-url` points to the new `frontend.checkout.billing-address.edit.get` route

### Deliverables

#### `src/Resources/views/storefront/page/checkout/confirm/confirm-address.html.twig` [MODIFY]

```twig
{% sw_extends '@Storefront/storefront/page/checkout/confirm/confirm-address.html.twig' %}

{% block page_checkout_confirm_address_billing_actions %}
    <div class="card-actions">
        {% set billingAddress = context.customer.activeBillingAddress %}

        <button
            type="button"
            class="btn btn-light btn-sm"
            data-ajax-modal="true"
            data-url="{{ path('frontend.checkout.billing-address.edit.get', { addressId: billingAddress.id }) }}"
            title="{{ 'checkout.confirmChangeBillingAddress'|trans|striptags }}"
        >
            {{ "checkout.confirmChangeBillingAddress"|trans|sw_sanitize }}
        </button>
    </div>
{% endblock %}
```

---

## Phase 4: Add Snippet Translations

### Objective

Add translation snippets for the modal title, cancel button, and save button in all 5 supported languages.

### Tasks

1. Update `storefront.de-DE.json` — add `better-checkout.billingAddressEdit.*` keys
2. Update `storefront.en-GB.json` — add English translations
3. Update `storefront.fr-FR.json` — add French translations
4. Update `storefront.fr-CH.json` — add Swiss French translations
5. Update `storefront.pt-PT.json` — add Portuguese translations

### Deliverables

#### `src/Resources/snippet/de_DE/storefront.de-DE.json` [MODIFY]

Add the following keys under `better-checkout`:

```json
{
    "better-checkout": {
        "billingAddressEdit": {
            "title": "Rechnungsadresse bearbeiten",
            "cancel": "Abbrechen",
            "save": "Speichern"
        }
    }
}
```

#### `src/Resources/snippet/en_GB/storefront.en-GB.json` [MODIFY]

```json
{
    "better-checkout": {
        "billingAddressEdit": {
            "title": "Edit billing address",
            "cancel": "Cancel",
            "save": "Save"
        }
    }
}
```

#### `src/Resources/snippet/fr_FR/storefront.fr-FR.json` [MODIFY]

```json
{
    "better-checkout": {
        "billingAddressEdit": {
            "title": "Modifier l'adresse de facturation",
            "cancel": "Annuler",
            "save": "Enregistrer"
        }
    }
}
```

#### `src/Resources/snippet/fr_CH/storefront.fr-CH.json` [MODIFY]

```json
{
    "better-checkout": {
        "billingAddressEdit": {
            "title": "Modifier l'adresse de facturation",
            "cancel": "Annuler",
            "save": "Enregistrer"
        }
    }
}
```

#### `src/Resources/snippet/pt_PT/storefront.pt-PT.json` [MODIFY]

```json
{
    "better-checkout": {
        "billingAddressEdit": {
            "title": "Editar endereço de faturação",
            "cancel": "Cancelar",
            "save": "Guardar"
        }
    }
}
```

---

## Phase 5: Update SPEC.md Documentation

### Objective

Update the project specification to document the new modal behavior.

### Tasks

1. Update `_ai/SPEC.md` section 2.7 to reflect the modal behavior

### Deliverables

#### `_ai/SPEC.md` [MODIFY]

Update section 2.7:

```markdown
### 2.7 "Edit Billing Address" on Confirm Page
- Replaces "Change billing address" (modal selection) with a direct-edit modal
- Clicking "Rechnungsadresse bearbeiten" opens an AJAX modal with the address form
- Saving the form updates the existing `CustomerAddress` entity via `AbstractUpsertAddressRoute`
- The `defaultBillingAddress` is NEVER changed by this operation
- After save, the modal closes and the page reloads to show the updated address
```

---

## Phase 6: Write Implementation Report

### Objective

Document the implementation results.

### Tasks

1. Create the implementation report at `_ai/backlog/reports/260601_1430__IMPLEMENTATION_REPORT__billing-address-edit-modal.md`

### Deliverables

#### `_ai/backlog/reports/260601_1430__IMPLEMENTATION_REPORT__billing-address-edit-modal.md` [NEW FILE]

```yaml
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
filesCreated: 3
filesModified: 7
filesDeleted: 0
tags: [checkout, modal, billing-address, storefront]
documentType: IMPLEMENTATION_REPORT
---
```

**Summary:**
Implemented an AJAX modal for editing the billing address on the checkout confirm page. The modal replaces the previous page-navigation behavior, allowing users to edit their billing address inline. The save operation updates only the `CustomerAddress` entity without modifying the `defaultBillingAddress`.

**Files Changed:**

- **New files:**
  - `src/Controller/BillingAddressEditController.php` — GET/POST endpoints for the modal form
  - `src/Resources/views/storefront/component/address/billing-address-edit-modal.html.twig` — Modal template with address form
  - `src/Resources/app/storefront/src/plugin/billing-address-modal/billing-address-modal.plugin.js` — (Optional) JS plugin if custom behavior is needed beyond Shopware's AjaxModalPlugin

- **Modified files:**
  - `src/Resources/views/storefront/page/checkout/confirm/confirm-address.html.twig` — Changed `<a>` to `<button>` with `data-ajax-modal` attributes
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
