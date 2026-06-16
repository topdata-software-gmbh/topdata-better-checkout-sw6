---
filename: "_ai/backlog/reports/260615_1954__IMPLEMENTATION_REPORT__address-form-restructuring.md"
title: "Report: Swiss Post address form restructuring — new-address layout, house number autocomplete, validation widget placement"
createdAt: 2026-06-15 19:54
updatedAt: 2026-06-15 19:54
project: "topdata-better-checkout-sw6"
status: completed
filesCreated: 1
filesModified: 7
filesDeleted: 0
tags: [twig, javascript, autocomplete, swiss-post, address-form]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report: Address form restructuring

## Summary

Restructured the address form template to show a different field layout for **new addresses** (registration, new address in address manager) vs **editing** existing addresses. For new addresses, PLZ+City now appear on line 1 and Street+House number on line 2, with Swiss Post validation widget positioned directly below the address fields (above the phone number). Added house number autocomplete via the existing `/autocomplete-house-number` endpoint.

## Prompt used

> We have this swiss plugin address autocomplete. We need some very specific changes:
> - bei neuregistrierung und bei eingabe einer neuen Adresse im Adressmanager:
>     - Zeile 1: zuerst PLZ + Ort
>     - Zeile 2: Strasse + Hausnummer (Autocomplete auch bei Hausnummer, siehe apidocs)
> - when EDITING an existing address, it should stay like it is now.
> - direkt unter den Eingabefeldern fuer die Adresse (also ueber und nicht unter der telefonnummer): das SwissPost validierungs-widget

## Files Changed

### Created
- `src/Resources/views/storefront/component/address/field/address-house-number-field.html.twig` — New Twig template for a visual-only house number input (no `name` attribute, uses `data-topdata-house-number`). The value is combined into the `street` field on form submit via JavaScript, so Shopware's address entity stores the full "Strasse 12" in the `street` column without using `additionalAddressLine1`.

### Modified
- `src/Resources/views/storefront/component/address/address-form.html.twig` — Main restructuring. Added `isNewAddress` detection (`data is null or data.get('id') is null`). For new addresses: renders ZIP+City row, Street+HouseNumber row, validation widget, then phone number. For editing: calls `{{ parent() }}` (standard layout) and appends validation widget after.
- `src/Resources/app/storefront/src/plugin/swiss-post-autocomplete.plugin.js` — Added house number autocomplete support: `_onHouseNumberAutocomplete`, `_renderHouseNumberDropdown`, `_selectHouseNumberItem` methods; `_registerFormSubmitHandler` to combine street + house number before form submission.
- `src/Resources/app/storefront/src/plugin/swiss-post-validator.plugin.js` — Added `houseNumberInputSelector`, reads house number and appends to street in `_getAddressPayload()` for accurate Swiss Post validation.
- `src/Resources/snippet/de_DE/storefront.de-DE.json` — Added `houseNumberLabel: "Hausnummer"`
- `src/Resources/snippet/en_GB/storefront.en-GB.json` — Added `houseNumberLabel: "House number"`
- `src/Resources/snippet/fr_FR/storefront.fr-FR.json` — Added `houseNumberLabel: "Numéro de maison"`
- `src/Resources/snippet/fr_CH/storefront.fr-CH.json` — Added `houseNumberLabel: "Numéro de maison"`
- `src/Resources/snippet/pt_PT/storefront.pt-PT.json` — Added `houseNumberLabel: "Número de porta"`

## Key Changes

1. **Branching layout** via `isNewAddress` flag in `address-form.html.twig` — new addresses get a completely custom field order, editing uses `{{ parent() }}` unchanged.
2. **House number as visual-only field** — the input has no `name` attribute, so it never reaches the server as a separate field. On form submit, JS combines `street` + house number into the `street` field.
3. **House number autocomplete** — uses the existing `autocompleteHouseNumber` API endpoint (requires street + zip). Selecting a house number from the dropdown pre-fills all address fields (street, ZIP, city) from the Swiss Post response.
4. **Validation widget repositioned** — for new addresses, the widget renders between the address fields (street/house number) and the phone number field, not after the phone number.
5. **Validator plugin updated** — includes house number in the validation payload so the Swiss Post API receives the complete "Hauptstrasse 12" string.

## Deviations from Plan

None. The implementation followed the requirements exactly:
- Only new addresses get the new layout (registration, new address in address manager)
- Editing uses `{{ parent() }}` — no layout change for editing
- Widget placed above phone for new addresses

## Technical Decisions

1. **House number not stored separately**: The house number input has no `name` attribute, so it is not submitted as a form field. On form submit, JS combines it into the `street` field. This avoids changing Shopware's address entity schema and keeps `additionalAddressLine1` free for its original purpose.

2. **`_selectHouseNumberItem` populates `street` with full address**: When a house number is selected from the autocomplete dropdown, the street field gets `item.street + ' ' + item.houseNumber` (e.g., "Hauptstrasse 12"). The form submit handler checks `!streetVal.endsWith(houseNumVal)` to avoid double-appending.

3. **Null-safe checks**: Plugin initializers guard against missing house number input (edit case), form submit handler returns early if `houseNumberInput` is null.

## Testing Notes

Run through `TEST-CHECKLIST.md` scenarios manually after rebuilding the storefront JS:

1. **Register new account** — verify PLZ+City appears first, then Street+HouseNumber, then validation widget, then phone.
2. **Add new address in address manager** — same layout as registration.
3. **Edit existing address** — standard layout (street first, then PLZ+City), no house number field.
4. **House number autocomplete** — fill PLZ+C city, select a street, type a number in Hausnummer field, verify suggestions appear.
5. **Form submission** — after submitting a new address, verify the saved address has the full "Strasse 12" in the street field.
6. **Validation widget** — verify widget appears above phone number for new addresses, and works correctly with the combined street value.

Rebuild storefront JS: `./bin/build-storefront.sh`

## Next Steps

None identified.
