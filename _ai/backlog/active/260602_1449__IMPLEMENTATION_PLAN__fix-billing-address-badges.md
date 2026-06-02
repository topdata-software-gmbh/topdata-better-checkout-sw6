---
filename: "_ai/backlog/active/260602_1449__IMPLEMENTATION_PLAN__fix-billing-address-badges.md"
title: "Fix billing address badges in account address book"
createdAt: 2026-06-02 14:49
updatedAt: 2026-06-02 14:49
status: draft
priority: high
tags: [storefront, twig, templates, bugfix]
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description
There is a visual bug in the customer account address book (`/account/address`). The "Rechnungsadresse" (Billing Address) badge is being displayed on almost every address in the list. This happens because the `defaultBilling: true` flag was hardcoded in the loop rendering the list of available addresses. 

Additionally, because the plugin enforces a strict separation of billing and shipping addresses and displays the active billing address in its own designated UI section at the top ("Rechnungsadresse" heading), a redundant badge inside the card is unnecessary and has been requested to be removed entirely.

A secondary bug was also identified in the same file: the default billing address was hardcoded with `defaultShipping: true`, causing it to incorrectly display the shipping badge even if the shipping address was set to a completely different address.

## 2. Executive Summary
This implementation plan will resolve the badge display issues entirely by modifying the storefront Twig templates:
1. **`address-manager.html.twig`**: Correct the hardcoded boolean flags passed to the `address-item.html.twig` include. We will dynamically check if the billing address is the shipping address, and we will pass `defaultBilling: false` for all addresses in the general list.
2. **`address-item.html.twig`**: Suppress the billing address badge entirely by overriding the `address_item_badge` block. We will use a safe Twig context trick to set `defaultBilling = false` right before calling `parent()`, ensuring Shopware core won't render the billing badge, but will accurately fall back to native styling for the shipping badge when appropriate.

## 3. Project Environment Details
- **Project Name:** SW6.7 Plugin (TopdataBetterCheckoutSW6)
- **Backend root:** `src`
- **PHP Version:** 8.2+
- **Shopware Version:** 6.7

## 4. Implementation Steps

### Phase 1: Update Address Manager Template
Fix the hardcoded template variables to prevent all addresses from being incorrectly flagged as billing/shipping addresses.

```twig
[MODIFY] src/Resources/views/storefront/page/account/addressbook/address-manager.html.twig
---
{% sw_extends '@Storefront/storefront/page/account/addressbook/address-manager.html.twig' %}

{% block address_base_default_address_item %}
    {% sw_include '@Storefront/storefront/page/account/addressbook/address-item.html.twig' with {
        address: defaultBillingAddress,
        defaultBilling: true,
        defaultShipping: defaultShippingAddress.id == defaultBillingAddress.id,
        isBillingAddress: true
    }  %}
{% endblock %}

{% block address_base_list_title %}
    <h2 class="mt-4">
        {{ "better-checkout.account.addressesAvailable"|trans|sw_sanitize }}
    </h2>
{% endblock %}

{% block address_base_list_address_item %}
    {% if address.id != defaultBillingAddress.id %}
        {% sw_include '@Storefront/storefront/page/account/addressbook/address-item.html.twig' with {
            address: address,
            defaultShipping: defaultShippingAddress.id == address.id,
            defaultBilling: false
        }  %}
    {% endif %}
{% endblock %}
```

### Phase 2: Update Address Item Template
Remove the custom billing badge entirely and rely on core Shopware behavior for the shipping badge by manipulating the template context.

```twig
[MODIFY] src/Resources/views/storefront/page/account/addressbook/address-item.html.twig
---
{% sw_extends '@Storefront/storefront/page/account/addressbook/address-item.html.twig' %}

{% block address_item_dropdown_items %}
    <li>
        <a
            class="dropdown-item address-manager-modal-address-form"
            href="{{ path('frontend.account.address.edit.page', {addressId: address.id}) }}"
        >
            {{ 'global.default.edit'|trans|sw_sanitize }}
        </a>
    </li>
    {% if not defaultShipping %}
        <li>
            <form
                action="{{ path('frontend.account.address.set-default-address', {type: 'shipping', addressId: address.id}) }}"
                method="post"
            >
                <button
                    type="submit"
                    title="{{ 'account.addressesSetAsDefaultShippingAction'|trans|striptags }}"
                    class="dropdown-item"{% if not address.country.shippingAvailable %}disabled="disabled"{% endif %}
                >
                    {{ 'account.addressesSetAsDefaultShippingAction'|trans|sw_sanitize }}
                </button>
            </form>
        </li>
    {% endif %}
    {% if not (defaultShipping or defaultBilling) %}
        <li>
            <form
                action="{{ path('frontend.account.address.delete', {addressId: address.id}) }}"
                method="post"
            >
                <button type="submit" class="dropdown-item text-danger">
                    {{ 'account.addressesContentItemActionDelete'|trans|sw_sanitize }}
                </button>
            </form>
        </li>
    {% endif %}
{% endblock %}

{% block address_item_badge %}
    {# 
       We intentionally suppress the billing badge to keep the UI clean, 
       but we preserve the shipping badge using native Shopware core HTML.
    #}
    {% set originalDefaultBilling = defaultBilling %}
    {% set defaultBilling = false %}

    {% if defaultShipping %}
        {{ parent() }}
    {% endif %}

    {% set defaultBilling = originalDefaultBilling %}
{% endblock %}
```

### Phase 3: Create Implementation Report
Write the final execution report mapping out exactly what was changed and asserting completion.

```yaml
---
filename: "_ai/backlog/reports/260602_1449__IMPLEMENTATION_REPORT__fix-billing-address-badges.md"
title: "Report: Fix billing address badges in account address book"
createdAt: 2026-06-02 14:49
updatedAt: 2026-06-02 14:49
planFile: "_ai/backlog/active/260602_1449__IMPLEMENTATION_PLAN__fix-billing-address-badges.md"
project: "TopdataBetterCheckoutSW6"
status: completed
filesCreated: 0
filesModified: 2
filesDeleted: 0
tags: [storefront, twig, templates, bugfix]
documentType: IMPLEMENTATION_REPORT
---
```
*(Report contents will follow the instructed format)*
```

