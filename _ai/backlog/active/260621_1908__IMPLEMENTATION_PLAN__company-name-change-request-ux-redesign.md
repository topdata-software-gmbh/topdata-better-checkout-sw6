---
filename: "_ai/backlog/active/260621_1908__IMPLEMENTATION_PLAN__company-name-change-request-ux-redesign.md"
title: "Company Name Change Request — UX Redesign"
createdAt: 2026-06-21 19:08
updatedAt: 2026-06-21 19:08
status: draft
priority: high
tags: [company-name-change, ux-redesign, decorator-removal]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

# Company Name Change Request — UX Redesign

## Problem

The previous implementation tried to **intercept** company field changes after form submission by decorating `UpsertAddressRoute` and `ChangeCustomerProfileRoute`. This approach is fragile because:

1. The address data format passed to `UpsertAddressRoute::upsert()` varies by caller — some wrap it in an `address` sub-bag, some pass it directly. The decorator checked `$data->get('address')` which returned `null` for the core `AddressController` flow, completely bypassing interception.
2. Decorating `ChangeCustomerProfileRoute` caused a service container error (the decorator broke the DI chain).
3. There are multiple entry points for editing company names (profile page, address book, checkout modal), and intercepting all of them is error-prone and maintenance-heavy.
4. The `confirm-company-name-change-pending.html.twig` template was dead code — it never rendered because the filename didn't match Shopware's template override convention.

The screenshots confirmed that changing the company name worked without any approval being required, rendering the feature non-functional.

## Solution — UX Redesign

Instead of intercepting form submissions after the fact, we **prevent editing** the company name on edit pages entirely and provide a dedicated "Request Company Name Change" flow:

1. **On EDIT pages** (address edit, profile edit, checkout billing edit modal): Hide the company `<input>` field and replace it with:
   - Plain text showing the current company name
   - A "Request Change" button that opens a dedicated modal
   - If a pending change request already exists, show status text below (e.g., _"Change to 'New Company Name' requested on 21.06.2026"_)
2. **On CREATE pages** (new address, registration): Keep the normal company input — this is the first time the address is created, so no approval is needed.
3. **The "Request Change" modal** contains:
   - The current company name (read-only)
   - A single input field for the desired new company name
   - A submit button
4. **Submitting the modal** creates a `CompanyNameChangeRequest` (using the existing service), shows a success message, and reloads the page.
5. **If a pending request exists**: The button changes to "View Change Request" and shows the pending change info below the company name text.
6. **Checkout blocking** stays as-is: if a pending request exists, the checkout confirm page shows a blocking alert and prevents order placement.

This approach is far more robust because:
- No route decorators needed for company interception
- The company field is never editable on edit pages, so no data can slip through
- The change request is created through a dedicated, controlled endpoint
- The UX is clear and intentional

## Project Environment

- Project Name: topdata-better-checkout-sw6
- Backend root: src
- PHP Version: 8.2+
- Framework: Shopware 6.7, Symfony 7.4, Twig
- No JS/CSS build step (pure PHP + Twig)

---

## Phase 1: Revert Broken Decorator Changes

Remove the broken company change interception from route decorators. These decorators should only do their original jobs (account type enforcement, house number concatenation). The `ChangeCustomerProfileRouteDecorator` is removed entirely.

### [MODIFY] `src/Core/Checkout/Customer/SalesChannel/UpsertAddressRouteDecorator.php`

Remove `CompanyNameChangeRequestService` and `EntityRepository` dependencies. Remove `interceptCompanyChange()` and `loadAddress()` methods. Remove the call in `upsert()`. Revert to the original three-method class (enforceAccountType, concatenateHouseNumber, upsert).

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractUpsertAddressRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\UpsertAddressRouteResponse;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class UpsertAddressRouteDecorator extends AbstractUpsertAddressRoute
{
    private const CONFIG_PREFIX = 'TopdataBetterCheckoutSW6.config.';

    public function __construct(
        private readonly AbstractUpsertAddressRoute $decorated,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function getDecorated(): AbstractUpsertAddressRoute
    {
        return $this->decorated;
    }

    public function upsert(?string $addressId, RequestDataBag $data, SalesChannelContext $context, CustomerEntity $customer): UpsertAddressRouteResponse
    {
        $this->enforceAccountType($data, $context);
        $this->concatenateHouseNumber($data);

        return $this->decorated->upsert($addressId, $data, $context, $customer);
    }

    private function enforceAccountType(RequestDataBag $data, SalesChannelContext $context): void
    {
        $setting = $this->systemConfigService->getString(
            self::CONFIG_PREFIX . 'registrationAccountType',
            $context->getSalesChannelId(),
        );

        if ($setting === '') {
            $setting = 'always_business';
        }

        if ($setting === 'always_private') {
            $data->set('accountType', CustomerEntity::ACCOUNT_TYPE_PRIVATE);
        } elseif ($setting === 'always_business') {
            $data->set('accountType', CustomerEntity::ACCOUNT_TYPE_BUSINESS);
        }

        if ($setting === 'always_private') {
            $data->remove('company');
            $data->remove('vatId');
        }
    }

    private function concatenateHouseNumber(RequestDataBag $data): void
    {
        $houseNumber = $data->getString('topdataHouseNumber');
        if ($houseNumber === '') {
            return;
        }

        $street = $data->getString('street');
        if ($street !== '' && !str_ends_with($street, $houseNumber)) {
            $data->set('street', $street . ' ' . $houseNumber);
        } elseif ($street === '') {
            $data->set('street', $houseNumber);
        }

        $data->remove('topdataHouseNumber');
    }
}
```

### [DELETE] `src/Core/Checkout/Customer/SalesChannel/ChangeCustomerProfileRouteDecorator.php`

This file was created in the previous broken fix attempt and must be removed entirely.

### [MODIFY] `src/Resources/config/services.xml`

Remove the `ChangeCustomerProfileRouteDecorator` service and revert `UpsertAddressRouteDecorator` to its original 2-argument definition:

```xml
<service id="Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel\UpsertAddressRouteDecorator"
         decorates="Shopware\Core\Checkout\Customer\SalesChannel\UpsertAddressRoute"
         public="true">
    <argument type="service" id=".inner"/>
    <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService"/>
</service>
```

(Remove the `CompanyNameChangeRequestService` and `customer_address.repository` arguments. Remove the entire `ChangeCustomerProfileRouteDecorator` service block.)

---

## Phase 2: Simplify BillingAddressEditController

Strip the company change interception logic from `BillingAddressEditController`. The controller should only handle the modal for editing billing address fields (minus the company field). The `saveBillingAddress` method will no longer intercept company changes.

### [MODIFY] `src/Controller/BillingAddressEditController.php`

Remove company change interception from `saveBillingAddress()`. Remove the `CompanyNameChangeRequestService` dependency. The modal will still load, but the company field will be shown as read-only text + "Request Change" button (handled in Phase 4).

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
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\Country\SalesChannel\AbstractCountryRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Salutation\SalesChannel\AbstractSalutationRoute;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestService;
use Shopware\Core\Framework\Routing\RoutingException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class BillingAddressEditController extends StorefrontController
{
    public function __construct(
        private readonly AbstractListAddressRoute $listAddressRoute,
        private readonly AbstractUpsertAddressRoute $upsertAddressRoute,
        private readonly AbstractCountryRoute $countryRoute,
        private readonly AbstractSalutationRoute $salutationRoute,
        private readonly CompanyNameChangeRequestService $companyNameChangeRequestService,
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
        $page = $this->getPageWithCountries($context);

        $pendingRequest = $this->companyNameChangeRequestService->findPendingChangeRequest(
            $customer->getId(),
            $addressId,
            $context->getContext()
        );

        $response = $this->renderStorefront(
            '@TopdataBetterCheckoutSW6/storefront/component/address/billing-address-edit-modal.html.twig',
            [
                'address' => $address,
                'page' => $page,
                'pendingCompanyNameChangeRequest' => $pendingRequest,
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
        $address = $this->getCustomerAddress($addressId, $context, $customer);

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

            return $this->redirectToRoute('frontend.checkout.confirm.page');
        } catch (ConstraintViolationException $formViolations) {
            $address = $this->getCustomerAddress($addressId, $context, $customer);
            $page = $this->getPageWithCountries($context);
            $response = $this->renderStorefront(
                '@TopdataBetterCheckoutSW6/storefront/component/address/billing-address-edit-modal.html.twig',
                [
                    'address' => $address,
                    'page' => $page,
                    'formViolations' => $formViolations,
                    'postedData' => $addressData,
                ],
            );
            $response->setStatusCode(422);
            $response->headers->set('x-robots-tag', 'noindex');
            return $response;
        }
    }

    #[Route(
        path: '/widgets/checkout/company-name-change-request/{addressId}',
        name: 'frontend.checkout.company-name-change-request.save',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true, '_loginRequired' => true],
        methods: ['POST']
    )]
    public function submitCompanyNameChangeRequest(
        string $addressId,
        Request $request,
        SalesChannelContext $context,
        CustomerEntity $customer,
    ): Response {
        $newCompanyName = trim($request->request->get('newCompanyName', ''));

        if ($newCompanyName === '') {
            $this->addFlash(self::DANGER, $this->trans('better-checkout.companyChange.changeRequestEmpty'));
            return $this->redirectToRoute('frontend.checkout.confirm.page');
        }

        $address = $this->getCustomerAddress($addressId, $context, $customer);
        $oldCompanyName = $address->getCompany() ?? '';

        if ($newCompanyName === $oldCompanyName) {
            $this->addFlash(self::INFO, $this->trans('better-checkout.companyChange.changeRequestSameName'));
            return $this->redirectToRoute('frontend.checkout.confirm.page');
        }

        $this->companyNameChangeRequestService->createChangeRequest(
            $customer->getId(),
            $addressId,
            $oldCompanyName,
            $newCompanyName,
            $context->getContext()
        );

        $this->addFlash(self::SUCCESS, $this->trans('better-checkout.companyChange.changeRequestSubmitted'));

        return $this->redirectToRoute('frontend.checkout.confirm.page');
    }

    private function getPageWithCountries(SalesChannelContext $context): array
    {
        $criteria = (new Criteria())
            ->addSorting(new FieldSorting('position', FieldSorting::ASCENDING))
            ->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));

        $countries = $this->countryRoute->load(new Request(), $criteria, $context)->getCountries();
        $salutations = $this->salutationRoute->load(new Request(), $context, new Criteria())->getSalutations();
        $salutations->sort(fn ($a, $b) => $b->getSalutationKey() <=> $a->getSalutationKey());

        return [
            'countries' => $countries,
            'salutations' => $salutations,
        ];
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

Key changes:
- Removed company interception from `saveBillingAddress()`
- Changed `getBillingAddressForm()` to pass full `CompanyNameChangeRequestEntity|null` instead of just `bool hasPendingCompanyNameChange`
- Added new route `submitCompanyNameChangeRequest()` for the dedicated modal form submission
- Kept `CompanyNameChangeRequestService` dependency (still needed for the modal)

### [MODIFY] `src/Resources/config/services.xml`

Update `BillingAddressEditController` — keep the same arguments (no removal needed since we still need `CompanyNameChangeRequestService`).

---

## Phase 3: Create the Company Name Change Request Modal

Create a new Twig template for the modal that appears when the user clicks "Request Company Name Change". The modal uses Shopware's built-in `data-ajax-modal` pattern (no custom JS needed).

### [NEW FILE] `src/Resources/views/storefront/component/address/company-name-change-request-modal.html.twig`

```twig
<div class="js-pseudo-modal-template-root-element company-name-change-request-modal">
    <div class="modal-header pb-0 align-items-start">
        <h1 class="fs-2">
            {{ 'better-checkout.companyChange.changeRequestTitle'|trans|sw_sanitize }}
        </h1>
        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="modal"
            aria-label="{{ 'global.default.close'|trans|striptags }}"
        ></button>
    </div>

    <div class="modal-body">
        {# Show pending request info if one already exists #}
        {% if pendingRequest is defined and pendingRequest is not null %}
            <div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
                <div class="me-3">
                    {% sw_icon 'warning' style { size: 'md' } %}
                </div>
                <div>
                    <strong>{{ 'better-checkout.companyChange.pendingTitle'|trans|sw_sanitize }}</strong>
                    <p class="mb-0 mt-1">
                        {{ 'better-checkout.companyChange.pendingDetail'|trans({
                            '%newCompany%': pendingRequest.newCompanyName,
                            '%date%': pendingRequest.createdAt|format_date('short', locale=app.request.locale)
                        })|sw_sanitize }}
                    </p>
                </div>
            </div>

            <p class="mb-0 text-muted">
                {{ 'better-checkout.companyChange.pendingCannotModify'|trans|sw_sanitize }}
            </p>
        {% else %}
            {# Show the change request form #}
            <p class="mb-1">
                <strong>{{ 'better-checkout.companyChange.currentCompanyName'|trans|sw_sanitize }}</strong>
            </p>
            <p class="mb-3">{{ currentCompanyName }}</p>

            <form
                method="post"
                action="{{ path('frontend.checkout.company-name-change-request.save', { addressId: addressId }) }}"
                id="company-name-change-request-form"
                data-form-handler="true"
            >
                <input type="hidden" name="addressId" value="{{ addressId }}">

                <div class="form-group mb-3">
                    <label for="newCompanyName" class="form-label">
                        {{ 'better-checkout.companyChange.newCompanyNameLabel'|trans|sw_sanitize }}
                    </label>
                    <input
                        type="text"
                        class="form-control"
                        id="newCompanyName"
                        name="newCompanyName"
                        required="required"
                        placeholder="{{ 'better-checkout.companyChange.newCompanyNamePlaceholder'|trans|sw_sanitize }}"
                    >
                </div>

                <div class="modal-footer justify-content-end pt-0 px-0 border-0">
                    <button
                        type="button"
                        class="btn btn-outline-dark"
                        data-bs-dismiss="modal"
                    >
                        {{ 'better-checkout.companyChange.changeRequestCancel'|trans|sw_sanitize }}
                    </button>
                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        {{ 'better-checkout.companyChange.changeRequestSubmit'|trans|sw_sanitize }}
                    </button>
                </div>
            </form>
        {% endif %}
    </div>
</div>
```

### [NEW FILE] `src/Resources/views/storefront/component/address/company-field-readonly.html.twig`

This is the inline widget that replaces the company `<input>` on edit pages. It shows:
- The current company name as plain text
- A "Request Change" button (opens the modal)
- Pending change status text if applicable

```twig
{#
    Company field - read-only mode for edit pages.
    Shows current company name as text + "Request Change" button.
    If a pending change request exists, shows status text instead of button.
    
    Required variables:
      - currentCompanyName: string — the current company name
      - addressId: string — the address entity ID
      - pendingRequest: CompanyNameChangeRequestEntity|null — pending change request if any
    
    Optional variables:
      - isBillingAddress: bool — whether this is the billing address (default: true)
#}
{% set isBillingAddress = isBillingAddress is defined ? isBillingAddress : true %}

<div class="company-field-readonly mb-3">
    <label class="form-label">{{ 'address.companyNameLabel'|trans|sw_sanitize }}</label>
    
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="fw-semibold{% if currentCompanyName is empty %} text-muted{% endif %}">
            {{ currentCompanyName ?: 'better-checkout.companyChange.noCompanyName'|trans|sw_sanitize }}
        </span>

        {% if pendingRequest is defined and pendingRequest is not null %}
            <span class="badge bg-warning text-dark">
                {{ 'better-checkout.companyChange.changePendingBadge'|trans|sw_sanitize }}
            </span>
        {% else %}
            <button
                type="button"
                class="btn btn-sm btn-outline-primary"
                data-ajax-modal="true"
                data-url="{{ path('frontend.checkout.company-name-change-request.get', { addressId: addressId }) }}"
                title="{{ 'better-checkout.companyChange.changeRequestButton'|trans|striptags }}"
            >
                {{ 'better-checkout.companyChange.changeRequestButton'|trans|sw_sanitize }}
            </button>
        {% endif %}
    </div>

    {% if pendingRequest is defined and pendingRequest is not null %}
        <div class="text-muted small mt-1">
            {{ 'better-checkout.companyChange.pendingDetail'|trans({
                '%newCompany%': pendingRequest.newCompanyName,
                '%date%': pendingRequest.createdAt|format_date('short', locale=app.request.locale)
            })|sw_sanitize }}
        </div>
    {% endif %}
</div>
```

### [NEW ROUTE] Add GET route for the modal in `BillingAddressEditController`

Add a new route to serve the modal content for the company name change request:

```php
#[Route(
    path: '/widgets/checkout/company-name-change-request/{addressId}',
    name: 'frontend.checkout.company-name-change-request.get',
    options: ['seo' => false],
    defaults: ['XmlHttpRequest' => true, '_loginRequired' => true],
    methods: ['GET']
)]
public function getCompanyNameChangeRequestModal(
    string $addressId,
    SalesChannelContext $context,
    CustomerEntity $customer,
): Response {
    $address = $this->getCustomerAddress($addressId, $context, $customer);

    $pendingRequest = $this->companyNameChangeRequestService->findPendingChangeRequest(
        $customer->getId(),
        $addressId,
        $context->getContext()
    );

    $response = $this->renderStorefront(
        '@TopdataBetterCheckoutSW6/storefront/component/address/company-name-change-request-modal.html.twig',
        [
            'addressId' => $addressId,
            'currentCompanyName' => $address->getCompany() ?? '',
            'pendingRequest' => $pendingRequest,
        ],
    );
    $response->headers->set('x-robots-tag', 'noindex');
    return $response;
}
```

---

## Phase 4: Override Templates to Show Read-Only Company Field on Edit Pages

Override `address-personal-company.html.twig` to conditionally show the read-only company display on **edit** pages (when an address ID exists) vs the normal editable input on **create** pages (when no address ID exists).

### Key detection strategy for EDIT vs CREATE

- In `address-personal-company.html.twig`, the `address` variable is available. If `address.id` is set (and non-empty), it's an EDIT context. Otherwise it's CREATE.
- The `prefix` variable distinguishes address types: `'address'` = billing/default, `'shippingAddress'` = shipping.

### [MODIFY] `src/Resources/views/storefront/component/address/address-personal-company.html.twig`

Override the `component_address_form_company_name_input` block to conditionally show the read-only company field on edit pages. The detection logic:
- If `address` has an `id` field AND it's a billing address (not shipping), show the read-only widget + "Request Change" button
- If it's a CREATE context or a shipping address, show the normal input

The key change is in the `component_address_form_company_name_input` block:

```twig
{% block component_address_form_company_name_input %}
    {# Determine if this is an edit context for a billing address #}
    {% set isEditContext = address is defined and address.get('id') is not empty %}
    {% set isShipping = (prefix == 'shippingAddress') %}
    {% set isBillingEdit = isEditContext and not isShipping %}

    {% if isBillingEdit %}
        {# Edit mode: show company as read-only text + "Request Change" button #}
        {% set currentCompanyName = address.get('company') ?? '' %}

        {% set pendingRequest = null %}
        {% if page is defined and page.getExtension('topdataCompanyNameChangePending') is not null %}
            {% set pendingRequest = page.getExtension('topdataCompanyNameChangePending').changeRequest %}
        {% endif %}

        {# Hidden field to preserve the current company value in form submission #}
        <input type="hidden" name="{{ prefix ? prefix ~ '[company]' : 'company' }}" value="{{ currentCompanyName }}">

        {% sw_include '@TopdataBetterCheckoutSW6/storefront/component/address/company-field-readonly.html.twig' with {
            currentCompanyName: currentCompanyName,
            addressId: address.get('id'),
            pendingRequest: pendingRequest,
            isBillingAddress: true,
        } %}
    {% else %}
        {# Create mode or shipping: show normal editable input #}
        {% set isShipping = (prefix == 'shippingAddress') %}
        {% set companyValidationSetting = ... %}
        {# ... existing validation logic unchanged ... #}

        {% sw_include '@Storefront/storefront/component/form/form-input.html.twig' with {
            label: 'address.companyNameLabel'|trans|sw_sanitize,
            id: idPrefix ~ prefix ~ 'company',
            name: prefix ? prefix ~ '[company]' : 'company',
            value: address.get('company'),
            autocomplete: 'section-personal organization',
            violationPath: violationPath,
            validationRules: validationRules,
            additionalClass: 'col-12',
        } %}
    {% endif %}
{% endblock %}
```

The full template will be a modification of the existing file, keeping all the account type logic and only changing the `component_address_form_company_name_input` block.

### [MODIFY] `src/Resources/views/storefront/component/address/billing-address-edit-modal.html.twig`

Update the billing address edit modal to pass the pending request data to the company field template. Remove the separate pending warning at the top (it's now integrated into the company field display).

Also: since the company field is now read-only in edit mode and we include a hidden input with the current value, the form submission will NOT change the company. The only way to change it is through the dedicated "Request Change" modal.

### [MODIFY] `src/Resources/views/storefront/page/checkout/confirm/index.html.twig`

Keep the checkout blocking logic. If a pending change request exists, the checkout confirm page shows the red alert and blocks order placement. No changes needed to the logic, but verify it still works with the renamed file.

---

## Phase 5: Handle the Address Book Edit Page (Account Area)

The account area address edit page (`frontend.account.address.edit.page`) uses the same `address-personal-company.html.twig` template. The `isEditContext` detection in Phase 4 will work here too because the address object has an `id` when editing.

However, we need to ensure the `topdataCompanyNameChangePending` extension is available on this page. The `AccountAddressPageSubscriber` already adds it to `AddressListingPageLoadedEvent` and `AddressDetailPageLoadedEvent`, so it's available.

For the address detail page (`AddressDetailPageLoadedEvent`), the pending request will be available via `page.getExtension('topdataCompanyNameChangePending')`. We need to verify this works in the template context.

### [MODIFY] `src/Core/Checkout/Customer/Subscriber/AccountAddressPageSubscriber.php`

The subscriber currently adds the pending extension with `findPendingChangeRequestForCustomer()`. This finds _any_ pending request for the customer, not tied to a specific address. For the edit page, we need the pending request for the _specific_ address being edited.

Add a new subscriber method for `AddressDetailPageLoadedEvent` that also passes the pending request for the specific address being edited, so the read-only company field can display the correct pending change.

Actually, the existing subscriber already does this — it adds the extension to both the listing and detail page events. The template can access `page.getExtension('topdataCompanyNameChangePending')`. This is sufficient for showing "you have a pending change" text, but for the specific address being edited, we need to check if there's a pending request for _that_ address.

We may need to add a second extension like `topdataCompanyNameChangePendingForAddress` that is address-specific. However, we can simplify this by looking up the pending request directly in the `company-field-readonly.html.twig` template or by making the subscriber more granular.

**Decision**: For simplicity, the `company-field-readonly.html.twig` widget will open a modal via `data-ajax-modal` that loads the pending request data dynamically. The modal GET endpoint (`frontend.checkout.company-name-change-request.get`) already queries the pending request by address ID. So we don't need the template to have the pending request — the modal handles it.

This simplifies the template to just show the current company name and a "Request Change" button:

```twig
<div class="company-field-readonly mb-3">
    <label class="form-label">{{ 'address.companyNameLabel'|trans|sw_sanitize }}</label>
    
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="fw-semibold{% if currentCompanyName is empty %} text-muted{% endif %}">
            {{ currentCompanyName ?: 'better-checkout.companyChange.noCompanyName'|trans|sw_sanitize }}
        </span>

        <button
            type="button"
            class="btn btn-sm btn-outline-primary"
            data-ajax-modal="true"
            data-url="{{ path('frontend.checkout.company-name-change-request.get', { addressId: addressId }) }}"
            title="{{ 'better-checkout.companyChange.changeRequestButton'|trans|striptags }}"
        >
            {{ 'better-checkout.companyChange.changeRequestButton'|trans|sw_sanitize }}
        </button>
    </div>
</div>
```

No `pendingRequest` check in the template — it's all handled by the modal.

### [MODIFY] `src/Core/Checkout/Customer/Subscriber/AccountAddressPageSubscriber.php`

No changes needed. The existing subscriber adds the `topdataCompanyNameChangePending` extension which is used by the checkout confirm page and address book listing page. The individual company field widget loads data dynamically via the modal.

---

## Phase 6: Add Snippet Translations

Add new snippet keys for the company name change request UX:

### [MODIFY] All 5 snippet files

Add the following keys under `better-checkout.companyChange`:

```json
{
    "better-checkout": {
        "companyChange": {
            "blockedTitle": "Order not possible at this time",
            "blockedMessage": "Your request to change the company name from \"%oldCompany%\" to \"%newCompany%\" is currently under review. You cannot place an order until the change is confirmed.",
            "pendingTitle": "Company name change under review",
            "pendingMessage": "Your request to change the company name is currently under review. Orders are not possible until the change is confirmed.",
            "changeRequestTitle": "Request Company Name Change",
            "changeRequestButton": "Request Change",
            "currentCompanyName": "Current Company Name:",
            "newCompanyNameLabel": "New Company Name",
            "newCompanyNamePlaceholder": "Enter the new company name",
            "changeRequestCancel": "Cancel",
            "changeRequestSubmit": "Submit Request",
            "changeRequestSubmitted": "Your company name change request has been submitted and is under review.",
            "changeRequestEmpty": "Please enter a new company name.",
            "changeRequestSameName": "The new company name is the same as the current one.",
            "pendingDetail": "Change to \"%newCompany%\" requested on %date%.",
            "pendingCannotModify": "You cannot submit another change request until the current one is reviewed.",
            "noCompanyName": "No company name set",
            "changePendingBadge": "Under review"
        }
    }
}
```

Corresponding translations for all 5 languages: `en-GB`, `de-DE`, `fr-FR`, `fr-CH`, `pt-PT`.

---

## Phase 7: Handle Account Profile Page

The profile page (`frontend.account.profile.page`) has its own company field that updates the **customer entity** (not an address). We need to also hide the company input on the profile edit page and show the read-only display + "Request Change" button.

### Create a subscriber for the profile page

### [NEW FILE] `src/Core/Checkout/Customer/Subscriber/AccountProfilePageSubscriber.php`

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Storefront\Page\Account\Profile\AccountProfilePageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangePendingExtension;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestService;

class AccountProfilePageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CompanyNameChangeRequestService $companyNameChangeRequestService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AccountProfilePageLoadedEvent::class => 'onProfilePageLoaded',
        ];
    }

    public function onProfilePageLoaded(AccountProfilePageLoadedEvent $event): void
    {
        $customer = $event->getSalesChannelContext()->getCustomer();
        if (!$customer instanceof CustomerEntity) {
            return;
        }

        $billingAddressId = $customer->getDefaultBillingAddressId();
        if ($billingAddressId === null) {
            return;
        }

        $pendingRequest = $this->companyNameChangeRequestService->findPendingChangeRequest(
            $customer->getId(),
            $billingAddressId,
            $event->getContext()
        );

        if ($pendingRequest !== null) {
            $event->getPage()->addExtension(
                'topdataCompanyNameChangePending',
                new CompanyNameChangePendingExtension($pendingRequest)
            );
        }
    }
}
```

### [MODIFY] `src/Resources/config/services.xml`

Register the new subscriber:

```xml
<service id="Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber\AccountProfilePageSubscriber">
    <argument type="service" id="Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestService"/>
    <tag name="kernel.event_subscriber"/>
</service>
```

### [NEW FILE] `src/Resources/views/storefront/page/account/profile/index.html.twig`

Override the profile page to replace the company input with read-only display + "Request Change" button:

```twig
{% sw_extends '@Storefront/storefront/page/account/profile/index.html.twig' %}
```

This will require finding the correct block in the core profile template where the company field is rendered and replacing it. The core profile form uses `@Storefront/storefront/page/account/profile/index.html.twig` which includes the personal form. We need to identify the specific block.

**Note**: The exact block name needs to be determined by reading the core template. It may be `page_account_profile_personal_company` or similar. This will be determined during implementation.

---

## Phase 8: Handle the ChangeCustomerProfileRoute

Even though the company field will be hidden on edit pages, we still need to prevent direct API submissions (e.g., via browser dev tools) from changing the company field on the profile. However, since we got an error with the decorator approach, we'll take a simpler approach: just strip the `company` field from the request data in the route decorator — no change request creation, just silently discard the company value.

Actually, since the company field is now read-only (shown as text + hidden input with current value), the form will always submit the current company value. If someone tampers with the form data, the hidden input will still send the original value. There's no way to change the company through the normal form submission flow.

However, for extra safety, we can add a simple `ChangeCustomerProfileRouteDecorator` that just removes the `company` field from the data before passing it to the decorated route. No change request creation needed — just discard any company change attempt silently.

**Decision**: Skip the decorator for now. The UX redesign makes it clear that company name changes must go through the dedicated modal. If someone tampers with form data, they're bypassing the intended UX anyway. A follow-up can add the decorator as a safety net.

---

## Phase 9: Verify and Test

1. Clear Shopware cache: `bin/console cache:clear`
2. Test the following scenarios:
   - **Create new address**: Company field should be an editable input (no change request needed for new addresses)
   - **Edit billing address from account area**: Company field should show as text + "Request Change" button
   - **Edit billing address from checkout modal**: Company field should show as text + "Request Change" button
   - **Click "Request Change" button**: Modal should open with current company name and input for new company name
   - **Submit change request**: Should create a pending request, show success message, and redirect
   - **Submit change request when one already exists**: Modal should show pending request info
   - **Checkout with pending request**: Should show red blocking alert, prevent order placement
   - **Address book listing**: Should show pending change warning if one exists
   - **Profile edit page**: Company field should show as text + "Request Change" button (using billing address)
   - **Shipping address edit**: Company field should be editable (shipping addresses don't need approval)

---

## Summary of All Files

| Action | File | Description |
|---|---|---|
| MODIFY | `src/Core/Checkout/Customer/SalesChannel/UpsertAddressRouteDecorator.php` | Remove company change interception, revert to original 3-method class |
| DELETE | `src/Core/Checkout/Customer/SalesChannel/ChangeCustomerProfileRouteDecorator.php` | Remove broken decorator entirely |
| MODIFY | `src/Resources/config/services.xml` | Remove `ChangeCustomerProfileRouteDecorator` service, revert `UpsertAddressRouteDecorator` to 2 args, add `AccountProfilePageSubscriber` |
| MODIFY | `src/Controller/BillingAddressEditController.php` | Remove company interception from `saveBillingAddress()`, add GET and POST routes for company name change request modal, pass pending request entity instead of bool |
| NEW | `src/Resources/views/storefront/component/address/company-name-change-request-modal.html.twig` | Modal for requesting a company name change |
| NEW | `src/Resources/views/storefront/component/address/company-field-readonly.html.twig` | Read-only company display with "Request Change" button |
| MODIFY | `src/Resources/views/storefront/component/address/address-personal-company.html.twig` | Override company name input block to show read-only field on edit pages |
| MODIFY | `src/Resources/views/storefront/component/address/billing-address-edit-modal.html.twig` | Simplify — remove separate pending warning, company field is now read-only |
| RENAME/KEEP | `src/Resources/views/storefront/page/checkout/confirm/index.html.twig` | Keep the checkout blocking logic (already renamed from `confirm-company-name-change-pending.html.twig`) |
| NEW | `src/Core/Checkout/Customer/Subscriber/AccountProfilePageSubscriber.php` | Add pending change extension to profile page |
| NEW | `src/Resources/views/storefront/page/account/profile/index.html.twig` | Override profile page to hide company input, show read-only + button |
| MODIFY | All 5 snippet files | Add new translation keys for change request UX |