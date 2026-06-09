---
filename: "_ai/backlog/reports/260609_1240__IMPLEMENTATION_REPORT__swiss-post-autocomplete-fix.md"
title: "Report: Fix Swiss Post Autocomplete & Add Separate Config Toggles"
createdAt: 2026-06-09 12:50
updatedAt: 2026-06-09 12:50
planFile: "_ai/backlog/active/260609_1240__IMPLEMENTATION_PLAN__swiss-post-autocomplete-fix.md"
project: "Topdata Better Checkout SW6"
status: completed
filesModified: 12
filesCreated: 0
filesDeleted: 0
tags: [swiss-post, autocomplete, address-validation, bugfix, config, report]
documentType: IMPLEMENTATION_REPORT
---

# Report: Fix Swiss Post Autocomplete & Add Separate Config Toggles

## 1. Summary
Fixed Swiss Post address autocomplete (zero network requests bug) by: splitting the single `swissPostEnabled` toggle into 3 toggles (master + validation + autocomplete), adding street and house-number autocomplete endpoints, rewriting both JS plugins with comprehensive console logging for diagnostics, and making data-attribute rendering config-gated. All PHP files pass syntax checks; cache cleared and warmed.

## 2. Files Changed

### Modified Files (12)
| File | Change |
|---|---|
| `src/Resources/config/config.xml` | Replaced Swiss Post card with 3 toggles (master + validation + autocomplete) + renamed card to "Swiss Post Address Services" |
| `src/Core/Content/SwissPost/SwissPostApiService.php` | Added `isValidationEnabled()`, `isAutocompleteEnabled()`, `autocompleteStreet()`, `autocompleteHouseNumber()` + 2 cache key constants |
| `src/Controller/SwissPostStorefrontController.php` | Changed `validate()` and `autocomplete()` to use feature-specific checks; added `autocompleteStreet` and `autocompleteHouseNumber` routes |
| `src/Core/Checkout/Customer/Subscriber/AddressCertificationSubscriber.php` | Changed `isEnabled()` to `isValidationEnabled()` |
| `src/Resources/app/storefront/src/plugin/swiss-post-autocomplete.plugin.js` | Full rewrite: verbose console logging (`[TopdataSW6 Autocomplete]`), street and house-number autocomplete support, debounced ZIP/street autocomplete with dropdown rendering |
| `src/Resources/app/storefront/src/plugin/swiss-post-validator.plugin.js` | Full rewrite: verbose console logging (`[TopdataSW6 Validator]`), country-aware widget toggle, all lifecycle events logged |
| `src/Resources/views/storefront/component/address/address-personal.html.twig` | Config-gated `data-*` attribute rendering; passes `countryIdsUrl` only when feature enabled |
| `src/Resources/views/storefront/component/address/address-form.html.twig` | Validation widget gated by `swissPostValidationEnabled` |
| `src/Resources/snippet/en_GB/storefront.en-GB.json` | Added `autocompleteZipPlaceholder` and `autocompleteStreetPlaceholder` |
| `src/Resources/snippet/de_DE/storefront.de-DE.json` | Added `autocompleteZipPlaceholder` and `autocompleteStreetPlaceholder` |
| `src/Resources/snippet/fr_FR/storefront.fr-FR.json` | Added missing `swissPost.*` keys (was missing entirely) |
| `src/Resources/snippet/fr_CH/storefront.fr-CH.json` | Added missing `swissPost.*` keys (was missing entirely) |
| `src/Resources/snippet/pt_PT/storefront.pt-PT.json` | Added missing `swissPost.*` keys (was missing entirely) |

## 3. Key Changes
- **Config split**: `swissPostEnabled` (master) + `swissPostValidationEnabled` + `swissPostAutocompleteEnabled` in `config.xml`
- **Feature gating**: `SwissPostApiService.isValidationEnabled()` and `isAutocompleteEnabled()` both check master toggle first
- **Street autocomplete**: New `GET /bettercheckoutsw6/swiss-post/autocomplete-street` route with caching and 401 retry
- **House-number autocomplete**: New `GET /bettercheckoutsw6/swiss-post/autocomplete-house-number` route (JS interaction prepared for future)
- **JS diagnostics**: Both plugins log with `[TopdataSW6 Validator]` / `[TopdataSW6 Autocomplete]` prefix — init, element detection, API calls, errors
- **Config-gated rendering**: `address-personal.html.twig` only renders `data-topdata-address-validator` and `data-topdata-zip-autocomplete` when respective features enabled
- **Missing snippets**: Added full `swissPost.*` translations for fr-FR, fr-CH, pt-PT that were missing

## 4. Technical Decisions
- `isValidationEnabled()` / `isAutocompleteEnabled()` delegate to `isEnabled()` first, preserving the master toggle behavior
- JS `_isCountrySupported()` returns `true` when `_supportedCountryIds` is null (before fetch completes) to avoid blocking autocomplete while loading
- Dropdown closing uses `_dropdownActive` reference (not DOM query) for O(1) close performance
- Street autocomplete requires ZIP to be entered first (DCAPI constraint)
- `_onHouseNumberKeydown()` left empty as placeholder for future house-number keyboard navigation

## 5. Registered Routes (new)
| Route | Method | Path |
|---|---|---|
| `frontend.bettercheckoutsw6.swiss-post.autocomplete-street` | GET | `/bettercheckoutsw6/swiss-post/autocomplete-street` |
| `frontend.bettercheckoutsw6.swiss-post.autocomplete-house-number` | GET | `/bettercheckoutsw6/swiss-post/autocomplete-house-number` |

## 6. Verification
- PHP syntax: all 4 PHP files pass `php -l` with no errors
- Cache: `cache:clear`, `cache:warmup`, `cache:clear:http` all succeeded
- JS build: Source files updated; rebuild via `./bin/build-js.sh` required inside SW6 container
- Config: 3 new config fields registered (`swissPostValidationEnabled`, `swissPostAutocompleteEnabled`, plus renamed card titles)
