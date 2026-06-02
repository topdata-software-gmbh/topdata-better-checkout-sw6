---
filename: "_ai/backlog/active/260602_1016__IMPLEMENTATION_PLAN__account_address_headings.md"
title: "Update headings on account address page"
createdAt: 2026-06-02 10:16
updatedAt: 2026-06-02 10:16
status: draft
priority: medium
tags: [storefront, account, address, twig, snippets]
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description
On the storefront route `/account/address` (the customer's address book), the default headings provided by Shopware are generic. The top heading displays "Adressen" (Addresses), and the lower section displaying the list of additional addresses is titled "Verfügbare Adressen" (Available addresses). The requirement is to change these headings specifically on this page to be more contextually precise:
- The top heading should say "Rechnungsadresse" (Billing address) instead of "Adressen".
- The bottom list heading should say "Verfügbare Lieferadressen" (Available shipping addresses) instead of "Verfügbare Adressen".

## 2. Executive Summary
This implementation plan provides a targeted solution by overriding the Shopware 6 core Storefront template for the address book index page (`storefront/page/account/addressbook/index.html.twig`). To adhere to Shopware standards and maintain multi-language support, new custom snippet keys will be added to the plugin's existing language files (de-DE, en-GB, fr-FR, fr-CH, pt-PT). The new template will extend the core address book template and replace only the specific title blocks to output the new snippet values, ensuring maximum compatibility and zero impact on other areas (like breadcrumbs or navigation menus) that rely on the original generic snippets.

## 3. Project Environment Details
- Project Name: SW6.7 Plugin
- Backend root: src
- PHP Version: 8.3 / 8.4

---

## 4. Implementation Phases

### Phase 1: Update Storefront Snippets
The AI coding agent must add the new snippet keys for the account address book into all 5 existing language files.

**[MODIFY] `src/Resources/snippet/de_DE/storefront.de-DE.json`**
Inject the `"account"` object inside `"better-checkout"`:
```json
{
    "better-checkout": {
        "...": "...",
        "account": {
            "addressesTitle": "Rechnungsadresse",
            "addressesAvailable": "Verfügbare Lieferadressen"
        }
    }
}
```

**[MODIFY] `src/Resources/snippet/en_GB/storefront.en-GB.json`**
```json
{
    "better-checkout": {
        "...": "...",
        "account": {
            "addressesTitle": "Billing address",
            "addressesAvailable": "Available shipping addresses"
        }
    }
}
```

**[MODIFY] `src/Resources/snippet/fr_FR/storefront.fr-FR.json`**
```json
{
    "better-checkout": {
        "...": "...",
        "account": {
            "addressesTitle": "Adresse de facturation",
            "addressesAvailable": "Adresses de livraison disponibles"
        }
    }
}
```

**[MODIFY] `src/Resources/snippet/fr_CH/storefront.fr-CH.json`**
```json
{
    "better-checkout": {
        "...": "...",
        "account": {
            "addressesTitle": "Adresse de facturation",
            "addressesAvailable": "Adresses de livraison disponibles"
        }
    }
}
```

**[MODIFY] `src/Resources/snippet/pt_PT/storefront.pt-PT.json`**
```json
{
    "better-checkout": {
        "...": "...",
        "account": {
            "addressesTitle": "Endereço de faturação",
            "addressesAvailable": "Endereços de entrega disponíveis"
        }
    }
}
```

### Phase 2: Override Storefront Template
The agent will create a new twig template overriding the default account address book template to inject the new snippet keys.
*Agent Instruction: Before creating this file, briefly inspect the Shopware core file `@Storefront/storefront/page/account/addressbook/index.html.twig` to confirm the exact block names for the top title (`page_account_addresses_welcome_title`) and list title (`page_account_addresses_list_title` or similar). The code below uses the standard Shopware 6 block names.*

**[NEW FILE] `src/Resources/views/storefront/page/account/addressbook/index.html.twig`**
```twig
{% sw_extends '@Storefront/storefront/page/account/addressbook/index.html.twig' %}

{# Override the main top heading #}
{% block page_account_addresses_welcome_title %}
    <h1 class="account-welcome-title">
        {{ "better-checkout.account.addressesTitle"|trans|sw_sanitize }}
    </h1>
{% endblock %}

{# Override the available addresses list heading #}
{% block page_account_addresses_list_title %}
    <h2 class="account-address-list-title">
        {{ "better-checkout.account.addressesAvailable"|trans|sw_sanitize }}
    </h2>
{% endblock %}
```

### Phase 3: Update User Documentation
Update the README to mention that the plugin also customizes the customer account address overview page headings for a better UX, aligning with the plugin's isolated billing/shipping logic.

**[MODIFY] `README.md`**
* Locate the `## Features` section.
* Add the following bullet point:
```markdown
- **Address Book Optimization**: Renames the generic headings in the customer account (`/account/address`) to distinctly display "Billing address" and "Available shipping addresses" for improved clarity.
```

### Phase 4: Generate Implementation Report
The AI coding agent must finalize the task by writing an implementation report documenting the changes made.

**[NEW FILE] `_ai/backlog/reports/260602_1016__IMPLEMENTATION_REPORT__account_address_headings.md`**
```yaml
---
filename: "_ai/backlog/reports/260602_1016__IMPLEMENTATION_REPORT__account_address_headings.md"
title: "Report: Update headings on account address page"
createdAt: 2026-06-02 10:16
updatedAt: 2026-06-02 10:16
planFile: "_ai/backlog/active/260602_1016__IMPLEMENTATION_PLAN__account_address_headings.md"
project: "SW6.7 Plugin"
status: completed
filesCreated: 2
filesModified: 6
filesDeleted: 0
tags: [storefront, account, address, twig, snippets]
documentType: IMPLEMENTATION_REPORT
---

## Summary
Successfully updated the `/account/address` storefront page headings to explicitly state "Rechnungsadresse" and "Verfügbare Lieferadressen" (and their localized equivalents) via template block overrides and custom snippets.

## Files Changed
- **Created:**
  - `src/Resources/views/storefront/page/account/addressbook/index.html.twig`: Overrides core blocks to inject new snippet keys.
  - `_ai/backlog/reports/260602_1016__IMPLEMENTATION_REPORT__account_address_headings.md`: This report.
- **Modified:**
  - `src/Resources/snippet/de_DE/storefront.de-DE.json`: Added `account` keys.
  - `src/Resources/snippet/en_GB/storefront.en-GB.json`: Added `account` keys.
  - `src/Resources/snippet/fr_FR/storefront.fr-FR.json`: Added `account` keys.
  - `src/Resources/snippet/fr_CH/storefront.fr-CH.json`: Added `account` keys.
  - `src/Resources/snippet/pt_PT/storefront.pt-PT.json`: Added `account` keys.
  - `README.md`: Documented the new address book UX enhancement.

## Key Changes
- Introduced new namespace `better-checkout.account.addressesTitle` and `better-checkout.account.addressesAvailable` into all 5 plugin snippet locales.
- Extended `@Storefront/storefront/page/account/addressbook/index.html.twig`.
- Overrode blocks `page_account_addresses_welcome_title` and `page_account_addresses_list_title`.

## Deviations from Plan
None. Template blocks were confirmed to align with Shopware 6.7 standards.

## Testing Notes
1. Log into the storefront as a customer.
2. Navigate to `My Account -> Addresses` (`/account/address`).
3. Verify the main page heading correctly reads "Rechnungsadresse" (in German) or "Billing address" (in English).
4. Verify the secondary list heading reads "Verfügbare Lieferadressen" (in German).
5. Switch the storefront language to verify translations load successfully.

