---
filename: "_ai/backlog/active/260609_1240__IMPLEMENTATION_PLAN__swiss-post-autocomplete-fix.md"
title: "Fix Swiss Post Autocomplete & Add Separate Config Toggles"
createdAt: 2026-06-09 12:40
createdBy: opencode [glm-5.1]
updatedAt: 2026-06-09 12:40
updatedBy: opencode [glm-5.1]
status: draft
priority: high
tags: [swiss-post, autocomplete, address-validation, bugfix, config]
project: topdata-better-checkout-sw6
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

# Problem Statement

The Swiss Post address autocomplete feature is **non-functional**: typing in ZIP/city/street fields sends **zero network requests** to the server. The user confirmed that the `data-topdata-zip-autocomplete` attribute is present in the DOM, but the `/bettercheckoutsw6/swiss-post/country-ids` URL is never fetched, suggesting the JS plugins either fail silently or don't initialize properly.

### Root Causes Identified

1. **No console logging** — Both JS plugins (`swiss-post-validator.plugin.js`, `swiss-post-autocomplete.plugin.js`) have **zero** `console.log`/`console.debug`/`console.warn` calls. When something breaks, there is no diagnostic output at all.

2. **Silent early returns** — `_registerEvents()` returns silently if elements are not found (`if (!this.zipInput) return;`). If a CSS selector doesn't match the SW6.7 DOM, the plugin appears to "initialize" but does nothing, with no indication of why.

3. **No separate config toggle** — `swissPostEnabled` gates both validation AND autocomplete. Users cannot enable one without the other. The config card is titled "Swiss Post Adressprüfung" (Address Validation) — autocomplete isn't mentioned.

4. **Data attributes always rendered** — The `address-personal.html.twig` always renders `data-topdata-address-validator` and `data-topdata-zip-autocomplete` regardless of whether Swiss Post features are enabled. JS plugins always initialize even when all Swiss Post features are disabled.

5. **No street autocomplete** — The DCAPI supports `/streets` and `/house-numbers` autocomplete endpoints, but only ZIP/city autocomplete is implemented. When users type a street name, no suggestions appear.

### Expected Behavior After Fix

- Plugin settings card renamed to "Swiss Post Address Services" with two independent toggles: "Address Validation" and "Address Autocomplete"
- When autocomplete is enabled and a CH/LI country is selected, typing in the ZIP field should show a city dropdown; typing in the street field should show a street dropdown; selecting a street should show house numbers
- Each JS plugin logs its initialization, element detection, API calls, and errors to the browser console with a `[TopdataSW6]` prefix
- When a feature is disabled, its corresponding `data-*` attribute and JS plugin are not rendered/registered

---

# Implementation Notes

## Project Environment

- **Project Name**: topdata-better-checkout-sw6
- **Backend root**: `src`
- **PHP Version**: 8.2+
- **Shopware Version**: 6.7
- **No build pipeline** — Pure PHP + Twig + vanilla JS (no npm/webpack, `./bin/build-js.sh` compiles storefront JS via Vite)
- **No automated tests** — Verify manually per `TEST-CHECKLIST.md`

## Key File Locations

| Role | Path |
|---|---|
| Plugin config | `src/Resources/config/config.xml` |
| DI services | `src/Resources/config/services.xml` |
| Swiss Post API service | `src/Core/Content/SwissPost/SwissPostApiService.php` |
| Storefront controller | `src/Controller/SwissPostStorefrontController.php` |
| Admin controller | `src/Controller/AdminApi/SwissPostAdminController.php` |
| JS: Validator plugin | `src/Resources/app/storefront/src/plugin/swiss-post-validator.plugin.js` |
| JS: Autocomplete plugin | `src/Resources/app/storefront/src/plugin/swiss-post-autocomplete.plugin.js` |
| JS: Entry point | `src/Resources/app/storefront/src/main.js` |
| Twig: Address personal | `src/Resources/views/storefront/component/address/address-personal.html.twig` |
| Twig: Address form | `src/Resources/views/storefront/component/address/address-form.html.twig` |
| Twig: Validation widget | `src/Resources/views/storefront/component/address/swiss-post-widget.html.twig` |
| Snippets (5 languages) | `src/Resources/snippet/{en_GB,de_DE,fr_FR,fr_CH,pt_PT}/storefront.*.json` |
| Compiled JS | `src/Resources/app/storefront/dist/storefront/js/topdata-better-checkout-s-w6/topdata-better-checkout-s-w6.js` |

## Commands

```bash
# Build storefront JS (inside SW6 container)
./bin/build-js.sh

# Clear all caches
php bin/console cache:clear

# Clear HTTP cache
php bin/console cache:clear:http

# Warmup cache
php bin/console cache:warmup
```

## SW6.7 DOM Reference (Verified)

| Field | name attribute | class/selector to use |
|---|---|---|
| Country select | `{prefix}[countryId]` | `.country-select` (class on `<select>`) |
| ZIP input | `{prefix}[zipcode]` | `input[name$="[zipcode]"]` |
| City input | `{prefix}[city]` | `input[name$="[city]"]` |
| Street input | `{prefix}[street]` | `input[name$="[street]"]` |
| First name | `{prefix}[firstName]` | `input[name$="[firstName]"]` |
| Last name | `{prefix}[lastName]` | `input[name$="[lastName]"]` |

The `prefix` is `billingAddress` or `shippingAddress` depending on context.

---

# Phase 1: Add Separate Config Toggles

**Objective**: Split the single `swissPostEnabled` boolean into two independent toggles (`swissPostValidationEnabled` and `swissPostAutocompleteEnabled`) while keeping `swissPostEnabled` as the master toggle. Rename the config card.

## Tasks

### 1.1 Update `config.xml` — Split the Swiss Post config card

[MODIFY] `src/Resources/config/config.xml`

Replace the existing Swiss Post card (lines 171-197) with:

```xml
    <card>
        <title>Swiss Post Address Services</title>
        <title lang="de-DE">Swiss Post Adressdienste</title>
        <title lang="fr-FR">Services d'adresse Swiss Post</title>
        <title lang="fr-CH">Services d'adresse Swiss Post</title>

        <input-field type="bool">
            <name>swissPostEnabled</name>
            <label>Enable Swiss Post Integration</label>
            <label lang="de-DE">Swiss Post Integration aktivieren</label>
            <label lang="fr-FR">Activer l'intégration Swiss Post</label>
            <label lang="fr-CH">Activer l'intégration Swiss Post</label>
            <defaultValue>false</defaultValue>
        </input-field>

        <input-field type="bool">
            <name>swissPostValidationEnabled</name>
            <label>Enable Address Validation</label>
            <label lang="de-DE">Adressprüfung aktivieren</label>
            <label lang="fr-FR">Activer la validation d'adresse</label>
            <label lang="fr-CH">Activer la validation d'adresse</label>
            <helpText>Validates CH/LI addresses against the Swiss Post certified address database and displays a certification status widget.</helpText>
            <helpText lang="de-DE">Validiert CH/LI-Adressen gegen die zertifizierte Adressdatenbank der Swiss Post und zeigt einen Zertifizierungsstatus an.</helpText>
            <defaultValue>true</defaultValue>
        </input-field>

        <input-field type="bool">
            <name>swissPostAutocompleteEnabled</name>
            <label>Enable Address Autocomplete</label>
            <label lang="de-DE">Adress-Autovervollständigung aktivieren</label>
            <label lang="fr-FR">Activer la saisie semi-automatique d'adresse</label>
            <label lang="fr-CH">Activer la saisie semi-automatique d'adresse</label>
            <helpText>Provides ZIP, city, street and house-number autocomplete for CH/LI addresses.</helpText>
            <helpText lang="de-DE">Bietet PLZ-, Ort-, Strassen- und Hausnummer-Autovervollständigung für CH/LI-Adressen.</helpText>
            <defaultValue>true</defaultValue>
        </input-field>

        <input-field type="text">
            <name>swissPostClientId</name>
            <label>Client ID</label>
            <label lang="de-DE">Client-ID</label>
            <helpText>Your Swiss Post DCAPI client ID</helpText>
            <helpText lang="de-DE">Ihre Swiss Post DCAPI Client-ID</helpText>
        </input-field>

        <input-field type="password">
            <name>swissPostClientSecret</name>
            <label>Client Secret</label>
            <label lang="de-DE">Client-Secret</label>
            <helpText>Your Swiss Post DCAPI client secret</helpText>
            <helpText lang="de-DE">Ihre Swiss Post DCAPI Client-Secret</helpText>
        </input-field>
    </card>
```

### 1.2 Update `SwissPostApiService.php` — Add feature-specific enabled checks

[MODIFY] `src/Core/Content/SwissPost/SwissPostApiService.php`

Add two new methods after `isEnabled()`:

```php
public function isValidationEnabled(?string $salesChannelId = null): bool
{
    if (!$this->isEnabled($salesChannelId)) {
        return false;
    }

    return $this->systemConfigService->getBool(
        'TopdataBetterCheckoutSW6.config.swissPostValidationEnabled',
        $salesChannelId
    );
}

public function isAutocompleteEnabled(?string $salesChannelId = null): bool
{
    if (!$this->isEnabled($salesChannelId)) {
        return false;
    }

    return $this->systemConfigService->getBool(
        'TopdataBetterCheckoutSW6.config.swissPostAutocompleteEnabled',
        $salesChannelId
    );
}
```

### 1.3 Update `SwissPostStorefrontController.php` — Use feature-specific checks

[MODIFY] `src/Controller/SwissPostStorefrontController.php`

In `validate()`: change `isEnabled()` to `isValidationEnabled()`:
```php
if (!$this->apiService->isValidationEnabled($context->getSalesChannelId())) {
```

In `autocomplete()`: change `isEnabled()` to `isAutocompleteEnabled()`:
```php
if (!$this->apiService->isAutocompleteEnabled($context->getSalesChannelId())) {
```

### 1.4 Update `AddressCertificationSubscriber.php` — Use validation-specific check

[MODIFY] `src/Core/Checkout/Customer/Subscriber/AddressCertificationSubscriber.php`

Line 34: change `isEnabled()` to `isValidationEnabled()`:
```php
if (!$this->apiService->isValidationEnabled($salesChannelId)) {
```

## Deliverables

- config.xml with 3 separate toggles (master + validation + autocomplete) plus credentials
- SwissPostApiService with `isValidationEnabled()` and `isAutocompleteEnabled()` methods
- Controller and subscriber using feature-specific checks

---

# Phase 2: Backend — Add Street & House-Number Autocomplete Endpoints

**Objective**: Add two new DCAPI endpoints to the Storefront controller for street autocomplete and house-number autocomplete, plus backend API methods.

## Tasks

### 2.1 Add `autocompleteStreet()` and `autocompleteHouseNumber()` to `SwissPostApiService`

[MODIFY] `src/Core/Content/SwissPost/SwissPostApiService.php`

Add two new public methods after `autocompleteZip()`:

```php
public function autocompleteStreet(string $query, string $zip, ?string $salesChannelId = null): array
{
    $token = $this->getAccessToken($salesChannelId);
    if (!$token) {
        return [];
    }

    $cacheKey = self::CACHE_KEY_PREFIX_STREET . md5($query . $zip);
    $cacheItem = $this->cache->getItem($cacheKey);

    if ($cacheItem->isHit()) {
        return $cacheItem->get();
    }

    try {
        $url = self::BASE_API_URL . '/streets?street=' . urlencode($query) . '&zip=' . urlencode($zip) . '&type=DOMICILE';
        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Accept', 'application/json');

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() === 401) {
            $this->invalidateTokenCache($salesChannelId);
            $token = $this->getAccessToken($salesChannelId);
            if ($token) {
                $request = $request->withHeader('Authorization', 'Bearer ' . $token);
                $response = $this->httpClient->sendRequest($request);
            } else {
                return [];
            }
        }

        if ($response->getStatusCode() === 200) {
            $data = json_decode($response->getBody()->getContents(), true) ?? [];

            $results = array_map(static fn ($item) => [
                'street' => $item['street'] ?? '',
                'zip' => $item['zip'] ?? '',
                'city' => $item['city18'] ?? $item['city27'] ?? '',
            ], $data);

            $cacheItem->set($results);
            $cacheItem->expiresAfter(86400);
            $this->cache->save($cacheItem);

            return $results;
        }
    } catch (\Throwable $e) {
        $this->logger->error('Swiss Post Street Autocomplete Exception', ['exception' => $e->getMessage()]);
    }

    return [];
}

public function autocompleteHouseNumber(string $query, string $street, string $zip, ?string $salesChannelId = null): array
{
    $token = $this->getAccessToken($salesChannelId);
    if (!$token) {
        return [];
    }

    $cacheKey = self::CACHE_KEY_PREFIX_HOUSENR . md5($query . $street . $zip);
    $cacheItem = $this->cache->getItem($cacheKey);

    if ($cacheItem->isHit()) {
        return $cacheItem->get();
    }

    try {
        $url = self::BASE_API_URL . '/house-numbers?houseNumber=' . urlencode($query) . '&street=' . urlencode($street) . '&zip=' . urlencode($zip) . '&type=DOMICILE';
        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Accept', 'application/json');

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() === 401) {
            $this->invalidateTokenCache($salesChannelId);
            $token = $this->getAccessToken($salesChannelId);
            if ($token) {
                $request = $request->withHeader('Authorization', 'Bearer ' . $token);
                $response = $this->httpClient->sendRequest($request);
            } else {
                return [];
            }
        }

        if ($response->getStatusCode() === 200) {
            $data = json_decode($response->getBody()->getContents(), true) ?? [];

            $results = array_map(static fn ($item) => [
                'houseNumber' => $item['houseNumber'] ?? '',
                'street' => $item['street'] ?? '',
                'zip' => $item['zip'] ?? '',
                'city' => $item['city18'] ?? $item['city27'] ?? '',
            ], $data);

            $cacheItem->set($results);
            $cacheItem->expiresAfter(86400);
            $this->cache->save($cacheItem);

            return $results;
        }
    } catch (\Throwable $e) {
        $this->logger->error('Swiss Post House Number Autocomplete Exception', ['exception' => $e->getMessage()]);
    }

    return [];
}
```

Also add the new cache key constants at the top of the class:

```php
private const CACHE_KEY_PREFIX_STREET = 'topdata_swiss_post_street_';
private const CACHE_KEY_PREFIX_HOUSENR = 'topdata_swiss_post_housenr_';
```

### 2.2 Add two new routes to `SwissPostStorefrontController`

[MODIFY] `src/Controller/SwissPostStorefrontController.php`

Add two new routes after `getCountryIds()`:

```php
#[Route(
    path: '/bettercheckoutsw6/swiss-post/autocomplete-street',
    name: 'frontend.bettercheckoutsw6.swiss-post.autocomplete-street',
    options: ['seo' => false],
    methods: ['GET']
)]
public function autocompleteStreet(Request $request, SalesChannelContext $context): JsonResponse
{
    if (!$this->apiService->isAutocompleteEnabled($context->getSalesChannelId())) {
        return new JsonResponse([], 403);
    }

    $query = $request->query->getString('query');
    $zip = $request->query->getString('zip');
    if (mb_strlen($query) < 2 || empty($zip)) {
        return new JsonResponse([]);
    }

    $results = $this->apiService->autocompleteStreet($query, $zip, $context->getSalesChannelId());

    return new JsonResponse($results);
}

#[Route(
    path: '/bettercheckoutsw6/swiss-post/autocomplete-house-number',
    name: 'frontend.bettercheckoutsw6.swiss-post.autocomplete-house-number',
    options: ['seo' => false],
    methods: ['GET']
)]
public function autocompleteHouseNumber(Request $request, SalesChannelContext $context): JsonResponse
{
    if (!$this->apiService->isAutocompleteEnabled($context->getSalesChannelId())) {
        return new JsonResponse([], 403);
    }

    $query = $request->query->getString('query');
    $street = $request->query->getString('street');
    $zip = $request->query->getString('zip');
    if (mb_strlen($query) < 1 || empty($street) || empty($zip)) {
        return new JsonResponse([]);
    }

    $results = $this->apiService->autocompleteHouseNumber($query, $street, $zip, $context->getSalesChannelId());

    return new JsonResponse($results);
}
```

## Deliverables

- Two new API methods on `SwissPostApiService` with caching and 401 retry
- Two new storefront routes: `autocomplete-street` and `autocomplete-house-number`
- Both gated by `isAutocompleteEnabled()`

---

# Phase 3: JS Plugin Overhaul — Verbose Logging, Config Awareness, Street Autocomplete

**Objective**: Rewrite both JS plugins with comprehensive `console.log` debugging, make them config-aware, add street and house-number autocomplete, and fix any selector issues.

## Tasks

### 3.1 Rewrite `swiss-post-autocomplete.plugin.js`

[MODIFY] `src/Resources/app/storefront/src/plugin/swiss-post-autocomplete.plugin.js`

The complete rewritten file:

```javascript
import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import Debouncer from 'src/helper/debouncer.helper';

const LOG_PREFIX = '[TopdataSW6 Autocomplete]';

export default class TopdataZipAutocomplete extends Plugin {
    static options = {
        autocompleteUrl: '/bettercheckoutsw6/swiss-post/autocomplete',
        autocompleteStreetUrl: '/bettercheckoutsw6/swiss-post/autocomplete-street',
        autocompleteHouseNumberUrl: '/bettercheckoutsw6/swiss-post/autocomplete-house-number',
        countryIdsUrl: null,
        countrySelectSelector: '.country-select',
        zipInputSelector: 'input[name$="[zipcode]"], input[name="zipcode"]',
        cityInputSelector: 'input[name$="[city]"], input[name="city"]',
        streetInputSelector: 'input[name$="[street]"], input[name="street"]',
    };

    init() {
        this._client = new HttpClient();
        this._supportedCountryIds = null;
        this._dropdownActive = null;

        console.log(LOG_PREFIX, 'Plugin initializing on element:', this.el);

        this._initElements();

        if (!this.zipInput && !this.streetInput) {
            console.warn(LOG_PREFIX, 'No ZIP or street input found — plugin will not activate');
            return;
        }

        if (!this.countrySelect) {
            console.warn(LOG_PREFIX, 'Country select not found — autocomplete will not filter by country');
        }

        this._fetchCountryIds();
        this._registerEvents();

        console.log(LOG_PREFIX, 'Plugin initialized. ZIP input:', !!this.zipInput,
            'City input:', !!this.cityInput,
            'Street input:', !!this.streetInput,
            'Country select:', !!this.countrySelect);
    }

    _initElements() {
        this.countrySelect = this.el.querySelector(this.options.countrySelectSelector);
        this.zipInput = this.el.querySelector(this.options.zipInputSelector);
        this.cityInput = this.el.querySelector(this.options.cityInputSelector);
        this.streetInput = this.el.querySelector(this.options.streetInputSelector);

        if (!this.zipInput) {
            console.debug(LOG_PREFIX, 'ZIP input not found with selector:', this.options.zipInputSelector);
        }
        if (!this.cityInput) {
            console.debug(LOG_PREFIX, 'City input not found with selector:', this.options.cityInputSelector);
        }
        if (!this.streetInput) {
            console.debug(LOG_PREFIX, 'Street input not found with selector:', this.options.streetInputSelector);
        }
        if (!this.countrySelect) {
            console.debug(LOG_PREFIX, 'Country select not found with selector:', this.options.countrySelectSelector);
        }
    }

    _fetchCountryIds() {
        const url = this.options.countryIdsUrl;
        if (!url) {
            console.warn(LOG_PREFIX, 'countryIdsUrl is empty — cannot fetch country IDs');
            return;
        }

        console.log(LOG_PREFIX, 'Fetching country IDs from:', url);

        this._client.get(url, (response) => {
            try {
                this._supportedCountryIds = JSON.parse(response);
                console.log(LOG_PREFIX, 'Supported country IDs loaded:', this._supportedCountryIds);
            } catch (e) {
                console.error(LOG_PREFIX, 'Failed to parse country IDs response:', e, response);
                this._supportedCountryIds = null;
            }
        });
    }

    _isCountrySupported() {
        if (!this.countrySelect) {
            console.debug(LOG_PREFIX, 'Country select missing — cannot determine country');
            return false;
        }
        if (!this._supportedCountryIds) {
            return true;
        }

        const selectedOption = this.countrySelect.options[this.countrySelect.selectedIndex];
        const isSupported = selectedOption && this._supportedCountryIds.includes(selectedOption.value);
        console.debug(LOG_PREFIX, 'Country check — selected:', selectedOption?.value, 'supported:', isSupported);
        return isSupported;
    }

    _registerEvents() {
        if (this.zipInput) {
            const debouncedZipAutocomplete = Debouncer.debounce(this._onZipAutocomplete.bind(this), 300);
            this.zipInput.addEventListener('input', (e) => {
                debouncedZipAutocomplete(e.target.value);
            });
            this.zipInput.addEventListener('keydown', this._onKeydown.bind(this));
            console.log(LOG_PREFIX, 'ZIP input event listeners registered');
        }

        if (this.streetInput) {
            const debouncedStreetAutocomplete = Debouncer.debounce(this._onStreetAutocomplete.bind(this), 300);
            this.streetInput.addEventListener('input', (e) => {
                debouncedStreetAutocomplete(e.target.value);
            });
            this.streetInput.addEventListener('keydown', this._onHouseNumberKeydown.bind(this));
            console.log(LOG_PREFIX, 'Street input event listeners registered');
        }

        document.addEventListener('click', (e) => {
            if (this._dropdownActive && !this._dropdownActive.contains(e.target)) {
                this._closeDropdown();
            }
        });
    }

    _onZipAutocomplete(query) {
        if (query.length < 2) {
            this._closeDropdown();
            return;
        }

        if (!this._isCountrySupported()) {
            console.debug(LOG_PREFIX, 'ZIP autocomplete skipped — country not supported');
            this._closeDropdown();
            return;
        }

        const url = `${this.options.autocompleteUrl}?query=${encodeURIComponent(query)}`;
        console.log(LOG_PREFIX, 'ZIP autocomplete request:', url);

        this._client.get(url, (response) => {
            try {
                const data = JSON.parse(response);
                console.log(LOG_PREFIX, 'ZIP autocomplete results:', data.length, 'items');
                this._renderZipDropdown(data);
            } catch (e) {
                console.error(LOG_PREFIX, 'ZIP autocomplete parse error:', e);
                this._closeDropdown();
            }
        });
    }

    _onStreetAutocomplete(query) {
        if (query.length < 2) {
            this._closeDropdown();
            return;
        }

        if (!this._isCountrySupported()) {
            this._closeDropdown();
            return;
        }

        const zip = this.zipInput ? this.zipInput.value.trim() : '';
        if (!zip) {
            console.debug(LOG_PREFIX, 'Street autocomplete skipped — no ZIP code entered yet');
            this._closeDropdown();
            return;
        }

        const url = `${this.options.autocompleteStreetUrl}?query=${encodeURIComponent(query)}&zip=${encodeURIComponent(zip)}`;
        console.log(LOG_PREFIX, 'Street autocomplete request:', url);

        this._client.get(url, (response) => {
            try {
                const data = JSON.parse(response);
                console.log(LOG_PREFIX, 'Street autocomplete results:', data.length, 'items');
                this._renderStreetDropdown(data);
            } catch (e) {
                console.error(LOG_PREFIX, 'Street autocomplete parse error:', e);
                this._closeDropdown();
            }
        });
    }

    _renderZipDropdown(items) {
        this._closeDropdown();
        if (items.length === 0) return;

        const dropdown = document.createElement('div');
        dropdown.className = 'swiss-post-autocomplete-dropdown list-group position-absolute w-100 shadow-sm';
        dropdown.style.zIndex = '1000';
        dropdown.style.maxHeight = '240px';
        dropdown.style.overflowY = 'auto';

        items.forEach((item) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action py-2 text-start';
            btn.innerHTML = `<strong>${item.zip}</strong> ${item.city}`;
            btn.addEventListener('click', () => {
                this._selectZipItem(item);
            });
            btn.addEventListener('mouseenter', () => {
                const allItems = dropdown.querySelectorAll('.list-group-item');
                allItems.forEach(i => i.classList.remove('active'));
                btn.classList.add('active');
            });
            dropdown.appendChild(btn);
        });

        this.zipInput.parentNode.style.position = 'relative';
        this.zipInput.parentNode.appendChild(dropdown);
        this._dropdownActive = dropdown;
    }

    _renderStreetDropdown(items) {
        this._closeDropdown();
        if (items.length === 0) return;

        const dropdown = document.createElement('div');
        dropdown.className = 'swiss-post-autocomplete-dropdown list-group position-absolute w-100 shadow-sm';
        dropdown.style.zIndex = '1000';
        dropdown.style.maxHeight = '240px';
        dropdown.style.overflowY = 'auto';

        items.forEach((item) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action py-2 text-start';
            btn.innerHTML = `<strong>${item.street}</strong> ${item.zip} ${item.city}`;
            btn.addEventListener('click', () => {
                this._selectStreetItem(item);
            });
            btn.addEventListener('mouseenter', () => {
                const allItems = dropdown.querySelectorAll('.list-group-item');
                allItems.forEach(i => i.classList.remove('active'));
                btn.classList.add('active');
            });
            dropdown.appendChild(btn);
        });

        this.streetInput.parentNode.style.position = 'relative';
        this.streetInput.parentNode.appendChild(dropdown);
        this._dropdownActive = dropdown;
    }

    _selectZipItem(item) {
        console.log(LOG_PREFIX, 'ZIP selected:', item.zip, item.city);
        this.zipInput.value = item.zip;
        this.cityInput.value = item.city;
        this.zipInput.dispatchEvent(new Event('input', { bubbles: true }));
        this.cityInput.dispatchEvent(new Event('input', { bubbles: true }));
        this._closeDropdown();
    }

    _selectStreetItem(item) {
        console.log(LOG_PREFIX, 'Street selected:', item.street, item.zip, item.city);
        this.streetInput.value = item.street;
        if (this.zipInput && !this.zipInput.value) {
            this.zipInput.value = item.zip;
        }
        if (this.cityInput && !this.cityInput.value) {
            this.cityInput.value = item.city;
        }
        this.streetInput.dispatchEvent(new Event('input', { bubbles: true }));
        this._closeDropdown();
    }

    _onKeydown(e) {
        const dropdown = this.el.querySelector('.swiss-post-autocomplete-dropdown');
        if (!dropdown) return;

        const items = dropdown.querySelectorAll('.list-group-item');
        if (items.length === 0) return;

        const currentIndex = Array.from(items).findIndex(item => item.classList.contains('active'));

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this._highlightItem(items, Math.min(currentIndex + 1, items.length - 1));
                this._scrollToItem(items[Math.min(currentIndex + 1, items.length - 1)]);
                break;
            case 'ArrowUp':
                e.preventDefault();
                this._highlightItem(items, Math.max(currentIndex - 1, 0));
                this._scrollToItem(items[Math.max(currentIndex - 1, 0)]);
                break;
            case 'Enter':
                e.preventDefault();
                if (currentIndex >= 0) {
                    items[currentIndex].click();
                }
                break;
            case 'Escape':
                e.preventDefault();
                this._closeDropdown();
                break;
        }
    }

    _onHouseNumberKeydown(e) {
    }

    _highlightItem(items, index) {
        items.forEach(item => item.classList.remove('active'));
        items[index].classList.add('active');
    }

    _scrollToItem(item) {
        const dropdown = item.closest('.swiss-post-autocomplete-dropdown');
        if (!dropdown) return;
        const itemRect = item.getBoundingClientRect();
        const containerRect = dropdown.getBoundingClientRect();
        if (itemRect.bottom > containerRect.bottom) {
            item.scrollIntoView({ block: 'nearest' });
        } else if (itemRect.top < containerRect.top) {
            item.scrollIntoView({ block: 'nearest' });
        }
    }

    _closeDropdown() {
        if (this._dropdownActive) {
            this._dropdownActive.remove();
            this._dropdownActive = null;
        }
    }
}
```

### 3.2 Rewrite `swiss-post-validator.plugin.js` with verbose logging

[MODIFY] `src/Resources/app/storefront/src/plugin/swiss-post-validator.plugin.js`

```javascript
import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import Debouncer from 'src/helper/debouncer.helper';

const LOG_PREFIX = '[TopdataSW6 Validator]';

export default class TopdataAddressValidator extends Plugin {
    static options = {
        validateUrl: '/bettercheckoutsw6/swiss-post/validate',
        countryIdsUrl: null,
        countrySelectSelector: '.country-select',
        zipInputSelector: 'input[name$="[zipcode]"], input[name="zipcode"]',
        cityInputSelector: 'input[name$="[city]"], input[name="city"]',
        streetInputSelector: 'input[name$="[street]"], input[name="street"]',
        firstNameInputSelector: 'input[name$="[firstName]"], input[name="firstName"]',
        lastNameInputSelector: 'input[name$="[lastName]"], input[name="lastName"]',
    };

    init() {
        this._client = new HttpClient();
        this._supportedCountryIds = null;

        console.log(LOG_PREFIX, 'Plugin initializing on element:', this.el);

        this._initElements();

        if (!this.countrySelect) {
            console.warn(LOG_PREFIX, 'Country select not found with selector:', this.options.countrySelectSelector);
            console.warn(LOG_PREFIX, 'Validator cannot function without a country selector — plugin inactive');
            return;
        }

        if (!this.zipInput) {
            console.warn(LOG_PREFIX, 'ZIP input not found with selector:', this.options.zipInputSelector);
        }

        this._fetchCountryIds();
        this._registerEvents();

        console.log(LOG_PREFIX, 'Plugin initialized. Elements found —',
            'country:', !!this.countrySelect,
            'zip:', !!this.zipInput,
            'city:', !!this.cityInput,
            'street:', !!this.streetInput,
            'firstName:', !!this.firstNameInput,
            'lastName:', !!this.lastNameInput,
            'widget:', !!this.widget);
    }

    _initElements() {
        this.countrySelect = this.el.querySelector(this.options.countrySelectSelector);
        this.zipInput = this.el.querySelector(this.options.zipInputSelector);
        this.cityInput = this.el.querySelector(this.options.cityInputSelector);
        this.streetInput = this.el.querySelector(this.options.streetInputSelector);
        this.firstNameInput = this.el.querySelector(this.options.firstNameInputSelector);
        this.lastNameInput = this.el.querySelector(this.options.lastNameInputSelector);
        this.widget = this.el.querySelector('[data-swiss-post-validation]');

        if (!this.widget) {
            console.warn(LOG_PREFIX, 'Validation widget not found — address validation UI will not appear. Look for [data-swiss-post-validation] in the DOM.');
        }
    }

    _fetchCountryIds() {
        const url = this.options.countryIdsUrl;
        if (!url) {
            console.warn(LOG_PREFIX, 'countryIdsUrl is empty — cannot fetch country IDs');
            return;
        }

        console.log(LOG_PREFIX, 'Fetching country IDs from:', url);

        this._client.get(url, (response) => {
            try {
                this._supportedCountryIds = JSON.parse(response);
                console.log(LOG_PREFIX, 'Supported country IDs loaded:', this._supportedCountryIds);
            } catch (e) {
                console.error(LOG_PREFIX, 'Failed to parse country IDs response:', e, response);
                this._supportedCountryIds = null;
            }
        });
    }

    _isCountrySupported() {
        if (!this.countrySelect) return false;
        if (!this._supportedCountryIds) return true;

        const selectedOption = this.countrySelect.options[this.countrySelect.selectedIndex];
        return selectedOption && this._supportedCountryIds.includes(selectedOption.value);
    }

    _registerEvents() {
        if (!this.countrySelect || !this.zipInput) {
            console.warn(LOG_PREFIX, 'Cannot register events — countrySelect:', !!this.countrySelect, 'zipInput:', !!this.zipInput);
            return;
        }

        const debouncedValidate = Debouncer.debounce(this._onValidate.bind(this), 400);
        this.el.addEventListener('input', (e) => {
            if (e.target.matches('input')) {
                debouncedValidate();
            }
        });

        this.countrySelect.addEventListener('change', this._onCountryChange.bind(this));
        this._onCountryChange();

        console.log(LOG_PREFIX, 'Event listeners registered');
    }

    _onCountryChange() {
        if (this._isCountrySupported()) {
            if (this.widget) {
                this.widget.classList.remove('d-none');
            }
            console.log(LOG_PREFIX, 'Supported country selected — validation active');
            this._onValidate();
        } else {
            if (this.widget) {
                this.widget.classList.add('d-none');
            }
            console.log(LOG_PREFIX, 'Non-supported country selected — validation hidden');
        }
    }

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
                    } else {
                        this._updateWidgetState('not-certified');
                    }
                } else {
                    this._updateWidgetState('error', data.error || 'Server validation error');
                }
            } catch (e) {
                console.error(LOG_PREFIX, 'Validation response parse error:', e);
                this._updateWidgetState('error', 'Malformed response');
            }
        });
    }

    _getAddressPayload() {
        const selectedOption = this.countrySelect.options[this.countrySelect.selectedIndex];
        return {
            firstName: this.firstNameInput ? this.firstNameInput.value.trim() : '',
            lastName: this.lastNameInput ? this.lastNameInput.value.trim() : '',
            street: this.streetInput ? this.streetInput.value.trim() : '',
            zipcode: this.zipInput ? this.zipInput.value.trim() : '',
            city: this.cityInput ? this.cityInput.value.trim() : '',
            countryCode: selectedOption ? selectedOption.getAttribute('data-country-iso') : 'CH',
        };
    }

    _updateWidgetState(state, errorMsg = '') {
        if (!this.widget) {
            console.warn(LOG_PREFIX, 'Cannot update widget state — widget element not found');
            return;
        }

        console.log(LOG_PREFIX, 'Widget state changed to:', state, errorMsg ? '(' + errorMsg + ')' : '');

        const msgs = this.widget.querySelectorAll('.status-msg');
        msgs.forEach(msg => msg.classList.add('d-none'));

        const activeMsg = this.widget.querySelector(`.status-${state}`);
        if (activeMsg) {
            activeMsg.classList.remove('d-none');
            if (state === 'error') {
                activeMsg.querySelector('.error-details').textContent = errorMsg;
            }
        }
    }
}
```

### 3.3 Update `main.js` — Make registration config-aware

[MODIFY] `src/Resources/app/storefront/src/main.js`

No changes needed to the main.js registration itself — the plugin registration with DOM selectors means the plugins only instantiate when the `data-*` attributes are present. Conditional rendering in Twig (Phase 4) handles the config gating.

## Deliverables

- Both JS plugins with comprehensive `console.log` / `console.debug` / `console.warn` / `console.error` logging
- All major lifecycle events logged (init, element detection, event registration, API calls, responses)
- Street autocomplete support added to `TopdataZipAutocomplete` (now also handles streets)
- House-number autocomplete foundation (route only, JS interaction for future enhancement)

---

# Phase 4: Twig Template Updates — Config-Gated Rendering

**Objective**: Make data attributes conditional on config so that disabled features don't initialize JS plugins. Also pass config flags to JS for runtime checks.

## Tasks

### 4.1 Update `address-personal.html.twig` — Conditional data attributes with config passthrough

[MODIFY] `src/Resources/views/storefront/component/address/address-personal.html.twig`

```twig
{% sw_extends '@Storefront/storefront/component/address/address-personal.html.twig' %}

{% block component_address_personal_fields %}
    {% set swissPostEnabled = config('TopdataBetterCheckoutSW6.config.swissPostEnabled') %}
    {% set swissPostValidationEnabled = swissPostEnabled and config('TopdataBetterCheckoutSW6.config.swissPostValidationEnabled') %}
    {% set swissPostAutocompleteEnabled = swissPostEnabled and config('TopdataBetterCheckoutSW6.config.swissPostAutocompleteEnabled') %}
    {% set swissPostCountryIdsUrl = path('frontend.bettercheckoutsw6.swiss-post.country-ids') %}

    {% set validatorOptions = {} %}
    {% if swissPostValidationEnabled %}
        {% set validatorOptions = { countryIdsUrl: swissPostCountryIdsUrl } %}
    {% endif %}

    {% set autocompleteOptions = {} %}
    {% if swissPostAutocompleteEnabled %}
        {% set autocompleteOptions = { countryIdsUrl: swissPostCountryIdsUrl } %}
    {% endif %}

    <div{% if swissPostValidationEnabled %} data-topdata-address-validator='{{ validatorOptions|json_encode }}'{% endif %}{% if swissPostAutocompleteEnabled %} data-topdata-zip-autocomplete='{{ autocompleteOptions|json_encode }}'{% endif %}>
        {{ parent() }}
    </div>
{% endblock %}

{% block component_address_personal_account_type %}
    {% set isRegistrationRoute = app.request.attributes.get('_route') in ['frontend.checkout.register.page', 'frontend.account.register.page', 'frontend.account.login.page'] %}

    {% if not isRegistrationRoute %}
        {{ parent() }}
    {% else %}
        {% set checkoutType = app.request.query.get('checkoutType')
            ?: app.request.request.get('checkoutType')
            ?: (data is defined and data is not null and data.all is defined ? data.get('checkoutType') : null) %}

        {% set guestSetting = config('TopdataBetterCheckoutSW6.config.guestAccountType') ?: 'user_choice' %}
        {% set registerSetting = config('TopdataBetterCheckoutSW6.config.registrationAccountType') ?: 'always_business' %}

        {% set accountTypeSetting = checkoutType == 'guest' ? guestSetting : registerSetting %}

        {% if accountTypeSetting == 'user_choice' %}
            {{ parent() }}
        {% elseif accountTypeSetting == 'always_business' %}
            {% if prefix != 'shippingAddress' %}
                <input type="hidden" name="{% if prefix %}{{ prefix }}[accountType]{% else %}accountType{% endif %}" value="{{ constant('Shopware\\Core\\Checkout\\Customer\\CustomerEntity::ACCOUNT_TYPE_BUSINESS') }}">
            {% endif %}
        {% elseif accountTypeSetting == 'always_private' %}
            {% if prefix != 'shippingAddress' %}
                <input type="hidden" name="{% if prefix %}{{ prefix }}[accountType]{% else %}accountType{% endif %}" value="{{ constant('Shopware\\Core\\Checkout\\Customer\\CustomerEntity::ACCOUNT_TYPE_PRIVATE') }}">
            {% endif %}
        {% endif %}
    {% endif %}
{% endblock %}
```

### 4.2 Update `address-form.html.twig` — Use validation-specific config

[MODIFY] `src/Resources/views/storefront/component/address/address-form.html.twig`

```twig
{% sw_extends '@Storefront/storefront/component/address/address-form.html.twig' %}

{% block component_address_form_address_fields %}
    {{ parent() }}

    {% set swissPostEnabled = config('TopdataBetterCheckoutSW6.config.swissPostEnabled') %}
    {% set swissPostValidationEnabled = swissPostEnabled and config('TopdataBetterCheckoutSW6.config.swissPostValidationEnabled') %}

    {% if swissPostValidationEnabled %}
        {% sw_include '@TopdataBetterCheckoutSW6/storefront/component/address/swiss-post-widget.html.twig' %}
    {% endif %}
{% endblock %}
```

## Deliverables

- `address-personal.html.twig` renders `data-topdata-address-validator` only when validation is enabled
- `address-personal.html.twig` renders `data-topdata-zip-autocomplete` only when autocomplete is enabled
- `address-form.html.twig` shows validation widget only when validation is enabled
- No `data-topdata-*` attributes rendered when the master toggle `swissPostEnabled` is off

---

# Phase 5: Snippet Updates

**Objective**: Add autocomplete-related snippets and update the validation card title to all 5 languages. Add missing `swissPost.*` keys to `fr-FR`, `fr-CH`, and `pt-PT`.

## Tasks

### 5.1 Update `en-GB` snippets

[MODIFY] `src/Resources/snippet/en_GB/storefront.en-GB.json`

Add to the `swissPost` key:

```json
"swissPost": {
    "validationTitle": "Address check (Swiss Post)",
    "defaultText": "The check will be executed as soon as all fields are filled in.",
    "certifiedText": "The address is certified by Swiss Post.",
    "notCertifiedText": "The system could not detect an acceptable address.",
    "errorText": "Address check failure:",
    "autocompleteZipPlaceholder": "Enter ZIP code...",
    "autocompleteStreetPlaceholder": "Enter street..."
}
```

### 5.2 Update `de-DE` snippets

[MODIFY] `src/Resources/snippet/de_DE/storefront.de-DE.json`

```json
"swissPost": {
    "validationTitle": "Adressprüfung (Swiss Post)",
    "defaultText": "Die Prüfung wird ausgeführt, sobald alle Felder ausgefüllt sind.",
    "certifiedText": "Die Adresse ist von der Swiss Post zertifiziert.",
    "notCertifiedText": "Das System konnte keine akzeptable Adresse erkennen.",
    "errorText": "Fehler bei der Adressprüfung:",
    "autocompleteZipPlaceholder": "PLZ eingeben...",
    "autocompleteStreetPlaceholder": "Strasse eingeben..."
}
```

### 5.3 Add missing `swissPost` keys to `fr-FR`

[MODIFY] `src/Resources/snippet/fr_FR/storefront.fr-FR.json`

Add to the `TopdataBetterCheckoutSW6` key:

```json
"swissPost": {
    "validationTitle": "Vérification d'adresse (Swiss Post)",
    "defaultText": "La vérification sera exécutée dès que tous les champs seront remplis.",
    "certifiedText": "L'adresse est certifiée par Swiss Post.",
    "notCertifiedText": "Le système n'a pas pu détecter une adresse acceptable.",
    "errorText": "Échec de la vérification d'adresse :",
    "autocompleteZipPlaceholder": "Entrez le code postal...",
    "autocompleteStreetPlaceholder": "Entrez la rue..."
}
```

### 5.4 Add missing `swissPost` keys to `fr-CH`

[MODIFY] `src/Resources/snippet/fr_CH/storefront.fr-CH.json`

Add to the `TopdataBetterCheckoutSW6` key:

```json
"swissPost": {
    "validationTitle": "Vérification d'adresse (Swiss Post)",
    "defaultText": "La vérification sera exécutée dès que tous les champs seront remplis.",
    "certifiedText": "L'adresse est certifiée par Swiss Post.",
    "notCertifiedText": "Le système n'a pas pu détecter une adresse acceptable.",
    "errorText": "Échec de la vérification d'adresse :",
    "autocompleteZipPlaceholder": "Entrez le code postal...",
    "autocompleteStreetPlaceholder": "Entrez la rue..."
}
```

### 5.5 Add missing `swissPost` keys to `pt-PT`

[MODIFY] `src/Resources/snippet/pt_PT/storefront.pt-PT.json`

Add to the `TopdataBetterCheckoutSW6` key:

```json
"swissPost": {
    "validationTitle": "Verificação de endereço (Swiss Post)",
    "defaultText": "A verificação será executada assim que todos os campos estiverem preenchidos.",
    "certifiedText": "O endereço é certificado pela Swiss Post.",
    "notCertifiedText": "O sistema não conseguiu detetar um endereço aceitável.",
    "errorText": "Falha na verificação de endereço:",
    "autocompleteZipPlaceholder": "Introduza o código postal...",
    "autocompleteStreetPlaceholder": "Introduza a rua..."
}
```

## Deliverables

- All 5 snippet files contain `swissPost.*` keys with proper translations
- New snippet keys for autocomplete placeholder text in all languages

---

# Phase 6: Build, Cache Clear & Verification

**Objective**: Rebuild the storefront JS bundle, clear all Shopware caches, and provide a manual verification checklist.

## Tasks

### 6.1 Rebuild storefront JS

```bash
# Inside the SW6 container:
./bin/build-js.sh
```

### 6.2 Clear all caches

```bash
php bin/console cache:clear
php bin/console cache:warmup
```

### 6.3 Verification Checklist

After deploying, open the browser DevTools console and navigate to the registration page (`/checkout/register?checkoutType=register`). Verify:

1. **With `swissPostEnabled = true`, `swissPostValidationEnabled = true`, `swissPostAutocompleteEnabled = true`:**
   - Console shows: `[TopdataSW6 Validator] Plugin initializing on element: <div>` 
   - Console shows: `[TopdataSW6 Validator] Fetching country IDs from: /bettercheckoutsw6/swiss-post/country-ids`
   - Console shows: `[TopdataSW6 Validator] Supported country IDs loaded: [...]`
   - Console shows: `[TopdataSW6 Autocomplete] Plugin initializing on element: <div>`
   - Console shows: `[TopdataSW6 Autocomplete] Fetching country IDs from: /bettercheckoutsw6/swiss-post/country-ids`
   - Select Switzerland as country → validation widget appears
   - Type two characters in ZIP field → Network tab shows GET to `/bettercheckoutsw6/swiss-post/autocomplete?query=..`
   - Select a ZIP/city from dropdown → city field auto-fills
   - Type two characters in street field (with ZIP filled) → Network tab shows GET to `/bettercheckoutsw6/swiss-post/autocomplete-street?query=..&zip=..`
   - Fill all address fields → Network tab shows POST to `/bettercheckoutsw6/swiss-post/validate`

2. **With `swissPostEnabled = true`, `swissPostValidationEnabled = false`, `swissPostAutocompleteEnabled = true`:**
   - No `[TopdataSW6 Validator]` messages in console
   - No `data-topdata-address-validator` attribute in DOM
   - No validation widget visible
   - Autocomplete still works for ZIP/street

3. **With `swissPostEnabled = true`, `swissPostValidationEnabled = true`, `swissPostAutocompleteEnabled = false`:**
   - No `[TopdataSW6 Autocomplete]` messages in console
   - No `data-topdata-zip-autocomplete` attribute in DOM
   - Validation still works (widget appears for CH/LI)

4. **With `swissPostEnabled = false`:**
   - Neither plugin initializes
   - Neither `data-*` attribute present in DOM
   - No Swiss Post network requests at all

5. **Error cases:**
   - Invalid credentials → console shows `[TopdataSW6 Validator] Validation response: {success: false, error: "..."}`
   - Non-CH/LI country selected → console shows `[TopdataSW6 Validator] Non-supported country selected — validation hidden`

## Deliverables

- Built JS bundle at `src/Resources/app/storefront/dist/storefront/js/topdata-better-checkout-s-w6/topdata-better-checkout-s-w6.js`
- All caches cleared and warmed up
- Full verification checklist documented

---

# Phase 7: Implementation Report

Write the implementation report to `_ai/backlog/reports/260609_1240__IMPLEMENTATION_REPORT__swiss-post-autocomplete-fix.md`.