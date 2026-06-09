---
filename: "_ai/backlog/reports/260610_0136__IMPLEMENTATION_REPORT__li-zip-validation.md"
title: "Report: Liechtenstein ZIP Code and Country Correlation Validation"
createdAt: 2026-06-10 01:36
updatedAt: 2026-06-10 01:36
planFile: "_ai/backlog/active/260610_0136__IMPLEMENTATION_PLAN__li-zip-validation.md"
project: "SW6.7 Plugin"
status: completed
filesCreated: 1
filesModified: 7
filesDeleted: 0
tags: [validation, liechtenstein, zip-code, swisspost]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report: Liechtenstein ZIP Correlation Validation

## 1. Summary
We have successfully implemented and integrated strict postal code checking to ensure correct pairing between Switzerland (`CH`) and Liechtenstein (`LI`). This check intercepts address entries before they can be sent to the Swiss Post validation API, preventing mismatch configurations from saving into the database.

## 2. Files Changed
- **New Files**:
  - `_ai/backlog/reports/260610_0136__IMPLEMENTATION_REPORT__li-zip-validation.md` (This report)
- **Modified Files**:
  - `src/Resources/config/services.xml` (Updated DI constructor dependencies)
  - `src/Core/Checkout/Customer/Subscriber/AddressValidationSubscriber.php` (Added the zipcode validation logic)
  - `src/Resources/snippet/de_DE/storefront.de-DE.json` (Added German localization messages)
  - `src/Resources/snippet/en_GB/storefront.en-GB.json` (Added English localization messages)
  - `src/Resources/snippet/fr_CH/storefront.fr-CH.json` (Added Swiss French localization messages)
  - `src/Resources/snippet/fr_FR/storefront.fr-FR.json` (Added French localization messages)
  - `src/Resources/snippet/pt_PT/storefront.pt-PT.json` (Added Portuguese localization messages)
  - `README.md` (Added documentation regarding this feature)

## 3. Key Changes
- Modified constructor signature of `AddressValidationSubscriber` to accept `Doctrine\DBAL\Connection` and Symfony's `TranslatorInterface`.
- Added a `Callback` constraint rule targeting the `'zipcode'` key inside the `DataValidationDefinition` parsing steps.
- Introduced strict checking of the postcode string format (filtering out non-4-digit patterns) and matched `CH` / `LI` database ISOs dynamically.
- Registered nested validation strings under the namespaced snippet scope `TopdataBetterCheckoutSW6.validation`.

## 4. Technical Decisions
- We chose to enforce the validation check at the Shopware validation definition level rather than inside the Swiss Post API service module. This ensures that a validation error is properly displayed as a form validation constraint error on the checkout or registration forms, rather than bubbling up as an unexpected API failure.

## 5. Testing Notes
1. **Liechtenstein Country + Swiss ZIP**: Navigate to `/checkout/register` or the `/account/address` book. Select `Liechtenstein` as the country and enter `3303` as the ZIP code. Submit the form. The system should block the submission and display: *"Die Postleitzahl ist für Liechtenstein nicht gültig (erwartet wird 9480-9499)."*
2. **Switzerland Country + Liechtenstein ZIP**: Select `Switzerland` as the country and enter `9490` as the ZIP code. Submit the form. The system should block the submission and display: *"Diese Postleitzahl gehört zu Liechtenstein. Bitte wählen Sie Liechtenstein als Land aus."*
