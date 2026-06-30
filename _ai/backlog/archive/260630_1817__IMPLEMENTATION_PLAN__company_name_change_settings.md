---
filename: "_ai/backlog/active/260630_1817__IMPLEMENTATION_PLAN__company_name_change_settings.md"
title: "Implementation Plan: Company Name Change Request — Plugin Settings Card"
createdAt: 2026-06-30 18:17
updatedAt: 2026-06-30 18:30
status: completed
completedAt: 2026-06-30 18:22
priority: medium
tags: [company-name-change, configuration, email, settings]
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---

# Implementation Plan: Company Name Change Request — Plugin Settings Card

## Problem

The admin notification email for company name change requests is sent to a hard-coded fallback (`core.basicInformation.email` / `core.mailerSettings.mailerSender`) with no way to:
1. Customise the recipient address per-shop/scoping.
2. Disable admin notification emails entirely.

The only way to silence them today is to clear `core.basicInformation.email`, which would break other Shopware emails.

## Executive Summary

Add a new **Company Name Change Request** card to the plugin's `config.xml` with two settings:

- **`companyNameChangeNotificationEmail`** (text, optional) — custom admin recipient. Falls back to the existing logic (`core.basicInformation.email` → `core.mailerSettings.mailerSender`) when left empty.
- **`companyNameChangeNotificationEnabled`** (bool, default `true`) — master switch to enable/disable admin notification emails.

Modify `CompanyNameChangeRequestEmailService::sendAdminNotificationEmail()` to honour both settings. No new services or DI changes needed — `SystemConfigService` is already injected.

## Project Environment Details

- Project Name: Topdata Better Checkout SW6 (SW6.7 Plugin)
- Backend Root: `src`
- PHP Version: 8.2+
- Symfony Version: 7.4
- Plugin Config Prefix: `TopdataBetterCheckoutSW6.config.`

---

## Phase 1: Configuration Definition

### [MODIFY] `src/Resources/config/config.xml`

Add a new card **before** the existing "Swiss Post Address Services" card (to keep company-change settings logically grouped with company validation).

```xml
    <card>
        <title>Company Name Change Request</title>
        <title lang="de-DE">Firmenname-Änderungsantrag</title>

        <input-field type="bool">
            <name>companyNameChangeNotificationEnabled</name>
            <defaultValue>true</defaultValue>
            <label>Enable admin notification emails</label>
            <label lang="de-DE">Admin-Benachrichtigungs-E-Mails aktivieren</label>
            <helpText>When enabled, an email is sent to the configured address when a customer submits a company name change request.</helpText>
            <helpText lang="de-DE">Wenn aktiviert, wird beim Einreichen eines Firmenname-Änderungsantrags eine E-Mail an die konfigurierte Adresse gesendet.</helpText>
        </input-field>

        <input-field type="text">
            <name>companyNameChangeNotificationEmail</name>
            <defaultValue></defaultValue>
            <label>Admin notification email address</label>
            <label lang="de-DE">Admin-Benachrichtigungs-E-Mail-Adresse</label>
            <helpText>Custom email address for admin notifications. If left empty, the system falls back to core.basicInformation.email / core.mailerSettings.mailerSender.</helpText>
            <helpText lang="de-DE">Benutzerdefinierte E-Mail-Adresse für Admin-Benachrichtigungen. Wenn leer, wird auf core.basicInformation.email / core.mailerSettings.mailerSender zurückgegriffen.</helpText>
        </input-field>
    </card>
```

---

## Phase 2: Bump Plugin Version

### [MODIFY] `composer.json`

Bump `version` from `v1.2.0` to `v1.3.0` (minor feature addition — new configurable settings, no BC breaks).

```json
{
    "name":        "topdata/topdata-better-checkout-sw6",
    "description": "Topdata Better Checkout SW6",
    "version":     "v1.3.0",
    ...
}
```

---

## Phase 3: PHP Logic Changes

### [MODIFY] `src/Core/Content/CompanyNameChangeRequest/CompanyNameChangeRequestEmailService.php`

**Change `sendAdminNotificationEmail()`** to:

1. Check `TopdataBetterCheckoutSW6.config.companyNameChangeNotificationEnabled` — if `false`, return immediately.
2. Read `TopdataBetterCheckoutSW6.config.companyNameChangeNotificationEmail` — if non-empty, use it as the sole recipient (no fallback chain for the custom field since the user explicitly set it).
3. If the custom email is empty, keep the existing fallback chain (`core.basicInformation.email` → `core.mailerSettings.mailerSender`).

Only two lines change inside the method body (recipient resolution); the rest of the method (subject, template, sending) stays identical.

```php
// Before — lines 26-34:
$recipientEmail = $this->systemConfigService->getString('core.basicInformation.email');

if ($recipientEmail === '') {
    $recipientEmail = $this->systemConfigService->getString('core.mailerSettings.mailerSender');
}

if ($recipientEmail === '') {
    return;
}

// After:
$enabled = $this->systemConfigService->getBool('TopdataBetterCheckoutSW6.config.companyNameChangeNotificationEnabled');
if (!$enabled) {
    return;
}

$recipientEmail = $this->systemConfigService->getString('TopdataBetterCheckoutSW6.config.companyNameChangeNotificationEmail');

if ($recipientEmail === '') {
    $recipientEmail = $this->systemConfigService->getString('core.basicInformation.email');
    if ($recipientEmail === '') {
        $recipientEmail = $this->systemConfigService->getString('core.mailerSettings.mailerSender');
    }
}

if ($recipientEmail === '') {
    return;
}
```

**No changes needed to `__construct()` or `sendCustomerStatusEmail()`** — `SystemConfigService` is already injected and `sendCustomerStatusEmail` is unrelated.

---

## Phase 4: Update SPEC.md

### [MODIFY] `_ai/SPEC.md`

1. Bump the spec version from `1.1.0` to `1.2.0` at the top of the file.
2. Update the **Configuration Summary** table (section 4) to include the new card.
3. Add a subsection under Features describing the new configurable notification settings.

```markdown
**Version:** 1.2.0
```

Add to Section 4 (Configuration Summary):

```markdown
| Card | Fields |
|---|---|
| Account Type Settings | `guestAccountType`, `registrationAccountType` |
| Payment Restrictions (Guest) | `blockedPrivateGuestPayments`, `blockedBusinessGuestPayments` |
| Address Cloning | `cloneBillingAsShipping` |
| Company Name Validation | `companyValidationBilling`, `companyValidationShipping` |
| Company Name Change Request | `companyNameChangeNotificationEnabled`, `companyNameChangeNotificationEmail` |
```

Add a new subsection **2.12 Company Name Change Request Notifications** under Features:

```markdown
### 2.12 Company Name Change Request Notifications
- Admin notification emails for company name change requests can be enabled/disabled via `companyNameChangeNotificationEnabled`
- The recipient can be customised via `companyNameChangeNotificationEmail` (falls back to `core.basicInformation.email` / `core.mailerSettings.mailerSender` when empty)
```

---

## Phase 5: Update AGENTS.md

### [MODIFY] `AGENTS.md`

Add the two new config keys to the configuration table:

```markdown
| `companyNameChangeNotificationEnabled` | `true` | Enable/disable admin notification emails |
| `companyNameChangeNotificationEmail` | `` (empty) | Custom admin email recipient (falls back to core.basicInformation.email) |
```

---

## Phase 6: Implementation Report

### [NEW] `_ai/backlog/reports/260630_1817__IMPLEMENTATION_REPORT__company_name_change_settings.md`

Generated after implementation. See report structure below.

---

## Files Changed Summary

| File | Change Type |
|---|---|
| `composer.json` | MODIFY — bump version to v1.3.0 |
| `src/Resources/config/config.xml` | MODIFY — add new card with 2 fields |
| `src/Core/Content/CompanyNameChangeRequest/CompanyNameChangeRequestEmailService.php` | MODIFY — honour new settings in `sendAdminNotificationEmail()` |
| `_ai/SPEC.md` | MODIFY — spec version + config table + feature description |
| `AGENTS.md` | MODIFY — document new config keys |
| `_ai/backlog/reports/260630_1817__IMPLEMENTATION_REPORT__company_name_change_settings.md` | NEW — implementation report |

---

## Testing Notes

1. **Manual QA via `TEST-CHECKLIST.md`**:
   - Go to plugin config → verify new "Company Name Change Request" card appears with both fields.
   - Leave notification email empty, ensure toggle is ON → submit a change request → check that email goes to the Shopware default (`core.basicInformation.email`).
   - Set a custom email → submit a change request → verify email arrives at the custom address.
   - Toggle OFF → submit a change request → verify **no** email is sent.

2. **Config verification**:
   ```bash
   bin/console system:config:get TopdataBetterCheckoutSW6.config.companyNameChangeNotificationEnabled
   bin/console system:config:get TopdataBetterCheckoutSW6.config.companyNameChangeNotificationEmail
   ```
