---
filename: "_ai/backlog/active/260610_0200__IMPLEMENTATION_PLAN__address_validation_localization_refactoring.md"
title: "Refactor Address Validation Error and Status Localizations"
createdAt: 2026-06-10 02:00
updatedAt: 2026-06-10 02:00
status: draft
priority: high
tags: [validation, swiss-post, localization, storefront]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description

Currently, the address validation feedback displayed to customers during checkout has a mixed language experience:
* Some error messages are returned directly as localized values (e.g., Liechtenstein ZIP constraints).
* Some error messages are hardcoded in English inside the backend services (e.g., `"Swiss Post could not validate this address (quality: UNUSABLE)"`).
* Connection or HTTP status failures from the API are output directly in English as raw strings (e.g., `"API returned status 500"`).

This mixture bypasses client-side or server-side localization and exposes raw technical feedback to end-users without translations.

## 2. Executive Summary

This plan provides a unified approach to handle address validation response formats and localization:
1. **Standardize Api Service Output:** Refactor `SwissPostApiService::validateAddress` to return structured array indices containing an `errorKey` (referencing standard Shopware translation snippet paths) and a non-localized `details` string, while keeping the standard English description as a fallback in `error`.
2. **Standardize and Localize Controller Responses:** Update `SwissPostStorefrontController::validate` to intercept the `errorKey` returned by the API service, perform server-side translation using the core Translator, and output a unified JSON model containing `success`, `error` (fully translated), `errorKey`, and optional `details`.
3. **Refactor Storefront JS Plugin:** Update `swiss-post-validator.plugin.js` to consume the standard model, rendering the translated `error` with the `details` appended in brackets if they exist (e.g., `(quality: UNUSABLE)`), keeping raw technical information distinct yet accessible.

---

## 3. Project Environment

* **Project Name:** SW6.7 Plugin (`TopdataBetterCheckoutSW6`)
* **Backend root:** `src`
* **PHP Version:** `8.2 / 8.3 / 8.4`

---

## 4. Multi-Phased Implementation Plan

### Phase 1: Refactor backend API service `SwissPostApiService`

We will modify `validateAddress` in `src/Core/Content/SwissPost/SwissPostApiService.php` to return structured errors containing an `errorKey`, `details`, and fallback English string.

`[MODIFY] src/Core/Content/SwissPost/SwissPostApiService.php`
```php
            if ($statusCode === 200) {
                $result = json_decode($contents, true);
                $quality = $result['quality'] ?? 'UNKNOWN';

                $isUnusable = $quality === 'UNUSABLE';
                $errorMsg = $isUnusable ? ('Swiss Post could not validate this address (quality: ' . $quality . ')') : null;

                $this->logToJsonl([
                    'action' => 'validate',
                    'direction' => 'response',
                    'status' => $statusCode,
                    'quality' => $quality,
                    'originalResponse' => $result,
                ]);

                return [
                    'success' => !$isUnusable,
                    'quality' => $quality,
                    'originalResponse' => $result,
                    'errorKey' => $isUnusable ? 'better-checkout.swissPostValidationFailed' : null,
                    'details' => $isUnusable ? 'quality: UNUSABLE' : null,
                    'error' => $errorMsg,
                ];
            }

            $this->logToJsonl([
                'action' => 'validate',
                'direction' => 'response',
                'status' => $statusCode,
                'error' => 'API returned status ' . $statusCode,
                'body' => json_decode($contents, true),
                'address' => $address,
            ]);

            return [
                'success' => false,
                'quality' => null,
                'originalResponse' => null,
                'errorKey' => 'better-checkout.swissPostValidationFailed',
                'details' => 'API returned status ' . $statusCode,
                'error' => 'API returned status ' . $statusCode,
            ];
        } catch (\Throwable $e) {
            $this->logToJsonl([
                'action' => 'validate',
                'direction' => 'response',
                'error' => $e->getMessage(),
                'address' => $address,
            ]);

            return [
                'success' => false,
                'quality' => null,
                'originalResponse' => null,
                'errorKey' => 'better-checkout.swissPostValidationFailed',
                'details' => $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
```

---

### Phase 2: Refactor Swiss Post Storefront Controller

We will standardize `SwissPostStorefrontController::validate` to translate returned validation results server-side using the `TranslatorInterface` based on the context's locale and structure all responses into the unified JSON schema.

`[MODIFY] src/Controller/SwissPostStorefrontController.php`
```php
    #[Route(
        path: '/bettercheckoutsw6/swiss-post/validate',
        name: 'frontend.bettercheckoutsw6.swiss-post.validate',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST']
    )]
    public function validate(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->apiService->isValidationEnabled($context->getSalesChannelId())) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Swiss Post Validation is disabled.',
                'errorKey' => null,
                'details' => 'Swiss Post Validation is disabled'
            ], 403);
        }

        $addressData = $request->request->all('address');

        $countryId = $addressData['countryId'] ?? null;
        if (empty($countryId)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Country ID is required for address validation.',
                'errorKey' => null,
                'details' => 'Country ID is required for address validation'
            ], 400);
        }

        $iso = $this->connection->fetchOne(
            'SELECT iso FROM country WHERE id = UNHEX(?)',
            [$countryId]
        );
        if (!$iso) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid country ID: could not resolve country ISO.',
                'errorKey' => null,
                'details' => 'Invalid country ID: could not resolve country ISO'
            ], 400);
        }

        if (!in_array($iso, ['CH', 'LI'], true)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Address validation is only available for Switzerland and Liechtenstein.',
                'errorKey' => null,
                'details' => 'Address validation is only available for Switzerland and Liechtenstein'
            ], 400);
        }

        $zipcode = $addressData['zipcode'] ?? '';
        if ($zipcode !== '' && preg_match('/^\d{4}$/', $zipcode)) {
            $zipInt = (int)$zipcode;
            $isLiZip = ($zipInt >= 9480 && $zipInt <= 9499);

            if ($iso === 'LI' && !$isLiZip) {
                $errorKey = 'TopdataBetterCheckoutSW6.validation.invalidLiechtensteinZip';
                return new JsonResponse([
                    'success' => false,
                    'errorKey' => $errorKey,
                    'error' => $this->translator->trans($errorKey),
                    'details' => null
                ], 400);
            }

            if ($iso === 'CH' && $isLiZip) {
                $errorKey = 'TopdataBetterCheckoutSW6.validation.swissZipForLiechtenstein';
                return new JsonResponse([
                    'success' => false,
                    'errorKey' => $errorKey,
                    'error' => $this->translator->trans($errorKey),
                    'details' => null
                ], 400);
            }
        }

        $addressData['countryCode'] = $iso;

        $result = $this->apiService->validateAddress($addressData, $context->getSalesChannelId());

        if (isset($result['errorKey']) && !empty($result['errorKey'])) {
            $result['error'] = $this->translator->trans($result['errorKey']);
        }

        return new JsonResponse($result);
    }
```

---

### Phase 3: Refactor Storefront JS Plugin

We will update `swiss-post-validator.plugin.js` to process the standardized response format. If `details` are present in an error response, we will cleanly append them to the translated message.

`[MODIFY] src/Resources/app/storefront/src/plugin/swiss-post-validator.plugin.js`
```javascript
    _onValidate() {
        const address = this._getAddressPayload();

        if (!address.firstName || !address.lastName || !address.street || !address.zipcode || !address.city) {
            console.debug(LOG_PREFIX, 'Validation skipped — not all fields filled');
            this._updateWidgetState('default');
            return;
        }

        console.log(LOG_PREFIX, 'Sending validation request for address:', {
            street: address.street,
            zipcode: address.zipcode,
            city: address.city,
            countryCode: address.countryCode,
        });

        this._client.post(this.options.validateUrl, JSON.stringify({ address }), (response) => {
            try {
                const data = JSON.parse(response);
                console.log(LOG_PREFIX, 'Validation response:', data);
                if (data.success) {
                    if (['CERTIFIED', 'DOMICILE_CERTIFIED'].includes(data.quality)) {
                        this._updateWidgetState('certified');
                    } else if (data.quality === 'USABLE') {
                        this._updateWidgetState('usable');
                    } else {
                        this._updateWidgetState('not-certified');
                    }
                } else {
                    let errMsg = data.error || this.widget?.dataset?.errorMessage || 'Address validation failed';
                    if (data.details) {
                        errMsg += ` (${data.details})`;
                    }
                    this._updateWidgetState('error', errMsg);
                }
            } catch (e) {
                console.error(LOG_PREFIX, 'Validation response parse error:', e);
                this._updateWidgetState('error', 'Malformed response');
            }
        });
    }
```

---

### Phase 4: Build Storefront Assets and Test Plan

#### Asset Compiling
Run the following script to compile the Storefront Vite bundle containing your JavaScript adjustments:
```bash
composer build:js:storefront
```

#### Test Scenarios to Verify Changes:
1. **Invalid Address Check (German Locale):**
   - Fill out validation form with an invalid street address.
   - Verify that the error message shows: *"Die Adresse konnte von der Schweizer Post nicht validiert werden. Bitte überprüfen Sie Ihre Adressangaben. (quality: UNUSABLE)"*
2. **Invalid Address Check (English Locale):**
   - Switch active language to English.
   - Verify that the error message shows: *"The address could not be validated by Swiss Post. Please check your address details. (quality: UNUSABLE)"*
3. **API Status Connection Error Verification:**
   - Simulate an API error (e.g. invalid client secret in config settings).
   - Verify the storefront displays the translated error message appended with the exact status details: *"Die Adresse konnte von der Schweizer Post nicht validiert werden. Bitte überprüfen Sie Ihre Adressangaben. (API returned status 401)"*

---

### Phase 5: Implementation Report

Create the final report file in the repository path:

`[NEW FILE] _ai/backlog/reports/260610_0200__IMPLEMENTATION_REPORT__address_validation_localization_refactoring.md`
```yaml
---
filename: "_ai/backlog/reports/260610_0200__IMPLEMENTATION_REPORT__address_validation_localization_refactoring.md"
title: "Report: Refactor Address Validation Error and Status Localizations"
createdAt: 2026-06-10 02:00
updatedAt: 2026-06-10 02:00
planFile: "_ai/backlog/active/260610_0200__IMPLEMENTATION_PLAN__address_validation_localization_refactoring.md"
project: "TopdataBetterCheckoutSW6"
status: completed
filesCreated: 0
filesModified: 3
filesDeleted: 0
tags: [validation, swiss-post, localization, storefront]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report: Address Validation Localization Refactoring

## 1. Summary
Refactored the validation error output structure between the Swiss Post API client service, storefront controller, and Javascript validator plugin. The implementation unifies all validation errors into a single schema, performing server-side translation through Symfony’s translation layer, and allowing the frontend to optionally append raw technical details.

## 2. Files Changed
* **Modified:**
  - `src/Core/Content/SwissPost/SwissPostApiService.php` — Modified return parameters of `validateAddress` to supply standard error keys and detailed metrics instead of hardcoded English strings.
  - `src/Controller/SwissPostStorefrontController.php` — Enforced server-side translation on incoming validation results and standardized input parameter failures.
  - `src/Resources/app/storefront/src/plugin/swiss-post-validator.plugin.js` — Processed the new schema and concatenated validation details on validation errors.

## 3. Key Changes
* Created a strict validation payload structure: `success`, `error`, `errorKey`, `details`.
* Transferred localization ownership from raw backend strings to Shopware storefront translation snippets.
* Integrated optional appending of details (such as `(quality: UNUSABLE)`) to the translated storefront alert message.

## 4. Technical Decisions
* Maintained a fallback English description (`error` index) directly in `SwissPostApiService::validateAddress` to prevent breaking existing CLI commands (e.g., `DiffFixedAddressesCommand`) which operate outside of translation context parameters.
```
