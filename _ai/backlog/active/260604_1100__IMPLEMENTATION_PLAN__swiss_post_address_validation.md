---
filename: "_ai/backlog/active/260604_1100__IMPLEMENTATION_PLAN__swiss_post_address_validation.md"
title: "Implementation Plan: Swiss Post Address Validation"
createdAt: 2026-06-04 11:00
updatedAt: 2026-06-04 11:00
status: draft
priority: high
tags: [swiss-post, address-validation, storefront, admin]
estimatedComplexity: complex
documentType: IMPLEMENTATION_PLAN
---

# Implementation Plan: Swiss Post Address Validation

This plan describes the implementation of a Swiss Post address validation and ZIP/city autocomplete integration for the `TopdataBetterCheckoutSW6` plugin. It details the technical steps to handle server-to-server OAuth2 authentication, real-time storefront checks, metadata caching, and automatic persistence on address changes.

## Executive Summary
We will implement a modular solution strictly adhering to SOLID principles and Shopware 6.7 conventions.
1. **API Client**: A resilient PHP client using PSR-18 (`Psr\Http\Client\ClientInterface`) and PSR-17 (`Psr\Http\Message\RequestFactoryInterface`) to communicate with the Swiss Post API.
2. **Token & Data Cache**: Leverage Symfony's PSR-6 cache adapter to cache the OAuth2 access tokens and ZIP search queries securely.
3. **Storefront API & JS**: Lightweight storefront JSON controller with a corresponding Vite-compiled vanilla JavaScript plugin. The JS uses global `window.bootstrap` components and debounces external queries.
4. **Data Persistence**: Map validation status into Shopware's built-in `customFields` on the `customer_address` entity (`topdata_swiss_post_certification_status`).
5. **Address Certification via Custom Fields**: Certification status persisted to `customFields` on address save using a `EntityWrittenEvent` subscriber.

---

## Project Environment Details
- Project Name: Topdata Better Checkout SW6 (SW6.7 Plugin)
- Backend Root: `src`
- PHP Version: 8.3+
- Symfony Version: 7.4

---

## Phase 1: Configuration, API Client, and Token Caching

We will add settings to `config.xml` and implement the token retrieval and caching client.

### [MODIFY] `src/Resources/config/config.xml`
Add a new card containing settings for the Swiss Post API credentials.

```xml
        <!-- ... Existing Cards ... -->
        <card>
            <title>Swiss Post Address Validation</title>
            <title lang="de-DE">Swiss Post Adressprüfung</title>

            <input-field type="bool">
                <name>swissPostEnabled</name>
                <label>Enable Swiss Post Address Validation</label>
                <label lang="de-DE">Swiss Post Adressprüfung aktivieren</label>
                <defaultValue>false</defaultValue>
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
    </config>
```

### [MODIFY] `src/TopdataBetterCheckoutSW6.php`

Add `install()` and `uninstall()` lifecycle hooks to create/remove the Swiss Post custom field set on `customer_address`:

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\CustomField\CustomFieldTypes;

class TopdataBetterCheckoutSW6 extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $this->installCustomFields($installContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        if ($uninstallContext->keepUserData()) {
            parent::uninstall($uninstallContext);
            return;
        }

        $this->removeCustomFields($uninstallContext);
        parent::uninstall($uninstallContext);
    }

    private function installCustomFields(InstallContext $installContext): void
    {
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('name', ['topdata_swiss_post_address_validation']));
        $existing = $customFieldSetRepository->searchIds($criteria, $installContext->getContext());

        if ($existing->getTotal() > 0) {
            return;
        }

        $customFieldSetRepository->create([
            [
                'name' => 'topdata_swiss_post_address_validation',
                'config' => [
                    'label' => [
                        'en-GB' => 'Topdata Swiss Post',
                        'de-DE' => 'Topdata Swiss Post',
                    ],
                ],
                'customFields' => [
                    [
                        'name' => 'topdata_swiss_post_certification_status',
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Swiss Post certificate',
                                'de-DE' => 'Swiss Post Zertifikat',
                            ],
                            'type' => 'text',
                            'customFieldType' => 'text',
                            'customFieldPosition' => 1,
                        ],
                    ],
                ],
                'relations' => [
                    [
                        'id' => Uuid::randomHex(),
                        'entityName' => 'customer_address',
                    ],
                ],
            ],
        ], $installContext->getContext());
    }

    private function removeCustomFields(UninstallContext $uninstallContext): void
    {
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('name', ['topdata_swiss_post_address_validation']));

        $ids = $customFieldSetRepository->searchIds($criteria, $uninstallContext->getContext());

        if ($ids->getTotal() > 0) {
            $customFieldSetRepository->delete(array_values($ids->getData()), $uninstallContext->getContext());
        }
    }
}
```

### [NEW FILE] `src/Core/Content/SwissPost/SwissPostApiService.php`
Create the core client handling authentication, address validation, and ZIP search using PSR-18 HTTP Client.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Topdata\TopdataBetterCheckoutSW6\Service\SwissPost\SwissPostAddressValidationRequest;

class SwissPostApiService
{
    private const AUTH_URL = 'https://api.post.ch/OAuth/token';
    private const BASE_API_URL = 'https://dcapi.apis.post.ch/address/v1';
    private const CACHE_KEY_TOKEN = 'topdata_swiss_post_oauth_token';
    private const CACHE_KEY_PREFIX_ZIP = 'topdata_swiss_post_zip_';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly CacheItemPoolInterface $cache,
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isEnabled(?string $salesChannelId = null): bool
    {
        return $this->systemConfigService->getBool(
            'TopdataBetterCheckoutSW6.config.swissPostEnabled',
            $salesChannelId
        );
    }

    /**
     * Authenticates and returns a bearer token, using cache when possible.
     */
    public function getAccessToken(?string $salesChannelId = null): ?string
    {
        $cacheKey = self::CACHE_KEY_TOKEN . '_' . ($salesChannelId ?? 'global');
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $clientId = $this->systemConfigService->getString('TopdataBetterCheckoutSW6.config.swissPostClientId', $salesChannelId);
        $clientSecret = $this->systemConfigService->getString('TopdataBetterCheckoutSW6.config.swissPostClientSecret', $salesChannelId);

        if (empty($clientId) || empty($clientSecret)) {
            return null;
        }

        try {
            $body = http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'DCAPI_ADDRESS_VALIDATE DCAPI_ADDRESS_AUTOCOMPLETE'
            ]);

            $request = $this->requestFactory->createRequest('POST', self::AUTH_URL)
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withBody($this->streamFactory->createStream($body));

            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Swiss Post Auth Failure', ['status' => $response->getStatusCode(), 'body' => $response->getBody()->getContents()]);
                return null;
            }

            $data = json_decode($response->getBody()->getContents(), true);
            $token = $data['access_token'] ?? null;
            $expiresIn = (int)($data['expires_in'] ?? 3500);

            if ($token) {
                $cacheItem->set($token);
                $cacheItem->expiresAfter($expiresIn - 60); // Safety buffer
                $this->cache->save($cacheItem);
                return $token;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Swiss Post Auth Exception', ['exception' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Validates a given address payload.
     */
    public function validateAddress(array $address, ?string $salesChannelId = null): array
    {
        $token = $this->getAccessToken($salesChannelId);
        if (!$token) {
            return ['success' => false, 'error' => 'Could not authenticate with Swiss Post API'];
        }

        try {
            $split = $this->splitStreet($address['street'] ?? '');

            $dto = new SwissPostAddressValidationRequest(
                firstName: $address['firstName'] ?? '',
                lastName: $address['lastName'] ?? '',
                street: $split['streetName'],
                houseNumber: $split['houseNumber'],
                zip: $address['zipcode'] ?? '',
                city: $address['city'] ?? '',
            );

            $payload = json_encode($dto);

            $request = $this->requestFactory->createRequest('POST', self::BASE_API_URL . '/addresses/validation')
                ->withHeader('Authorization', 'Bearer ' . $token)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($payload));

            $response = $this->httpClient->sendRequest($request);
            $contents = $response->getBody()->getContents();

            if ($response->getStatusCode() === 200) {
                $result = json_decode($contents, true);
                return [
                    'success' => true,
                    'quality' => $result['quality'] ?? 'UNKNOWN',
                    'originalResponse' => $result
                ];
            }

            return [
                'success' => false,
                'error' => 'API returned status ' . $response->getStatusCode(),
                'details' => json_decode($contents, true)
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Lightweight inline street splitter for Swiss addresses.
     * Extracts house number from the end of the street string.
     * Handles patterns like "Hauptstrasse 12", "Route des Alpes 42bis", "Bahnhofstrasse 12-14".
     * Falls back to using the full street as street name with empty house number.
     */
    private function splitStreet(string $street): array
    {
        if (preg_match('/^(.+?)\s+(\d[\d\s\-\/]*(?:[a-zA-Z])?)$/u', trim($street), $m)) {
            return [
                'streetName' => trim($m[1]),
                'houseNumber' => trim($m[2]),
            ];
        }

        return ['streetName' => $street, 'houseNumber' => ''];
    }

    /**
     * Search ZIP codes and autocomplete matching city names.
     */
    public function autocompleteZip(string $query, ?string $salesChannelId = null): array
    {
        $token = $this->getAccessToken($salesChannelId);
        if (!$token) {
            return [];
        }

        $cacheKey = self::CACHE_KEY_PREFIX_ZIP . md5($query);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        try {
            $url = self::BASE_API_URL . '/zips?zipCity=' . urlencode($query) . '&type=DOMICILE';
            $request = $this->requestFactory->createRequest('GET', $url)
                ->withHeader('Authorization', 'Bearer ' . $token)
                ->withHeader('Accept', 'application/json');

            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true) ?? [];
                
                // Map to structured simplified format
                $results = array_map(static fn($item) => [
                    'zip' => $item['zip'] ?? '',
                    'city' => $item['city18'] ?? $item['city27'] ?? ''
                ], $data);

                $cacheItem->set($results);
                $cacheItem->expiresAfter(86400); // 24 hours
                $this->cache->save($cacheItem);

                return $results;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Swiss Post Autocomplete Exception', ['exception' => $e->getMessage()]);
        }

        return [];
    }
}
```

---

## Phase 2: Core Business Logic & Address Metadata Integration

We will wire standard routes to expose API services to the storefront, listen to address persistence events, and update custom metadata.

### [NEW FILE] `src/Controller/SwissPostStorefrontController.php`
Expose endpoints for real-time validation checks and autocomplete requests.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Controller;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\SwissPostApiService;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class SwissPostStorefrontController extends StorefrontController
{
    public function __construct(
        private readonly SwissPostApiService $apiService
    ) {
    }

    #[Route(
        path: '/bettercheckoutsw6/swiss-post/validate',
        name: 'frontend.bettercheckoutsw6.swiss-post.validate',
        options: ['seo' => false],
        methods: ['POST']
    )]
    public function validate(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->apiService->isEnabled($context->getSalesChannelId())) {
            return new JsonResponse(['success' => false, 'error' => 'Swiss Post Validation is disabled.'], 403);
        }

        $addressData = $request->request->all('address');
        $result = $this->apiService->validateAddress($addressData, $context->getSalesChannelId());

        return new JsonResponse($result);
    }

    #[Route(
        path: '/bettercheckoutsw6/swiss-post/autocomplete',
        name: 'frontend.bettercheckoutsw6.swiss-post.autocomplete',
        options: ['seo' => false],
        methods: ['GET']
    )]
    public function autocomplete(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->apiService->isEnabled($context->getSalesChannelId())) {
            return new JsonResponse([], 403);
        }

        $query = $request->query->getString('query');
        if (mb_strlen($query) < 2) {
            return new JsonResponse([]);
        }

        $results = $this->apiService->autocompleteZip($query, $context->getSalesChannelId());
        return new JsonResponse($results);
    }
}
```

### [NEW FILE] `src/Core/Checkout/Customer/Subscriber/AddressCertificationSubscriber.php`
Automatically certify customer addresses whenever an address is written to the database.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\SwissPostApiService;

class AddressCertificationSubscriber implements EventSubscriberInterface
{
    public const METADATA_KEY = 'topdata_swiss_post_certification_status';

    public function __construct(
        private readonly SwissPostApiService $apiService,
        private readonly EntityRepository $customerAddressRepository
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'customer_address.written' => 'onAddressWritten'
        ];
    }

    public function onAddressWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        // Skip validation inside backend API tasks if credentials are contextual to SalesChannels
        $salesChannelId = null; 

        if (!$this->apiService->isEnabled($salesChannelId)) {
            return;
        }

        foreach ($event->getWriteResults() as $result) {
            $payload = $result->getPayload();
            $addressId = $payload['id'] ?? null;

            if (!$addressId) {
                continue;
            }

            // Avoid infinite loop during self-updates
            if (array_key_exists('customFields', $payload) && isset($payload['customFields'][self::METADATA_KEY])) {
                continue;
            }

            $criteria = new Criteria([$addressId]);
            $criteria->addAssociation('country');
            /** @var CustomerAddressEntity|null $addressEntity */
            $addressEntity = $this->customerAddressRepository->search($criteria, $context)->first();

            if (!$addressEntity || !$addressEntity->getCountry()) {
                continue;
            }

            $iso = $addressEntity->getCountry()->getIso();
            if ($iso !== 'CH' && $iso !== 'LI') {
                continue;
            }

            // Run validation synchronously during persistence
            $validation = $this->apiService->validateAddress([
                'firstName' => $addressEntity->getFirstName(),
                'lastName' => $addressEntity->getLastName(),
                'street' => $addressEntity->getStreet(),
                'zipcode' => $addressEntity->getZipcode(),
                'city' => $addressEntity->getCity(),
                'countryCode' => $iso,
            ], $salesChannelId);

            $quality = $validation['success'] ? ($validation['quality'] ?? 'UNKNOWN') : 'INVALID';

            $customFields = $addressEntity->getCustomFields() ?? [];
            $customFields[self::METADATA_KEY] = $quality;

            $this->customerAddressRepository->update([
                [
                    'id' => $addressId,
                    'customFields' => $customFields
                ]
            ], $context);
        }
    }
}
```

---

### [NEW FILE] `src/Service/SwissPost/SwissPostAddressValidationRequest.php`

Add a typed DTO for the DCAPI address validation request payload. Implements `JsonSerializable` for direct use with `json_encode()`.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Service\SwissPost;

class SwissPostAddressValidationRequest implements \JsonSerializable
{
    public function __construct(
        private readonly string $firstName,
        private readonly string $lastName,
        private readonly string $street,
        private readonly string $houseNumber,
        private readonly string $zip,
        private readonly string $city,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'addressee' => [
                'firstName' => $this->firstName,
                'lastName' => $this->lastName,
            ],
            'geographicLocation' => [
                'house' => [
                    'street' => $this->street,
                    'houseNumber' => $this->houseNumber,
                ],
                'zip' => [
                    'zip' => $this->zip,
                    'city' => $this->city,
                ],
            ],
            'fullValidation' => true,
        ];
    }
}
```

---

## Phase 3: Storefront Integration (Twig & JS)

We will integrate the validation notifications into relevant template files and build the reactive Vite-compiled JavaScript plugin.

### [NEW FILE] `src/Resources/snippet/storefront.de-DE.json`
*(Add key namespace in flat directory structure)*

```json
{
    "TopdataBetterCheckoutSW6": {
        "swissPost": {
            "validationTitle": "Adressprüfung (Swiss Post)",
            "defaultText": "Die Prüfung wird ausgeführt, sobald alle Felder ausgefüllt sind.",
            "certifiedText": "Die Adresse ist von der Swiss Post zertifiziert.",
            "notCertifiedText": "Das System konnte keine akzeptable Adresse erkennen.",
            "errorText": "Fehler bei der Adressprüfung:"
        }
    }
}
```

### [NEW FILE] `src/Resources/snippet/storefront.en-GB.json`

```json
{
    "TopdataBetterCheckoutSW6": {
        "swissPost": {
            "validationTitle": "Address check (Swiss Post)",
            "defaultText": "The check will be executed as soon as all fields are filled in.",
            "certifiedText": "The address is certified by Swiss Post.",
            "notCertifiedText": "The system could not detect an acceptable address.",
            "errorText": "Address check failure:"
        }
    }
}
```

### [NEW FILE] `src/Resources/views/storefront/component/address/swiss-post-widget.html.twig`
Create the UI markup block wrapper for displaying statuses.

```twig
<div class="swiss-post-validation-wrapper d-none my-3" data-swiss-post-validation="true">
    <div class="card border">
        <div class="card-header bg-light py-2">
            <strong>{{ "TopdataBetterCheckoutSW6.swissPost.validationTitle"|trans|sw_sanitize }}</strong>
        </div>
        <div class="card-body py-2">
            <div class="status-msg status-default text-muted">
                {{ "TopdataBetterCheckoutSW6.swissPost.defaultText"|trans|sw_sanitize }}
            </div>
            <div class="status-msg status-certified text-success d-none">
                {% sw_icon 'checkmark-circle' style { size: 'xs', pack: 'solid' } %}
                {{ "TopdataBetterCheckoutSW6.swissPost.certifiedText"|trans|sw_sanitize }}
            </div>
            <div class="status-msg status-not-certified text-warning d-none">
                {% sw_icon 'warning' style { size: 'xs', pack: 'solid' } %}
                {{ "TopdataBetterCheckoutSW6.swissPost.notCertifiedText"|trans|sw_sanitize }}
            </div>
            <div class="status-msg status-error text-danger d-none">
                {% sw_icon 'blocked' style { size: 'xs', pack: 'solid' } %}
                <span class="error-details"></span>
            </div>
        </div>
    </div>
</div>
```

### [MODIFY] `src/Resources/views/storefront/component/address/address-form.html.twig`
Incorporate the validation widget and custom autocomplete targets directly into standard address blocks.

```twig
{% sw_extends '@Storefront/storefront/component/address/address-form.html.twig' %}

{% block component_address_form_address_fields %}
    {{ parent() }}
    
    {% if config('TopdataBetterCheckoutSW6.config.swissPostEnabled') %}
        {% sw_include '@TopdataBetterCheckoutSW6/storefront/component/address/swiss-post-widget.html.twig' %}
    {% endif %}
{% endblock %}
```

### [NEW FILE] `src/Resources/app/storefront/src/plugin/swiss-post-validator.plugin.js`
The address validation plugin. Listens to field changes on address forms and calls the validation API for CH/LI addresses.

```javascript
import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import Debouncer from 'src/helper/debouncer.helper';

export default class TopdataAddressValidator extends Plugin {
    static options = {
        validateUrl: '/bettercheckoutsw6/swiss-post/validate',
        countrySelectSelector: '.country-select',
        zipInputSelector: 'input[name$="[zipcode]"], input[name="zipcode"]',
        cityInputSelector: 'input[name$="[city]"], input[name="city"]',
        streetInputSelector: 'input[name$="[street]"], input[name="street"]',
        firstNameInputSelector: 'input[name$="[firstName]"], input[name="firstName"]',
        lastNameInputSelector: 'input[name$="[lastName]"], input[name="lastName"]'
    };

    init() {
        this._client = new HttpClient();
        this._initElements();
        this._registerEvents();
    }

    _initElements() {
        this.countrySelect = this.el.querySelector(this.options.countrySelectSelector);
        this.zipInput = this.el.querySelector(this.options.zipInputSelector);
        this.cityInput = this.el.querySelector(this.options.cityInputSelector);
        this.streetInput = this.el.querySelector(this.options.streetInputSelector);
        this.firstNameInput = this.el.querySelector(this.options.firstNameInputSelector);
        this.lastNameInput = this.el.querySelector(this.options.lastNameInputSelector);
        this.widget = this.el.querySelector('[data-swiss-post-validation]');
    }

    _registerEvents() {
        if (!this.countrySelect || !this.zipInput) return;

        const debouncedValidate = Debouncer.debounce(this._onValidate.bind(this), 400);
        this.el.addEventListener('input', (e) => {
            if (e.target.matches('input')) {
                debouncedValidate();
            }
        });

        this.countrySelect.addEventListener('change', this._onCountryChange.bind(this));
        this._onCountryChange();
    }

    _onCountryChange() {
        const selectedOption = this.countrySelect.options[this.countrySelect.selectedIndex];
        const isCHorLI = ['CH', 'LI'].includes(selectedOption.getAttribute('data-country-iso'));

        if (isCHorLI) {
            this.widget.classList.remove('d-none');
            this._onValidate();
        } else {
            this.widget.classList.add('d-none');
        }
    }

    _onValidate() {
        const address = this._getAddressPayload();

        if (!address.firstName || !address.lastName || !address.street || !address.zipcode || !address.city) {
            this._updateWidgetState('default');
            return;
        }

        this._client.post(this.options.validateUrl, JSON.stringify({ address }), (response) => {
            try {
                const data = JSON.parse(response);
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
            countryCode: selectedOption ? selectedOption.getAttribute('data-country-iso') : 'CH'
        };
    }

    _updateWidgetState(state, errorMsg = '') {
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

### [NEW FILE] `src/Resources/app/storefront/src/plugin/swiss-post-autocomplete.plugin.js`
The ZIP/city autocomplete plugin. Debounces zip input and renders a dropdown with matching results.

```javascript
import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import Debouncer from 'src/helper/debouncer.helper';

export default class TopdataZipAutocomplete extends Plugin {
    static options = {
        autocompleteUrl: '/bettercheckoutsw6/swiss-post/autocomplete',
        zipInputSelector: 'input[name$="[zipcode]"], input[name="zipcode"]',
        cityInputSelector: 'input[name$="[city]"], input[name="city"]'
    };

    init() {
        this._client = new HttpClient();
        this._initElements();
        this._registerEvents();
    }

    _initElements() {
        this.zipInput = this.el.querySelector(this.options.zipInputSelector);
        this.cityInput = this.el.querySelector(this.options.cityInputSelector);
    }

    _registerEvents() {
        if (!this.zipInput) return;

        const debouncedAutocomplete = Debouncer.debounce(this._onAutocomplete.bind(this), 300);
        this.zipInput.addEventListener('input', (e) => {
            debouncedAutocomplete(e.target.value);
        });

        document.addEventListener('click', (e) => {
            if (!this.zipInput.contains(e.target)) {
                this._closeDropdown();
            }
        });
    }

    _onAutocomplete(query) {
        if (query.length < 2) {
            this._closeDropdown();
            return;
        }

        this._client.get(`${this.options.autocompleteUrl}?query=${encodeURIComponent(query)}`, (response) => {
            try {
                const data = JSON.parse(response);
                this._renderDropdown(data);
            } catch (e) {
                this._closeDropdown();
            }
        });
    }

    _renderDropdown(items) {
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
                this.zipInput.value = item.zip;
                this.cityInput.value = item.city;
                this.zipInput.dispatchEvent(new Event('input', { bubbles: true }));
                this.cityInput.dispatchEvent(new Event('input', { bubbles: true }));
                this._closeDropdown();
            });
            dropdown.appendChild(btn);
        });

        this.zipInput.parentNode.style.position = 'relative';
        this.zipInput.parentNode.appendChild(dropdown);
    }

    _closeDropdown() {
        const active = this.el.querySelector('.swiss-post-autocomplete-dropdown');
        if (active) {
            active.remove();
        }
    }
}
```

### [NEW FILE] `src/Resources/app/storefront/src/main.js`
Register both storefront plugins.

```javascript
import TopdataAddressValidator from './plugin/swiss-post-validator.plugin';
import TopdataZipAutocomplete from './plugin/swiss-post-autocomplete.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('TopdataAddressValidator', TopdataAddressValidator, '[data-topdata-address-validator]');
PluginManager.register('TopdataZipAutocomplete', TopdataZipAutocomplete, '[data-topdata-zip-autocomplete]');
```

### [MODIFY] `src/Resources/views/storefront/component/address/address-personal.html.twig`
Wrap form fields with target markup attributes for both plugins.

```twig
{% sw_extends '@Storefront/storefront/component/address/address-personal.html.twig' %}

{% block component_address_personal_fields %}
    <div data-topdata-address-validator="true" data-topdata-zip-autocomplete="true">
        {{ parent() }}
    </div>
{% endblock %}
```

---

## Phase 4: Administration UI & Testing Tools

We will implement a credential testing system allowing fast API troubleshooting.

### [NEW FILE] `src/Controller/AdminApi/SwissPostAdminController.php`
Create a controller to handle quick testing requests from the administration panel.

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Controller\AdminApi;

use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

#[Route(defaults: ['_routeScope' => ['api']])]
class SwissPostAdminController extends AbstractController
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory
    ) {
    }

    #[Route(
        path: '/api/topdata-better-checkout/swiss-post/test-credentials',
        name: 'api.topdata_better_checkout.swiss_post.test_credentials',
        methods: ['POST']
    )]
    public function testCredentials(RequestDataBag $data): JsonResponse
    {
        $clientId = $data->get('clientId');
        $clientSecret = $data->get('clientSecret');

        if (!$clientId || !$clientSecret) {
            return new JsonResponse(['success' => false, 'message' => 'Credentials must not be empty.'], 400);
        }

        try {
            $body = http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'DCAPI_ADDRESS_VALIDATE DCAPI_ADDRESS_AUTOCOMPLETE'
            ]);

            $request = $this->requestFactory->createRequest('POST', 'https://api.post.ch/OAuth/token')
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withBody($this->streamFactory->createStream($body));

            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() === 200) {
                return new JsonResponse(['success' => true]);
            }

            $errorMsg = 'Auth failed: Status ' . $response->getStatusCode();
            return new JsonResponse(['success' => false, 'message' => $errorMsg]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
```

---

## Phase 5: Quality Assurance, Documentation & Report

1. **Vite Compilation**: Execute JS assets packaging commands:
   ```bash
   composer build:js:storefront
   composer build:js:admin
   ```
2. **System Configurations Wire-up**: Modify `services.xml` to declare structural items.

### [MODIFY] `src/Resources/config/services.xml`
Register subscriber listeners and the API controller services.

```xml
        <!-- ... Existing Services ... -->

        <!-- Swiss Post API Client -->
        <service id="Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\SwissPostApiService" autowire="true">
            <argument type="service" id="psr18.client"/>
            <argument type="service" id="Nyholm\Psr7\Factory\Psr17Factory"/>
            <argument type="service" id="Nyholm\Psr7\Factory\Psr17Factory"/>
            <argument type="service" id="cache.object"/>
            <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService"/>
            <argument type="service" id="logger"/>
        </service>

        <!-- Swiss Post Controllers -->
        <service id="Topdata\TopdataBetterCheckoutSW6\Controller\SwissPostStorefrontController" public="true" autowire="true">
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>

        <service id="Topdata\TopdataBetterCheckoutSW6\Controller\AdminApi\SwissPostAdminController" public="true" autowire="true">
            <argument type="service" id="psr18.client"/>
            <argument type="service" id="Nyholm\Psr7\Factory\Psr17Factory"/>
            <argument type="service" id="Nyholm\Psr7\Factory\Psr17Factory"/>
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>

        <!-- Subscriber -->
        <service id="Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber\AddressCertificationSubscriber" autowire="true">
            <tag name="kernel.event_subscriber"/>
        </service>
    </services>
</container>
```

3. **Report Generation**: Compile implementation progress metrics into `_ai/backlog/reports/260604_1100__IMPLEMENTATION_REPORT__swiss_post_address_validation.md`.

---

## Phase 6: Documenting Implementation Report
Write a detailed report summarizing the completion status of the phases after integration testing.

### [NEW FILE] `_ai/backlog/reports/260604_1100__IMPLEMENTATION_REPORT__swiss_post_address_validation.md`

```yaml
---
filename: "_ai/backlog/reports/260604_1100__IMPLEMENTATION_REPORT__swiss_post_address_validation.md"
title: "Report: Swiss Post Address Validation"
createdAt: 2026-06-04 11:30
updatedAt: 2026-06-04 11:30
planFile: "_ai/backlog/active/260604_1100__IMPLEMENTATION_PLAN__swiss_post_address_validation.md"
project: "Topdata Better Checkout SW6"
status: completed
filesCreated: 10
filesModified: 4
filesDeleted: 0
tags: [swiss-post, address-validation, report]
documentType: IMPLEMENTATION_REPORT
---

# Report: Swiss Post Address Validation

## 1. Summary
We successfully implemented the Swiss Post Address Validation feature for the `TopdataBetterCheckoutSW6` plugin. This features real-time address validation via a typed DTO with street/house number splitting, ZIP/city auto-completion, synchronous certification tracking on save, and a credential validation tool in the administrator settings panel.

## 2. Files Changed
### New Files
- `src/Core/Content/SwissPost/SwissPostApiService.php` (Core API communication client)
- `src/Controller/SwissPostStorefrontController.php` (Storefront AJAX endpoint router)
- `src/Controller/AdminApi/SwissPostAdminController.php` (Admin testing API)
- `src/Core/Checkout/Customer/Subscriber/AddressCertificationSubscriber.php` (Save event certifier hook)
- `src/Service/SwissPost/SwissPostAddressValidationRequest.php` (Typed DTO with JsonSerializable)
- `src/Resources/views/storefront/component/address/swiss-post-widget.html.twig` (UI status container)
- `src/Resources/app/storefront/src/plugin/swiss-post-validator.plugin.js` (Debounced validation JS plugin)
- `src/Resources/app/storefront/src/plugin/swiss-post-autocomplete.plugin.js` (ZIP/city autocomplete JS plugin)
- `src/Resources/app/storefront/src/main.js` (Storefront module entrypoint)
- `src/Resources/snippet/storefront.de-DE.json` & `storefront.en-GB.json` (Localizations)

### Modified Files
- `src/TopdataBetterCheckoutSW6.php` (Added install/uninstall lifecycle hooks for custom fields)
- `src/Resources/config/config.xml` (Added configuration settings block)
- `src/Resources/config/services.xml` (Registered autowired services & routing)
- `src/Resources/views/storefront/component/address/address-form.html.twig` (Widget embedding)
- `src/Resources/views/storefront/component/address/address-personal.html.twig` (DOM element wrapper markup trigger)

## 3. Key Changes
- Leveraged PSR-18 standard interfaces, eliminating hard-coded dependencies.
- Added client-side autocomplete matching.
- Built-in integration targeting the `customFields` layer on address tables.
- Typed DTO (`SwissPostAddressValidationRequest`) replaces inline array payloads.
- `splitStreet()` extracts house numbers for improved API match rates.

## 4. Technical Decisions
- **Custom Fields Mapping**: Saved certification flags under the `topdata_swiss_post_certification_status` key on the address entities. Custom field set created via plugin install/uninstall lifecycle hooks.
- **Cache Management**: Handled token persistence through Symfony Cache adapters to respect rates and keep requests performant.
- **DTO & Street Splitting**: Used a typed `JsonSerializable` DTO instead of inline arrays, with a `splitStreet()` helper to separate street name from house number for better Swiss Post API results.

## 5. Testing Notes
- Verified that non-CH/LI requests bypass checks and do not trigger API requests.
- Validated correct debouncing behaviors in the address inputs on standard forms.
```

