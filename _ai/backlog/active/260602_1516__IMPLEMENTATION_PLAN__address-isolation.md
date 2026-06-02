---
filename: "_ai/backlog/active/{YYMMDD_HHmm}__IMPLEMENTATION_PLAN__address-isolation.md"
title: "Implement Billing and Shipping Address Isolation"
createdAt: 2026-06-02 15:13
updatedAt: 2026-06-02 15:13
status: draft
priority: high
tags: [checkout, addresses, api-validation, storefront, sw6.7]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description
Currently, the checkout address management allows using a billing address as a shipping address and vice versa via the context menus in the storefront. The requirement is full isolation between billing and shipping addresses. This requires hiding specific options in the UI context menus ("Als Standard-Lieferadresse verwenden" for billing addresses and "Als Standard-Rechnungsadresse verwenden" for shipping addresses) and strictly enforcing this rule at the API level (returning a 403 Forbidden error if an API call attempts to set both default addresses to the same ID).

## 2. Executive Summary
This plan details the implementation of full address isolation:
1.  **API Level Validation:** Introduce a DAL `PreWriteValidationEvent` subscriber (`CustomerAddressIsolationSubscriber`). This subscriber will intercept any `UpdateCommand` for the `CustomerDefinition` and prevent the `default_billing_address_id` and `default_shipping_address_id` from being set to the same value, throwing an `AccessDeniedHttpException` (403). This elegantly guards Store-API, Admin-API, and Storefront operations simultaneously.
2.  **Storefront UI Adjustments:** Modify the plugin's Twig templates that render the address context menus. We will locate the specific dropdown implementations and remove the cross-referencing actions to ensure complete UI isolation.

## 3. Project Environment Details
- **Project Name:** SW6.7 Plugin (TopdataBetterCheckoutSW6)
- **Backend root:** `src`
- **PHP Version:** 8.2+
- **Shopware Version:** 6.7

## 4. Implementation Steps

### Phase 1: API Level Enforcement (DAL Validator)

We will create an event subscriber to intercept the DAL pre-write events. By using `Doctrine\DBAL\Connection`, we can efficiently perform this check before any data is written without bloating the DAL overhead.

```php
// [NEW FILE] src/Subscriber/CustomerAddressIsolationSubscriber.php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

#[AutoconfigureTag('kernel.event_subscriber')]
class CustomerAddressIsolationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PreWriteValidationEvent::class => 'onPreWriteValidate'
        ];
    }

    public function onPreWriteValidate(PreWriteValidationEvent $event): void
    {
        foreach ($event->getCommands() as $command) {
            if (!$command instanceof UpdateCommand || $command->getDefinition()->getClass() !== CustomerDefinition::class) {
                continue;
            }

            $payload = $command->getPayload();
            $hasBillingChange = array_key_exists('default_billing_address_id', $payload);
            $hasShippingChange = array_key_exists('default_shipping_address_id', $payload);

            if (!$hasBillingChange && !$hasShippingChange) {
                continue;
            }

            $customerIdBytes = $command->getPrimaryKey()['id'];

            // Fetch current addresses from DB efficiently
            $currentData = $this->connection->fetchAssociative(
                'SELECT default_billing_address_id, default_shipping_address_id FROM customer WHERE id = :id',
                ['id' => $customerIdBytes]
            );

            if (!$currentData) {
                continue;
            }

            $newBillingIdBytes = $hasBillingChange ? $payload['default_billing_address_id'] : $currentData['default_billing_address_id'];
            $newShippingIdBytes = $hasShippingChange ? $payload['default_shipping_address_id'] : $currentData['default_shipping_address_id'];

            // Check if both are set and identical
            if ($newBillingIdBytes !== null && $newShippingIdBytes !== null && $newBillingIdBytes === $newShippingIdBytes) {
                // Throws a 403 Forbidden which will be seamlessly handled by the Store API and Storefront exception handlers
                throw new AccessDeniedHttpException('Setting default billing address same as shipping address (or vice-versa) is strictly forbidden due to address isolation rules.');
            }
        }
    }
}
```

### Phase 2: Storefront UI Adjustments

The implementing agent will need to search the plugin's Twig templates for the context menu definitions and remove the unwanted options based on context.

1.  **Search Strategy**: 
    - Search for `"frontend.account.address.set-default-address"` or snippet `account.addressesSetAsDefaultShippingAction` / `account.addressesSetAsDefaultBillingAction` inside `src/Resources/views/storefront/`.
    - If the Topdata plugin uses custom templates to isolate the lists (as implied by the screenshots), you should manually remove the `<li>` containing the unwanted cross-link forms in those exact templates.

2.  **Standard Override (Fallback Method)**: 
    If the plugin uses standard Shopware core blocks, inject the following template override to safely hide them from context menus dynamically:

```twig
{# [NEW FILE] src/Resources/views/storefront/component/address/address-item.html.twig #}
{% sw_extends '@Storefront/storefront/component/address/address-item.html.twig' %}

{# Remove option to set as default shipping for billing addresses #}
{% block component_address_item_content_action_set_default_shipping %}
    {# Ensure it is completely hidden if it is the default billing address #}
    {% if not (address.id == context.customer.defaultBillingAddressId) %}
        {{ parent() }}
    {% endif %}
{% endblock %}

{# Remove option to set as default billing for shipping addresses #}
{% block component_address_item_content_action_set_default_billing %}
    {# Ensure it is completely hidden if it is the default shipping address #}
    {% if not (address.id == context.customer.defaultShippingAddressId) %}
        {{ parent() }}
    {% endif %}
{% endblock %}
```
*(Agent Instruction: Please inspect existing customized lists. If the lists "VERFÜGBARE LIEFERADRESSEN" and "Rechnungsadresse" have dedicated loop templates, simply remove the form block generating the opposing option entirely without the conditional statement).*

## 5. Report Template
The agent will write the execution report into `_ai/backlog/reports/{YYMMDD_HHmm}__IMPLEMENTATION_REPORT__address-isolation.md` following the instructed structure, including:

```yaml
---
filename: "_ai/backlog/reports/{YYMMDD_HHmm}__IMPLEMENTATION_REPORT__address-isolation.md"
title: "Report: Implement Billing and Shipping Address Isolation"
createdAt: YYYY-MM-DD HH:mm
updatedAt: YYYY-MM-DD HH:mm
planFile: "_ai/backlog/active/{YYMMDD_HHmm}__IMPLEMENTATION_PLAN__address-isolation.md"
project: "TopdataBetterCheckoutSW6"
status: completed
filesCreated: 0
filesModified: 0
filesDeleted: 0
tags: [checkout, addresses, api-validation, storefront, sw6.7]
documentType: IMPLEMENTATION_REPORT
---
```
Ensure you include any deviations or custom twig changes identified during the code scan in the report.
```

