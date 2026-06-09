---
filename: "_ai/backlog/active/260609_1200__IMPLEMENTATION_PLAN__async-swiss-post-validation.md"
title: "Replace synchronous Swiss Post validation with async message queue processing"
createdAt: 2026-06-09 12:00
updatedAt: 2026-06-09 12:00
status: completed
completedAt: 2026-06-09 21:33
priority: high
tags: [swiss-post, messaging, async, address-validation, worker]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

# Problem

The `AddressValidationSubscriber` adds a synchronous Symfony `Callback` constraint on `zipcode` during `customer.create`, `customer.update`, `address.create`, and `address.update` validation events. This constraint calls the Swiss Post API in real time. If the API is unreachable, slow, or returns an error, the entire registration or address save fails with a validation error.

Additionally, `AddressCertificationSubscriber` makes a synchronous Swiss Post API call on every `customer_address.written` event to determine and store the certification quality in `custom_fields[topdata_swiss_post_certification_status]`. Same problem — API downtime blocks address persistence.

The `custom_fields` already stores granular Swiss Post quality states (`CERTIFIED`, `DOMICILE_CERTIFIED`, `USABLE`, `UNUSABLE`, `FIXED`, `INVALID`, `NULL`), but this data is populated in a fragile, synchronous way.

# Solution

1. **Remove** all synchronous Swiss Post API calls from the validation layer — the Symfony `Callback` constraint on `zipcode` is deleted entirely.
2. **Dispatch** a Symfony Messenger message (`ValidateAddressMessage`) from the `AddressCertificationSubscriber` instead of calling the API synchronously.
3. **Handle** the message in a new `ValidateAddressHandler` that calls the Swiss Post API and writes the quality back to `custom_fields`.
4. The **frontend Ajax validation** (`swiss-post-validator.plugin.js` + `SwissPostStorefrontController::validate`) remains unchanged — it provides non-blocking real-time feedback to the user without blocking the save.

This means **saves never fail** due to Swiss Post API issues. Quality data is populated in the background by the message queue worker (`bin/console messenger:consume`).

# Project Environment

- **Project Name**: SW6.7 Plugin — topdata-better-checkout-sw6
- **Backend root**: `src`
- **PHP Version**: 8.2+
- **Symfony**: 7.4
- **Messaging**: Symfony Messenger (`Symfony\Component\Messenger\MessageBusInterface`, `#[AsMessageHandler]`)

# Phases

## Phase 1 — Remove Swiss Post validation from AddressValidationSubscriber

**Goal**: Strip all Swiss Post API calls from the validation event subscriber. Keep the company field validation (`applyValidationRules`) intact.

### Files Changed

#### [MODIFY] `src/Core/Checkout/Customer/Subscriber/AddressValidationSubscriber.php`

**Remove:**
- `use Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\SwissPostApiService;`
- `use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;`
- `use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;`
- `use Symfony\Component\Validator\Constraints\Callback;`
- `use Symfony\Component\Validator\Context\ExecutionContextInterface;`
- Constructor parameter: `SwissPostApiService $swissPostApiService` and `EntityRepository $countryRepository`
- Calls to `$this->applySwissPostValidation(...)` on lines 59-61 and 76-78 and 108
- Entire `applySwissPostValidation()` method (lines 128-183)

**Keep:**
- Company field validation (`applyValidationRules`, `removeConstraint`, `addConstraintIfNotExists`)
- `SystemConfigService`, `RequestStack`, `customer.repository` (now unused — remove `country.repository` from constructor)

**After cleanup, the constructor should look like:**
```php
public function __construct(
    private readonly SystemConfigService $systemConfigService,
    private readonly RequestStack $requestStack,
) {
}
```

#### [MODIFY] `src/Resources/config/services.xml`

**Replace** the `AddressValidationSubscriber` service registration (lines 90-96):

```xml
<service id="Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber\AddressValidationSubscriber">
    <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService"/>
    <argument type="service" id="Symfony\Component\HttpFoundation\RequestStack"/>
    <tag name="kernel.event_subscriber"/>
</service>
```


## Phase 2 — Create async message and handler

**Goal**: Create `ValidateAddressMessage` (carrying `addressId`) and `ValidateAddressHandler` (calls Swiss Post API, writes quality).

### Files Created

#### [NEW FILE] `src/Message/ValidateAddressMessage.php`

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Message;

class ValidateAddressMessage
{
    public function __construct(
        private readonly string $addressId,
        private readonly ?string $salesChannelId = null,
    ) {
    }

    public function getAddressId(): string
    {
        return $this->addressId;
    }

    public function getSalesChannelId(): ?string
    {
        return $this->salesChannelId;
    }
}
```

#### [NEW FILE] `src/Message/ValidateAddressHandler.php`

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Message;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\SwissPostApiService;

#[AsMessageHandler]
class ValidateAddressHandler
{
    public const METADATA_KEY = 'topdata_swiss_post_certification_status';

    public function __construct(
        private readonly SwissPostApiService $apiService,
        private readonly EntityRepository $customerAddressRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ValidateAddressMessage $message): void
    {
        $addressId = $message->getAddressId();
        $context = Context::createDefaultContext();

        try {
            $criteria = new Criteria([$addressId]);
            $criteria->addAssociation('country');
            $address = $this->customerAddressRepository->search($criteria, $context)->first();

            if (!$address || !$address->getCountry()) {
                $this->logger->warning('ValidateAddressHandler: address not found or missing country', ['addressId' => $addressId]);
                return;
            }

            $iso = $address->getCountry()->getIso();
            if ($iso !== 'CH' && $iso !== 'LI') {
                return;
            }

            $validation = $this->apiService->validateAddress([
                'firstName' => $address->getFirstName(),
                'lastName' => $address->getLastName(),
                'street' => $address->getStreet(),
                'zipcode' => $address->getZipcode(),
                'city' => $address->getCity(),
                'countryCode' => $iso,
            ], $message->getSalesChannelId());

            $quality = $validation['quality'] ?? ($validation['success'] ? 'UNKNOWN' : 'INVALID');

            $customFields = $address->getCustomFields() ?? [];
            $customFields[self::METADATA_KEY] = $quality;

            $this->customerAddressRepository->update([
                [
                    'id' => $addressId,
                    'customFields' => $customFields,
                ],
            ], $context);

            $this->logger->info('ValidateAddressHandler: address quality updated', [
                'addressId' => $addressId,
                'quality' => $quality,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('ValidateAddressHandler: exception', [
                'addressId' => $addressId,
                'error' => $e->getMessage(),
            ]);

            // Re-throw to let Symfony Messenger handle retry logic
            throw $e;
        }
    }
}
```


## Phase 3 — Refactor AddressCertificationSubscriber to dispatch message

**Goal**: Replace the synchronous Swiss Post API call with dispatching a `ValidateAddressMessage` to the message bus.

### Files Changed

#### [MODIFY] `src/Core/Checkout/Customer/Subscriber/AddressCertificationSubscriber.php`

**Changes:**
- Remove `SwissPostApiService` from constructor, add `MessageBusInterface`
- Replace the API call + direct `customerAddressRepository->update()` with a message dispatch
- Keep the CH/LI country filtering and existing-status check

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\SwissPostApiService;
use Topdata\TopdataBetterCheckoutSW6\Message\ValidateAddressMessage;

class AddressCertificationSubscriber implements EventSubscriberInterface
{
    public const METADATA_KEY = 'topdata_swiss_post_certification_status';

    public function __construct(
        private readonly SwissPostApiService $apiService,
        private readonly EntityRepository $customerAddressRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'customer_address.written' => 'onAddressWritten',
        ];
    }

    public function onAddressWritten(EntityWrittenEvent $event): void
    {
        $salesChannelId = null;

        if (!$this->apiService->isValidationEnabled($salesChannelId)) {
            return;
        }

        foreach ($event->getWriteResults() as $result) {
            $payload = $result->getPayload();
            $addressId = $payload['id'] ?? null;

            if (!$addressId) {
                continue;
            }

            // Skip if a status is already being set in this write
            if (array_key_exists('customFields', $payload) && isset($payload['customFields'][self::METADATA_KEY])) {
                continue;
            }

            // Fetch address to check country (CH/LI only)
            $criteria = new Criteria([$addressId]);
            $criteria->addAssociation('country');
            /** @var CustomerAddressEntity|null $addressEntity */
            $addressEntity = $this->customerAddressRepository->search($criteria, $event->getContext())->first();

            if (!$addressEntity || !$addressEntity->getCountry()) {
                continue;
            }

            $iso = $addressEntity->getCountry()->getIso();
            if ($iso !== 'CH' && $iso !== 'LI') {
                continue;
            }

            // Dispatch async message instead of calling API synchronously
            $this->messageBus->dispatch(new ValidateAddressMessage(
                addressId: $addressId,
                salesChannelId: $salesChannelId,
            ));
        }
    }
}
```

#### [MODIFY] `src/Resources/config/services.xml`

**Replace** the `AddressCertificationSubscriber` registration (lines 145-150):

```xml
<!-- Address Certification Subscriber (dispatches async validation message) -->
<service id="Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber\AddressCertificationSubscriber">
    <argument type="service" id="Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\SwissPostApiService"/>
    <argument type="service" id="customer_address.repository"/>
    <argument type="service" id="messenger.default_bus"/>
    <tag name="kernel.event_subscriber"/>
</service>
```

**Add** the message handler registration after the Address Certification Subscriber:

```xml
<!-- ASYNC MESSAGE HANDLER -->
<service id="Topdata\TopdataBetterCheckoutSW6\Message\ValidateAddressHandler" autowire="true">
    <tag name="messenger.message_handler"/>
</service>
```


## Phase 4 — Preserve frontend Ajax validation

**No changes needed.** The storefront `SwissPostStorefrontController::validate()` endpoint and `swiss-post-validator.plugin.js` remain unchanged. They provide non-blocking real-time feedback to the user. The controller checks `isValidationEnabled()` before processing and returns JSON — it never blocks the address save.

The `swiss-post-widget.html.twig` continues to display the certification status read from `custom_fields[topdata_swiss_post_certification_status]`, which will now be populated asynchronously by the worker.

### Files Unchanged

- `src/Controller/SwissPostStorefrontController.php`
- `src/Resources/app/storefront/src/plugin/swiss-post-validator.plugin.js`
- `src/Resources/app/storefront/src/plugin/swiss-post-autocomplete.plugin.js`
- `src/Resources/views/storefront/component/address/swiss-post-widget.html.twig`
- `src/Resources/views/storefront/component/address/address-form.html.twig`


## Phase 5 — Update documentation

#### [MODIFY] `_ai/SPEC.md`

Update the **Event Subscribers** table to mention the async certification subscriber. Add a new section describing the async validation flow:

```markdown
### 2.11 Asynchronous Swiss Post Address Validation
- **Registration/address save NEVER blocks** on Swiss Post API calls
- `AddressCertificationSubscriber` dispatches `ValidateAddressMessage` via Symfony Messenger
- `ValidateAddressHandler` processes the message asynchronously:
  - Calls Swiss Post DCAPI validation endpoint
  - Stores the quality result (`CERTIFIED`, `DOMICILE_CERTIFIED`, `USABLE`, `UNUSABLE`, `FIXED`, `INVALID`) in `customer_address.custom_fields[topdata_swiss_post_certification_status]`
- If the API is unreachable, the message is retried by Symfony Messenger's retry strategy
- The storefront Ajax validation endpoint (`SwissPostStorefrontController::validate`) remains for **non-blocking real-time feedback** only

#### Running the worker
```bash
bin/console messenger:consume async -vv
```
```

**Update the Event Subscribers table** to reflect the new architecture:

| Subscriber | Events | Notes |
|---|---|---|
| `AddressValidationSubscriber` | `framework.validation.customer.create/update`, `framework.validation.address.create/update` | Company field validation only (Swiss Post validation removed) |
| `AddressCertificationSubscriber` | `customer_address.written` | Dispatches `ValidateAddressMessage` for async processing |

Add a new table under a **Messages** section:

### Messages

| Message | Handler | Purpose |
|---|---|---|
| `ValidateAddressMessage` | `ValidateAddressHandler` | Validates address via Swiss Post DCAPI and stores quality in `custom_fields` |

#### [MODIFY] `AGENTS.md`

Update the **Architecture** section to note the async messaging addition.


## Phase 6 — Implementation report

After all changes are implemented, generate the report to:
`_ai/backlog/reports/260609_1200__IMPLEMENTATION_REPORT__async-swiss-post-validation.md`

The report should verify:
1. `AddressValidationSubscriber` no longer references `SwissPostApiService` or `country.repository`
2. `ValidateAddressMessage` and `ValidateAddressHandler` exist in `src/Message/`
3. `AddressCertificationSubscriber` dispatches a message instead of calling the API synchronously
4. `services.xml` is updated for all three service changes
5. Running `bin/console messenger:consume async -vv` processes queued validations
6. Frontend Ajax validation still works as before

# Summary of All File Changes

| Action | File |
|---|---|
| MODIFY | `src/Core/Checkout/Customer/Subscriber/AddressValidationSubscriber.php` |
| MODIFY | `src/Core/Checkout/Customer/Subscriber/AddressCertificationSubscriber.php` |
| NEW | `src/Message/ValidateAddressMessage.php` |
| NEW | `src/Message/ValidateAddressHandler.php` |
| MODIFY | `src/Resources/config/services.xml` |
| MODIFY | `_ai/SPEC.md` |
| MODIFY | `AGENTS.md` |
