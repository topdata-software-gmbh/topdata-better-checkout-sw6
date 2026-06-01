---
filename: "_ai/backlog/active/260527_0223__IMPLEMENTATION_PLAN__fix_registration_account_type_dropdown.md"
title: "Fix Registration Account Type Dropdown on Account Login Page"
createdAt: 2026-05-27 02:23
updatedAt: 2026-05-27 02:23
status: in-progress
priority: medium
tags: [shopware-6, storefront, twig, bugfix, plugin-config]
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---

# Problem Statement
The custom Shopware 6 plugin has a setting `Registrierung - Kontotyp` designed to force the customer account type (e.g., "Immer Gewerblich" / Always Business). While this configuration correctly forces the account type and hides the dropdown during the 3-box checkout intermediate step, it fails to apply on the default Shopware account registration page (`/account/login`). On this page, the "Kontotyp" (Account type) dropdown is incorrectly displayed, allowing users to override the plugin's configuration.

# Executive Summary
To resolve this inconsistency, we will extend the base Storefront registration component (`storefront/component/account/register.html.twig`). By targeting the `component_account_register_personal_account_type` Twig block, we can globally evaluate the plugin's configuration parameter. If the setting dictates a forced account type (like "Immer Gewerblich"), we will intercept the rendering to replace the visible dropdown with a hidden pre-selected element. This satisfies the Shopware native JavaScript (`FormFieldToggle`), ensuring company fields are correctly displayed upon page load, while strictly enforcing the configuration setting across all registration avenues.

# Project Environment Details
- **Platform:** Shopware 6
- **Architecture:** Plugin System / Storefront Theme extending
- **Tech Stack:** Twig, HTML, JavaScript, CSS
- **Design Pattern:** Open/Closed Principle (Extending via Twig Blocks rather than hardcoding controller overrides)

---

# Implementation Plan

## Phase 1: Identify Configuration Keys
Before applying the Twig patch, the AI coding agent must verify the exact plugin configuration domain and keys used for the "Registrierung - Kontotyp" setting.
*   **Action:** Locate the `config.xml` in `src/Resources/config/` of the plugin.
*   **Target Data:** Determine the `<name>` of the config element (e.g., `registrationCustomerGroup`) and the exact `<option>` `<value>` strings (e.g., `always_business`, `always_private`). 
*   *Note: In the code blocks below, placeholders `YourPlugin.config.registrationCustomerGroup` and `always_business` are used and must be substituted with the actual plugin values.*

## Phase 2: Extend the Storefront Registration Component
We will extend the global registration component block. This guarantees that whether the form is rendered on the checkout page or the `/account/login` route, the plugin configuration is uniformly respected.

### File Modifications

```twig
{# [NEW FILE] src/Resources/views/storefront/component/account/register.html.twig #}
{# Note: If this file already exists in the plugin, [MODIFY] it by appending/merging this block. #}

{% sw_extends '@Storefront/storefront/component/account/register.html.twig' %}

{% block component_account_register_personal_account_type %}
    {# 
       TODO for AI Agent: 
       Replace 'YourPlugin.config.registrationCustomerGroup' with the exact config path.
       Replace 'always_business' / 'always_private' with the exact value strings from config.xml.
    #}
    {% set registrationAccountType = config('YourPlugin.config.registrationCustomerGroup') %}

    {% if registrationAccountType == 'always_business' or registrationAccountType == 'always_private' %}
        
        {# Hide the field visually but retain standard DOM structure for standard Shopware JS initialization #}
        <div class="d-none">
            <select name="accountType"
                    id="personalAccountType"
                    class="custom-select form-select"
                    required="required"
                    data-form-field-toggle="true"
                    data-form-field-toggle-target=".js-field-toggle-contact-type-company"
                    data-form-field-toggle-value="{{ constant('Shopware\\Core\\Checkout\\Customer\\CustomerEntity::ACCOUNT_TYPE_BUSINESS') }}"
                    data-form-field-toggle-scope="{% if isUsingHiddenScope %}all{% else %}parent{% endif %}">
                
                {% if registrationAccountType == 'always_business' %}
                    <option value="{{ constant('Shopware\\Core\\Checkout\\Customer\\CustomerEntity::ACCOUNT_TYPE_BUSINESS') }}" selected="selected"></option>
                {% else %}
                    <option value="{{ constant('Shopware\\Core\\Checkout\\Customer\\CustomerEntity::ACCOUNT_TYPE_PRIVATE') }}" selected="selected"></option>
                {% endif %}
            </select>
        </div>
        
        {# Fallback CSS rule to prevent FOUC (Flash of Unstyled Content) before JS initializes the field toggles #}
        <style>
            {% if registrationAccountType == 'always_business' %}
                .js-field-toggle-contact-type-company { display: block !important; }
            {% else %}
                .js-field-toggle-contact-type-company { display: none !important; }
            {% endif %}
        </style>
        
    {% else %}
        {# Fallback to default Shopware behavior if 'Let user choose' is selected #}
        {{ parent() }}
    {% endif %}
{% endblock %}
```

## Phase 3: Update Documentation
Since the plugin's scope of effectiveness is being widened to encompass standard account registration, a small update to the changelog and user instructions is required.

```markdown
<!-- [MODIFY] README.md or CHANGELOG.md -->
### Fixed
- Fixed an issue where the "Registrierung - Kontotyp" (Registration Customer Group) configuration was bypassed and rendered a manual dropdown on the default `/account/login` route. The chosen setting is now globally enforced across all registration pages.
```

## Phase 4: Compile and Test
*   **Command:** Run `bin/console theme:compile` and `bin/console cache:clear` to apply the Twig templates.
*   **Verification 1:** Navigate to `/account/login` and verify the "Kontotyp" dropdown is hidden.
*   **Verification 2:** If configured to "Immer Gewerblich", verify the Company (Firma) and VAT (USt-IdNr) fields are instantly visible.
*   **Verification 3:** Test form submission to ensure validation passes and the account is registered successfully as a Commercial account.

---

# Final Reporting
After executing this plan, the AI agent must generate an implementation report strictly following this structure in `_ai/backlog/reports/`:

```yaml
---
filename: "_ai/backlog/reports/260527_0223__IMPLEMENTATION_REPORT__fix_registration_account_type_dropdown.md"
title: "Report: Fix Registration Account Type Dropdown on Account Login Page"
createdAt: 2026-05-27 02:23
updatedAt: YYYY-MM-DD HH:mm
planFile: "_ai/backlog/active/260527_0223__IMPLEMENTATION_PLAN__fix_registration_account_type_dropdown.md"
project: "Shopware Plugin Bugfix"
status: completed|partial|blocked
filesCreated: X
filesModified: Y
filesDeleted: Z
tags: [shopware-6, bugfix, storefront]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
[Brief overview of what was accomplished (2-3 sentences)]

## 2. Files Changed
- **New Files**:
  - `[File path]` - [Brief description]
- **Modified Files**:
  - `[File path]` - [Summary of changes]
- **Deleted Files**:
  - `[File path]` - [Reason]

## 3. Key Changes
- [Bullet points of the main technical changes made]

## 4. Deviations from Plan
[What broke, what performance issues were hit, and why a different approach was taken, if any]

## 5. Technical Decisions
[Important design decisions or trade-offs made during implementation, e.g., using CSS overrides alongside hidden selects]

## 6. Testing Notes
[How the changes were verified]

## 7. Usage Examples (if applicable)
[CLI commands run, e.g., cache clearing]

## 8. Documentation Updates
[Summary of changelog/readme modifications]

## 9. Next Steps (optional)
[Follow-up work]
```

