---
filename: "_ai/backlog/reports/260630_1817__IMPLEMENTATION_REPORT__company_name_change_settings.md"
title: "Implementation Report: Company Name Change Request — Plugin Settings Card"
createdAt: 2026-06-30 18:17
updatedAt: 2026-06-30 18:45
status: completed
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report: Company Name Change Request — Plugin Settings Card

## Summary

Implemented the "Company Name Change Request" settings card with two new configuration fields (`companyNameChangeNotificationEnabled`, `companyNameChangeNotificationEmail`), updated the email service to honour them, bumped plugin version to v1.3.0, and updated documentation.

## Changes Made

| File | Change |
|---|---|
| `src/Resources/config/config.xml` | Added new card with `companyNameChangeNotificationEnabled` (bool) and `companyNameChangeNotificationEmail` (text) fields before the Swiss Post card |
| `composer.json` | Bumped version from `v1.2.0` to `v1.3.0` |
| `src/Core/Content/CompanyNameChangeRequest/CompanyNameChangeRequestEmailService.php` | `sendAdminNotificationEmail()` now checks the enabled flag and custom email config before falling back to core settings |
| `_ai/SPEC.md` | Spec version bumped to 1.2.0; config table updated; new feature subsection 2.10 added |
| `AGENTS.md` | Documented both new config keys in the configuration table |

## Verification

```bash
bin/console system:config:get TopdataBetterCheckoutSW6.config.companyNameChangeNotificationEnabled
bin/console system:config:get TopdataBetterCheckoutSW6.config.companyNameChangeNotificationEmail
```
