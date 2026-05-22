---
filename: "_ai/backlog/active/260521_1053__IMPLEMENTATION_PLAN__custom-conversion-checkout.md"
title: "Replace 3rd Party Conversion Checkout with Custom Implementation"
createdAt: 2026-05-21 10:53
updatedAt: 2026-05-21 10:53
status: completed
priority: high
tags: [checkout, storefront, plugin, template-override, cleanup]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

# Problem Description
The online shop currently uses a 3rd party plugin to provide a 3-box selection ("Register as new", "Login", "Guest") during the checkout process. Using a 3rd party plugin limits flexibility and causes conflicts (e.g., handling the `account_type` logic). We need to remove the reliance on the existing plugin and implement a clean, customizable 3-box checkout page natively using our `TopdataBetterCheckoutSW6` plugin. Additionally, we must enforce the "Business" account type for normal registrations (`/account/login`), while allowing guest checkouts to choose between Private and Business. Overwrites related to the old plugin in the `topdata-theme-focus-sw6` must be cleaned up.

# Executive Summary
This implementation plan will:
1. **Clean up the Theme:** Delete the legacy JavaScript hacks from `topdata-theme-focus-sw6`.
2. **Implement Snippets:** Add translations for the new checkout boxes in the `TopdataBetterCheckoutSW6` plugin.
3. **Build the 3-Box Checkout Layout:** Extend the Shopware standard `/checkout/register` page via Twig to display three Bootstrap cards (Register, Login, Guest) when no specific flow is selected.
4. **Enforce Business Accounts:** Override the `address-personal.html.twig` template to conditionally replace the Account Type selector with a hidden `business` input only on the standard `/account/login` route.

# Project Environment
- **Shopware Version:** 6.6 / 6.7
- **Target Plugin:** `TopdataBetterCheckoutSW6`

- **Frontend Stack:** Twig, Bootstrap 5 (native to SW6)

---

# Implementation Phases



## Phase 2: Create Snippets for the new Checkout UI
We will add the necessary text snippets for our custom 3-box checkout page into the `TopdataBetterCheckoutSW6` plugin.

```php
[NEW FILE] src/Resources/snippet/de_DE/SnippetFile_de_DE.php (in TopdataBetterCheckoutSW6)
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Resources\snippet\de_DE;

use Shopware\Core\System\Snippet\Files\SnippetFileInterface;

class SnippetFile_de_DE implements SnippetFileInterface
{
    public function getName(): string { return 'storefront.de-DE'; }
    public function getPath(): string { return __DIR__ . '/storefront.de-DE.json'; }
    public function getIso(): string { return 'de-DE'; }
    public function getAuthor(): string { return 'TopData Software GmbH'; }
    public function isBase(): bool { return false; }
}
```

```json
[NEW FILE] src/Resources/snippet/de_DE/storefront.de-DE.json (in TopdataBetterCheckoutSW6)
{
    "better-checkout": {
        "box": {
            "registerTitle": "Ich möchte mich als neuer Kunde registrieren",
            "registerText": "Melden Sie sich einmal an und profitieren Sie für lange Zeit.",
            "registerBtn": "Ein Konto erstellen",
            "loginTitle": "Ich habe bereits ein Konto",
            "guestTitle": "Ich möchte nur als Gast bestellen",
            "guestText": "Der schnelle Weg zu Ihrer Bestellung ohne Kundenkonto",
            "guestBtn": "Bestellung als Gast"
        }
    }
}
```

*(Repeat the same structure for `en_GB` if English translations are required in your shop).*

## Phase 3: Implement the 3-Box Checkout Layout
We will intercept the standard `frontend.checkout.register.page` template. If no flow parameter (`checkoutType`) is present, we show the 3 cards. If the user selects a card, they proceed to the respective form.

```twig
[NEW FILE] src/Resources/views/storefront/page/checkout/address/index.html.twig (in TopdataBetterCheckoutSW6)
{% sw_extends '@Storefront/storefront/page/checkout/address/index.html.twig' %}

{% block page_checkout_main_content %}
    {% set checkoutType = app.request.query.get('checkoutType') ?: app.request.request.get('checkoutType') %}
    
    {% if not checkoutType %}
        <div class="row topdata-better-checkout-boxes mt-4">
            {# Box 1: Register #}
            <div class="col-md-4 mb-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body d-flex flex-column text-center p-4">
                        <h3 class="card-title h5 mb-3">{{ "better-checkout.box.registerTitle"|trans|sw_sanitize }}</h3>
                        <p class="card-text flex-grow-1 text-muted">{{ "better-checkout.box.registerText"|trans|sw_sanitize }}</p>
                        <a href="{{ path('frontend.checkout.register.page', {checkoutType: 'register'}) }}" class="btn btn-primary w-100 mt-3">
                            {{ "better-checkout.box.registerBtn"|trans|sw_sanitize }}
                        </a>
                    </div>
                </div>
            </div>

            {# Box 2: Login #}
            <div class="col-md-4 mb-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body d-flex flex-column p-4">
                        <h3 class="card-title h5 mb-4 text-center">{{ "better-checkout.box.loginTitle"|trans|sw_sanitize }}</h3>
                        {% sw_include '@Storefront/storefront/component/account/login.html.twig' %}
                    </div>
                </div>
            </div>

            {# Box 3: Guest #}
            <div class="col-md-4 mb-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body d-flex flex-column text-center p-4">
                        <h3 class="card-title h5 mb-3">{{ "better-checkout.box.guestTitle"|trans|sw_sanitize }}</h3>
                        <p class="card-text flex-grow-1 text-muted">{{ "better-checkout.box.guestText"|trans|sw_sanitize }}</p>
                        <a href="{{ path('frontend.checkout.register.page', {checkoutType: 'guest'}) }}" class="btn btn-secondary w-100 mt-3">
                            {{ "better-checkout.box.guestBtn"|trans|sw_sanitize }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    {% else %}
        {{ parent() }}
    {% endif %}
{% endblock %}

{% block page_checkout_address_login %}
    {% set checkoutType = app.request.query.get('checkoutType') ?: app.request.request.get('checkoutType') %}
    {% if checkoutType %}
        {# We hide the left-side login form when the user explicitly chose to register or checkout as guest #}
    {% else %}
        {{ parent() }}
    {% endif %}
{% endblock %}

{% block page_checkout_address_register %}
    {% set checkoutType = app.request.query.get('checkoutType') ?: app.request.request.get('checkoutType') %}
    {% if checkoutType %}
        <div class="col-12 col-lg-8 offset-lg-2">
            {{ parent() }}
        </div>
    {% else %}
        {{ parent() }}
    {% endif %}
{% endblock %}
```

Next, ensure the Guest Checkbox behaves correctly based on the `checkoutType`:

```twig
[NEW FILE] src/Resources/views/storefront/page/checkout/address/register.html.twig (in TopdataBetterCheckoutSW6)
{% sw_extends '@Storefront/storefront/page/checkout/address/register.html.twig' %}

{% block page_checkout_register_personal_guest %}
    {% set checkoutType = app.request.query.get('checkoutType') ?: app.request.request.get('checkoutType') %}
    
    {% if checkoutType == 'guest' %}
        {# Enforce Guest and hide the checkbox to avoid confusion #}
        <input type="hidden" name="guest" value="1">
    {% elseif checkoutType == 'register' %}
        {# Enforce normal registration and hide the checkbox #}
        <input type="hidden" name="guest" value="">
    {% else %}
        {{ parent() }}
    {% endif %}
{% endblock %}
```

Ensure the parameter persists if validation fails:

```twig
[NEW FILE] src/Resources/views/storefront/component/account/register.html.twig (in TopdataBetterCheckoutSW6)
{% sw_extends '@Storefront/storefront/component/account/register.html.twig' %}

{% block component_account_register_form_action %}
    {{ parent() }}
    {% set checkoutType = app.request.query.get('checkoutType') ?: app.request.request.get('checkoutType') %}
    {% if checkoutType %}
        <input type="hidden" name="checkoutType" value="{{ checkoutType }}">
    {% endif %}
{% endblock %}
```

## Phase 4: Enforce Business Account for Standard Registration
We will intercept `component/address/address-personal.html.twig`. 

*Note on Shopware Architecture:* You might wonder why the `accountType` (which belongs to the Customer) is located in the `address-personal.html.twig` template, especially when shipping and billing addresses exist. In Shopware 6, `address-personal.html.twig` is a shared template that renders "Personal Information" (like First Name, Last Name, and Account Type) and is included *once* at the top of the main registration form (`register.html.twig`). The `accountType` field generated here is globally named `accountType` (not prefixed) and modifies the `customer` entity. JavaScript (via CSS toggles) then uses this global selection to show/hide the company fields within the separate billing and shipping address sub-forms. Because Shopware Core puts the selector in this template, we must override it here.

If the route is the normal registration (`/account/login`), we hide the selector and enforce "business".

```twig
[NEW FILE] src/Resources/views/storefront/component/address/address-personal.html.twig (in TopdataBetterCheckoutSW6)
{% sw_extends '@Storefront/storefront/component/address/address-personal.html.twig' %}

{% block component_address_personal_account_type %}
    {% set currentRoute = app.request.attributes.get('_route') %}
    
    {% if currentRoute == 'frontend.account.login.page' %}
        {# Force Business Account Type on standard registration page #}
        <input type="hidden" name="{% if prefix %}{{ prefix }}[accountType]{% else %}accountType{% endif %}" value="{{ constant('Shopware\\Core\\Checkout\\Customer\\CustomerEntity::ACCOUNT_TYPE_BUSINESS') }}">
    {% else %}
        {# Allow Private/Business selection during Guest Checkout or anywhere else #}
        {{ parent() }}
    {% endif %}
{% endblock %}
```

## Phase 5: Service Configuration
Register the new Snippet file in `services.xml`.

```xml
[MODIFY] src/Resources/config/services.xml (in TopdataBetterCheckoutSW6)
```
Add the snippet definition inside `<services>`:
```xml
        <service id="Topdata\TopdataBetterCheckoutSW6\Resources\snippet\de_DE\SnippetFile_de_DE">
            <tag name="shopware.snippet.file"/>
        </service>
```

---

## Final Phase: Writing the Report
After implementing the changes, a report will be created to document the successful replacement of the 3rd party plugin.

```yaml
---
filename: "_ai/backlog/reports/{YYMMDD_HHmm}__IMPLEMENTATION_REPORT__custom-conversion-checkout.md"
title: "Report: Replace 3rd Party Conversion Checkout with Custom Implementation"
createdAt: 2026-05-21 11:00
updatedAt: 2026-05-21 11:00
planFile: "_ai/backlog/active/{YYMMDD_HHmm}__IMPLEMENTATION_PLAN__custom-conversion-checkout.md"
project: "topdata-better-checkout-sw6"
status: completed
filesCreated: 5
filesModified: 1
filesDeleted: 1
tags: [checkout, storefront, plugin, template-override, cleanup]
documentType: IMPLEMENTATION_REPORT
---
```

**Content structure for the report:**
1. **Summary:** Successfully removed the legacy plugin dependencies from the theme and implemented a native, lightweight 3-box checkout page in `TopdataBetterCheckoutSW6`.
2. **Files Changed:** Listing the new Twig template overrides, the deleted JS hack in the old theme, and snippet additions.
3. **Key Changes:** Enforced business account type on `/account/login` via Twig logic. Replaced complex plugin-overwrites with a state-driven URL parameter `?checkoutType=`.
4. **Testing Notes:** Compile theme (`bin/console theme:compile`). Go to checkout without being logged in to verify the 3 boxes. Test standard registration `/account/login` to ensure the account type dropdown is hidden and company fields are visible. Test guest checkout to ensure the private/business toggle is available.

