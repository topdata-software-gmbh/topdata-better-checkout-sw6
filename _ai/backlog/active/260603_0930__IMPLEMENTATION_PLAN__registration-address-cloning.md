---
filename: "_ai/backlog/active/260603_0930__IMPLEMENTATION_PLAN__registration-address-cloning.md"
title: "Registration Address Cloning: Ensure 2 CustomerAddress Entities Instead of 1"
createdAt: 2026-06-03 09:30
updatedAt: 2026-06-03 09:30
status: draft
priority: high
tags: [checkout, addresses, registration, decorator, sw6.7]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description

In standard Shopware 6.7, when a customer registers and leaves the "Lieferadresse entspricht der Rechnungsadresse" (shipping address equals billing address) checkbox checked, only **one** `customer_address` entity is created. Both `default_billing_address_id` and `default_shipping_address_id` point to this single address. This prevents billing address isolation — the customer can later "swap" their billing address with any other address, and the two default address references share the same row.

The plugin requires that **billing and shipping addresses are always separate entities**, even when they start with identical data. This enables the existing isolation guards (`SetDefaultBillingAddressRouteDecorator`, `ContextSwitchRouteDecorator`, `CustomerAddressIsolationSubscriber`) to function correctly. Without address cloning at registration, the `CustomerAddressIsolationSubscriber` would throw a 403 on any subsequent update because the billing and shipping IDs start out identical.

A partial implementation exists in `RegisterRouteDecorator::splitAddressesAndFlagBilling()`, which clones `billingAddress` → `shippingAddress` when no `shippingAddress` is present in the `RequestDataBag`. However, the current implementation uses PHP `clone` on `RequestDataBag` (shallow copy), lacks a configuration toggle, and has no test coverage. This plan hardens the cloning logic, adds configurability, and ensures correctness across all edge cases.

## 2. Executive Summary

1. **Refactor `splitAddressesAndFlagBilling()`** — Replace PHP `clone` with a proper deep copy via `new RequestDataBag()`, explicitly remove any `id` field from the cloned address, and add a `flagBilling` marker for downstream logic.
2. **Add `cloneBillingAsShipping` configuration** — A boolean config key in `config.xml` (default: `true`) that enables/disables the address cloning behavior per sales channel.
3. **Guard against `RegisterController` removal** — Shopware's `RegisterController::register()` calls `$data->remove('shippingAddress')` when `differentShippingAddress` is absent. Our decorator runs before the core route, but the controller action also runs before the route. Ensure the cloning is applied at the correct point in the request lifecycle.
4. **Add unit tests** — Test the cloning method in isolation with various `RequestDataBag` inputs (with/without shipping address, with/without custom fields, with `always_private`/`always_business` account types).
5. **Update documentation** — SPEC.md, AGENTS.md, and snippet files.

## 3. Project Environment Details

- **Project Name:** TopdataBetterCheckoutSW6
- **Backend root:** `src`
- **PHP Version:** 8.2+ / 8.3+ / 8.4+
- **Shopware Version:** 6.7
- **Symfony Version:** 7.4
- **No JS/CSS/Build Step** — Pure PHP + Twig + JSON snippets

## 4. Implementation Steps

### Phase 1: Refactor `splitAddressesAndFlagBilling()` for Robustness

The current implementation uses PHP `clone` which creates a shallow copy of `RequestDataBag`. This means:
- Nested `DataBag` objects (e.g., `customFields`) are shared references between billing and shipping
- Any mutation of nested objects after cloning would affect both instances
- The `id` field (if present in the form data) would be shared

Replace with a proper deep copy that explicitly constructs a new `RequestDataBag` from the billing address data.

#### [MODIFY] `src/Core/Checkout/Customer/SalesChannel/RegisterRouteDecorator.php`

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractRegisterRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\CustomerResponse;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Contracts\Translation\TranslatorInterface;

class RegisterRouteDecorator extends AbstractRegisterRoute
{
    private const CONFIG_PREFIX = 'TopdataBetterCheckoutSW6.config.';

    public function __construct(
        private readonly AbstractRegisterRoute $decorated,
        private readonly EntityRepository $customerRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator
    ) {
    }

    public function getDecorated(): AbstractRegisterRoute
    {
        return $this->decorated;
    }

    public function register(
        RequestDataBag $data,
        SalesChannelContext $context,
        bool $validateStorefrontUrl = true,
        ?DataValidationDefinition $additionalValidationDefinitions = null
    ): CustomerResponse {
        $isGuest = $data->getBoolean('guest') || !$data->has('password') || empty($data->get('password'));

        if ($isGuest) {
            $this->assertGuestEmailNotRegistered($data, $context);
        }

        $this->enforceAccountType($data, $context, $isGuest);
        $this->cloneBillingAsShippingIfEnabled($data, $context);

        return $this->decorated->register($data, $context, $validateStorefrontUrl, $additionalValidationDefinitions);
    }

    private function cloneBillingAsShippingIfEnabled(RequestDataBag $data, SalesChannelContext $context): void
    {
        $isEnabled = $this->systemConfigService->getBool(
            self::CONFIG_PREFIX . 'cloneBillingAsShipping',
            $context->getSalesChannelId()
        );

        if (!$isEnabled) {
            return;
        }

        $billingAddress = $data->get('billingAddress');
        if (!$billingAddress instanceof RequestDataBag) {
            return;
        }

        if ($data->has('shippingAddress')) {
            return;
        }

        $shippingData = $billingAddress->all();
        unset($shippingData['id']);

        $data->set('shippingAddress', new RequestDataBag($shippingData));
    }

    private function enforceAccountType(RequestDataBag $data, SalesChannelContext $context, bool $isGuest): void
    {
        $configKey = $isGuest ? 'guestAccountType' : 'registrationAccountType';
        $defaultSetting = $isGuest ? 'user_choice' : 'always_business';

        $setting = $this->systemConfigService->getString(
            self::CONFIG_PREFIX . $configKey,
            $context->getSalesChannelId()
        );

        if ($setting === '') {
            $setting = $defaultSetting;
        }

        if ($setting === 'always_private') {
            $data->set('accountType', CustomerEntity::ACCOUNT_TYPE_PRIVATE);
        } elseif ($setting === 'always_business') {
            $data->set('accountType', CustomerEntity::ACCOUNT_TYPE_BUSINESS);
        }

        if ($setting === 'always_private') {
            $data->remove('company');
            $data->remove('vatIds');
            if ($data->has('billingAddress')) {
                $billingAddress = $data->get('billingAddress');
                if ($billingAddress instanceof RequestDataBag) {
                    $billingAddress->remove('company');
                    $billingAddress->remove('vatId');
                }
            }
        }
    }

    private function assertGuestEmailNotRegistered(RequestDataBag $data, SalesChannelContext $context): void
    {
        $email = $data->get('email');
        if (!\is_string($email) || $email === '') {
            return;
        }

        $isBoundToSalesChannel = (bool) $this->systemConfigService->get(
            'core.loginRegistration.isCustomerBoundToSalesChannel',
            $context->getSalesChannelId()
        );

        $criteria = (new Criteria())
            ->setLimit(1)
            ->addFilter(new EqualsFilter('email', $email))
            ->addFilter(new EqualsFilter('guest', false));

        if ($isBoundToSalesChannel) {
            $criteria->addFilter(new EqualsFilter('boundSalesChannelId', $context->getSalesChannelId()));
        }

        $existingCustomer = $this->customerRepository->search($criteria, $context->getContext())->first();
        if (!$existingCustomer instanceof CustomerEntity) {
            return;
        }

        $message = $this->translator->trans('better-checkout.register.emailAlreadyRegistered');

        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null && $request->hasSession()) {
            $session = $request->getSession();
            if (method_exists($session, 'getFlashBag')) {
                $session->getFlashBag()->add('danger', $message);
            }
        }

        $violations = new ConstraintViolationList();
        $violations->add(new ConstraintViolation(
            $message,
            null,
            [],
            null,
            'email',
            $email
        ));

        throw new ConstraintViolationException($violations, $data->all());
    }
}
```

**Key changes from the current code:**

1. **Method renamed** from `splitAddressesAndFlagBilling` to `cloneBillingAsShippingIfEnabled` — the old name was misleading (no "flagging" was happening)
2. **Config-gated** — Only clones when `cloneBillingAsShipping` config is `true`
3. **Deep copy via `new RequestDataBag($billingAddress->all())`** — Instead of PHP `clone`, we extract all data as a plain array and construct a fresh `RequestDataBag`. This ensures no shared references to nested `DataBag` objects
4. **Explicit `unset($shippingData['id'])`** — Defensive removal of any `id` field from the cloned data. While Shopware's `mapAddressData()` doesn't whitelist `id` and generates a new UUID, removing it explicitly prevents any edge case where a form might submit an `id` field
5. **Constants** — Added `CONFIG_PREFIX` for DRY config key access
6. **Order of operations preserved** — `enforceAccountType()` runs BEFORE `cloneBillingAsShippingIfEnabled()`, so if `always_private` strips `company`/`vatId` from the billing address, those fields will also be absent from the shipping clone (since we clone AFTER stripping). This is the correct behavior — both addresses should reflect the same account type constraints

### Phase 2: Add `cloneBillingAsShipping` Configuration

Add a boolean config key to `config.xml` under a new or existing card. Default should be `true` (feature enabled) since the address isolation architecture depends on it.

#### [MODIFY] `src/Resources/config/config.xml`

Add a new `<card>` or extend an existing one with the toggle:

```xml
<!-- Add inside the appropriate <card> element, after existing fields -->
<component name="switch" type="bool">
    <name>cloneBillingAsShipping</name>
    <label>Clone billing as shipping address</label>
    <label lang="de-DE">Rechnungsadresse als Lieferadresse klonen</label>
    <helpText>When enabled, creates two separate address entities even when billing and shipping are identical. Required for billing address isolation.</helpText>
    <helpText lang="de-DE">Wenn aktiviert, werden zwei separate Adressentitäten erstellt, auch wenn Rechnungs- und Lieferadresse identisch sind. Erforderlich für die Rechnungsadressenisolierung.</helpText>
    <defaultValue>true</defaultValue>
</component>
```

### Phase 3: Update Snippet Files

Add snippet keys for the config label/help text in all 5 languages.

#### [MODIFY] `src/Resources/snippet/storefront.en-GB.json`

```json
{
    "TopdataBetterCheckoutSW6": {
        "cloneBillingAsShipping": "Clone billing as shipping address",
        "cloneBillingAsShippingHelp": "When enabled, creates two separate address entities even when billing and shipping are identical. Required for billing address isolation."
    }
}
```

#### [MODIFY] `src/Resources/snippet/storefront.de-DE.json`

```json
{
    "TopdataBetterCheckoutSW6": {
        "cloneBillingAsShipping": "Rechnungsadresse als Lieferadresse klonen",
        "cloneBillingAsShippingHelp": "Wenn aktiviert, werden zwei separate Adressentitäten erstellt, auch wenn Rechnungs- und Lieferadresse identisch sind. Erforderlich für die Rechnungsadressenisolierung."
    }
}
```

#### [MODIFY] `src/Resources/snippet/storefront.fr-FR.json`, `storefront.fr-CH.json`, `storefront.pt-PT.json`

Add corresponding translations for French, Swiss French, and Portuguese.

### Phase 4: Update `services.xml` if Needed

The `RegisterRouteDecorator` service definition already injects `SystemConfigService`, so no changes are needed for the config access. The `getBool()` method on `SystemConfigService` will correctly return the default value (`true`) when the config key has not been explicitly set per sales channel.

**No changes required to `services.xml`.**

### Phase 5: Unit Tests

Create a test class that verifies the address cloning behavior in isolation.

#### [NEW FILE] `tests/Core/Checkout/Customer/SalesChannel/RegisterRouteDecoratorTest.php`

```php
<?php declare(strict_types=1);

namespace Tests\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractRegisterRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\CustomerResponse;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel\RegisterRouteDecorator;

class RegisterRouteDecoratorTest extends TestCase
{
    private AbstractRegisterRoute $decorated;
    private SystemConfigService $systemConfigService;
    private RequestStack $requestStack;
    private TranslatorInterface $translator;
    private RegisterRouteDecorator $sut;

    protected function setUp(): void
    {
        $this->decorated = $this->createMock(AbstractRegisterRoute::class);
        $this->systemConfigService = $this->createMock(SystemConfigService::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->translator = $this->createMock(TranslatorInterface::class);

        $this->systemConfigService->method('getBool')
            ->with(
                $this->stringContains('cloneBillingAsShipping'),
                $this->anything()
            )
            ->willReturn(true);

        $this->systemConfigService->method('getString')
            ->willReturn('user_choice');

        $this->decorated->method('register')
            ->willReturn($this->createMock(CustomerResponse::class));

        $this->sut = new RegisterRouteDecorator(
            $this->decorated,
            $this->createMock(\Shopware\Core\Framework\DataAbstractionLayer\EntityRepository::class),
            $this->systemConfigService,
            $this->requestStack,
            $this->translator
        );
    }

    public function testCloneBillingAsShippingWhenNoShippingAddressProvided(): void
    {
        $data = new RequestDataBag([
            'email' => 'test@example.com',
            'password' => 'SecureP@ss1',
            'billingAddress' => [
                'firstName' => 'Max',
                'lastName' => 'Mustermann',
                'street' => 'Hauptstr. 42',
                'zipcode' => '10115',
                'city' => 'Berlin',
                'countryId' => 'de-country-id',
            ],
        ]);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('test-sales-channel-id');

        $this->sut->register($data, $context);

        $shippingAddress = $data->get('shippingAddress');
        $this->assertInstanceOf(RequestDataBag::class, $shippingAddress, 'shippingAddress should be created from billingAddress clone');
        $this->assertSame('Max', $shippingAddress->get('firstName'));
        $this->assertSame('Mustermann', $shippingAddress->get('lastName'));
        $this->assertSame('Hauptstr. 42', $shippingAddress->get('street'));
        $this->assertSame('10115', $shippingAddress->get('zipcode'));
        $this->assertSame('Berlin', $shippingAddress->get('city'));
        $this->assertNull($shippingAddress->get('id'), 'Cloned shipping address should not have an id');
    }

    public function testDoesNotOverwriteExistingShippingAddress(): void
    {
        $data = new RequestDataBag([
            'email' => 'test@example.com',
            'password' => 'SecureP@ss1',
            'billingAddress' => [
                'firstName' => 'Max',
                'lastName' => 'Mustermann',
                'street' => 'Hauptstr. 42',
                'zipcode' => '10115',
                'city' => 'Berlin',
                'countryId' => 'de-country-id',
            ],
            'shippingAddress' => [
                'firstName' => 'Anna',
                'lastName' => 'Schmidt',
                'street' => 'Nebenstr. 7',
                'zipcode' => '80331',
                'city' => 'München',
                'countryId' => 'de-country-id',
            ],
        ]);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('test-sales-channel-id');

        $this->sut->register($data, $context);

        $shippingAddress = $data->get('shippingAddress');
        $this->assertSame('Anna', $shippingAddress->get('firstName'), 'Existing shippingAddress should NOT be overwritten by clone');
        $this->assertSame('Schmidt', $shippingAddress->get('lastName'));
        $this->assertSame('Nebenstr. 7', $shippingAddress->get('street'));
    }

    public function testClonedShippingAddressIsIndependentFromBillingAddress(): void
    {
        $data = new RequestDataBag([
            'email' => 'test@example.com',
            'password' => 'SecureP@ss1',
            'billingAddress' => [
                'firstName' => 'Max',
                'lastName' => 'Mustermann',
                'street' => 'Hauptstr. 42',
                'zipcode' => '10115',
                'city' => 'Berlin',
                'countryId' => 'de-country-id',
                'company' => 'Test GmbH',
            ],
        ]);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('test-sales-channel-id');

        $this->sut->register($data, $context);

        $billingAddress = $data->get('billingAddress');
        $shippingAddress = $data->get('shippingAddress');

        $billingAddress->set('city', 'Hamburg');
        $this->assertSame('Berlin', $shippingAddress->get('city'), 'Modifying billingAddress should NOT affect the cloned shippingAddress');
    }

    public function testCloneDisabledDoesNotCreateShippingAddress(): void
    {
        $configService = $this->createMock(SystemConfigService::class);
        $configService->method('getBool')->willReturn(false);
        $configService->method('getString')->willReturn('user_choice');

        $sut = new RegisterRouteDecorator(
            $this->decorated,
            $this->createMock(\Shopware\Core\Framework\DataAbstractionLayer\EntityRepository::class),
            $configService,
            $this->requestStack,
            $this->translator
        );

        $data = new RequestDataBag([
            'email' => 'test@example.com',
            'password' => 'SecureP@ss1',
            'billingAddress' => [
                'firstName' => 'Max',
                'lastName' => 'Mustermann',
                'street' => 'Hauptstr. 42',
                'zipcode' => '10115',
                'city' => 'Berlin',
                'countryId' => 'de-country-id',
            ],
        ]);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('test-sales-channel-id');

        $sut->register($data, $context);

        $this->assertFalse($data->has('shippingAddress'), 'shippingAddress should NOT be created when cloning is disabled');
    }

    public function testAlwaysPrivateStripsCompanyBeforeCloning(): void
    {
        $configService = $this->createMock(SystemConfigService::class);
        $configService->method('getBool')->willReturn(true);
        $configService->method('getString')
            ->willReturnCallback(function (string $key) {
                if (str_contains($key, 'registrationAccountType') || str_contains($key, 'guestAccountType')) {
                    return 'always_private';
                }
                return '';
            });

        $sut = new RegisterRouteDecorator(
            $this->decorated,
            $this->createMock(\Shopware\Core\Framework\DataAbstractionLayer\EntityRepository::class),
            $configService,
            $this->requestStack,
            $this->translator
        );

        $data = new RequestDataBag([
            'email' => 'test@example.com',
            'password' => 'SecureP@ss1',
            'billingAddress' => [
                'firstName' => 'Max',
                'lastName' => 'Mustermann',
                'street' => 'Hauptstr. 42',
                'zipcode' => '10115',
                'city' => 'Berlin',
                'countryId' => 'de-country-id',
                'company' => 'Test GmbH',
                'vatId' => 'DE123456789',
            ],
        ]);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('test-sales-channel-id');

        $sut->register($data, $context);

        $shippingAddress = $data->get('shippingAddress');
        $this->assertInstanceOf(RequestDataBag::class, $shippingAddress);
        $this->assertNull($shippingAddress->get('company'), 'company should be stripped from clone when always_private');
        $this->assertNull($shippingAddress->get('vatId'), 'vatId should be stripped from clone when always_private');
    }

    public function testIdFieldRemovedFromClone(): void
    {
        $data = new RequestDataBag([
            'email' => 'test@example.com',
            'password' => 'SecureP@ss1',
            'billingAddress' => [
                'id' => 'preexisting-id-123',
                'firstName' => 'Max',
                'lastName' => 'Mustermann',
                'street' => 'Hauptstr. 42',
                'zipcode' => '10115',
                'city' => 'Berlin',
                'countryId' => 'de-country-id',
            ],
        ]);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('test-sales-channel-id');

        $this->sut->register($data, $context);

        $shippingAddress = $data->get('shippingAddress');
        $this->assertInstanceOf(RequestDataBag::class, $shippingAddress);
        $this->assertNull($shippingAddress->get('id'), 'id field must be explicitly removed from the cloned shipping address');
    }

    public function testNoCloneWhenNoBillingAddress(): void
    {
        $data = new RequestDataBag([
            'email' => 'test@example.com',
            'password' => 'SecureP@ss1',
        ]);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('test-sales-channel-id');

        $this->sut->register($data, $context);

        $this->assertFalse($data->has('shippingAddress'), 'shippingAddress should NOT be created when billingAddress is absent');
    }
}
```

### Phase 6: Update SPEC.md

Add documentation for the address cloning feature and the new configuration key.

#### [MODIFY] `_ai/SPEC.md`

Add to Section 2.5 (Isolated Billing & Shipping Addresses):

```markdown
### 2.5 Isolated Billing & Shipping Addresses (Address Splitting)

- **When no separate shipping address is provided** (customer leaves the "shipping = billing" checkbox checked), the billing address is **cloned** into a distinct `shippingAddress` entry in the `RequestDataBag`
- Creates two separate `customer_address` database rows instead of sharing one address entity
- Cloning is gated by the `cloneBillingAsShipping` configuration key (default: `true`)
- When disabled, Shopware's default behavior applies (single shared address for billing + shipping)
- The cloned address is a **deep copy** — no shared references between billing and shipping `RequestDataBag` instances
- The `id` field is explicitly removed from the clone to prevent ID collisions
- Operates **before** `RegisterRoute::register()` processes the data, so Shopware's core creates two independent `customer_address` entities with unique UUIDs
- **Order of operations**: `enforceAccountType()` runs first (may strip `company`/`vatId`), then `cloneBillingAsShippingIfEnabled()` runs on the already-modified data. This ensures private accounts don't leak company info into the cloned shipping address.
```

Add to Section 4 (Configuration Summary):

```markdown
| Address Cloning | `cloneBillingAsShipping` | `true` | `true`, `false` |
```

### Phase 7: Update AGENTS.md

Add the new config key to the Configuration table.

#### [MODIFY] `AGENTS.md`

Update the Configuration table:

```markdown
| `cloneBillingAsShipping` | `true` | `true` / `false` |
```

Add note about order of operations:

```markdown
- **RegisterRouteDecorator execution order**: `assertGuestEmailNotRegistered()` → `enforceAccountType()` → `cloneBillingAsShippingIfEnabled()` → delegate to decorated route. The order matters because `enforceAccountType()` may strip company/vatId before cloning.
```

### Phase 8: Implementation Report

Write the execution report to `_ai/backlog/reports/{YYMMDD_HHmm}__IMPLEMENTATION_REPORT__registration-address-cloning.md`.

```yaml
---
filename: "_ai/backlog/reports/{YYMMDD_HHmm}__IMPLEMENTATION_REPORT__registration-address-cloning.md"
title: "Report: Registration Address Cloning"
createdAt: YYYY-MM-DD HH:mm
updatedAt: YYYY-MM-DD HH:mm
planFile: "_ai/backlog/active/260603_0930__IMPLEMENTATION_PLAN__registration-address-cloning.md"
project: "TopdataBetterCheckoutSW6"
status: completed
filesCreated: 1
filesModified: 5
filesDeleted: 0
tags: [checkout, addresses, registration, decorator, sw6.7]
documentType: IMPLEMENTATION_REPORT
---
```

## 5. Technical Considerations

### 5.1 RegisterController Interaction

Shopware's `RegisterController::register()` (line ~155-157) contains this logic:

```php
if (!$data->has('differentShippingAddress')) {
    $data->remove('shippingAddress');
}
```

This runs **before** the decorated `RegisterRoute::register()` is called. Since our decorator also runs before the core route (it decorates the route itself, not the controller), we need to verify the execution order:

1. `RegisterController::register()` is called
2. Controller checks `differentShippingAddress` flag
3. If not present, `$data->remove('shippingAddress')` removes any shipping address data
4. Controller calls `$this->registerRoute->register($data, ...)`
5. Our `RegisterRouteDecorator::register()` is invoked
6. Our decorator clones `billingAddress` → `shippingAddress`
7. Our decorator delegates to the core `RegisterRoute::register()`
8. Core route processes both addresses

**Result**: Our cloning happens AFTER the controller's removal, so the cloning is not undone. This is correct because the decorator wraps the core route, not the controller.

### 5.2 Shallow vs Deep Copy

PHP's `clone` on `RequestDataBag` creates a shallow copy. The `$parameters` array is copied, but nested `DataBag` objects (like `customFields`) are references. Using `$billingAddress->all()` + `new RequestDataBag()` creates a truly independent copy because `all()` returns a plain array, and `new RequestDataBag()` constructs fresh nested `DataBag` instances.

### 5.3 SystemConfigService::getBool() Behavior

`SystemConfigService::getBool()` returns the config value as a boolean. When the config key has never been set, it returns the `defaultValue` from `config.xml` (`true`). This means the feature is enabled by default, and merchants must explicitly disable it if they want standard Shopware behavior.

### 5.4 v6.7+ Compatibility

In Shopware 6.7+, `firstName` and `lastName` from `billingAddress` bubble up to the top-level data. Our decorator runs before this bubbling happens, so the clone will also contain `firstName`/`lastName`. Since `mapAddressData()` whitelists these fields, they will be correctly processed for both addresses.

The trunk version of `RegisterRoute` also sets `$shippingAddress->set('salutationId', ...)` only when the shipping salutationId is empty. Since our clone copies all billing fields including `salutationId`, the trunk code will skip this step (which is correct — both addresses should have the same salutation).

### 5.5 CustomerAddressIsolationSubscriber Compatibility

The `CustomerAddressIsolationSubscriber` blocks any `UPDATE` that would set `default_billing_address_id === default_shipping_address_id`. Since our cloning creates two distinct `customer_address` rows with different UUIDs, the IDs will never be equal, and the subscriber won't interfere with registration.

However, if `cloneBillingAsShipping` is disabled and a customer registers with billing = shipping, the `CustomerAddressIsolationSubscriber` will block the initial `INSERT` because Shopware sets both `default_billing_address_id` and `default_shipping_address_id` to the same ID. **This is intentional** — the isolation architecture requires separate addresses. Disabling cloning effectively breaks isolation.

### 5.6 Custom Fields

If the billing address form includes custom fields (e.g., `billingAddress[customFields][my_field]`), these will be present in the cloned `RequestDataBag`. Shopware's `mapAddressData()` handles custom fields via `$this->customFieldMapper->map()`, which processes them independently for each address. The deep copy ensures that nested `customFields` DataBags are not shared references.

## 6. Acceptance Criteria

1. **When the "shipping = billing" checkbox is left checked during registration**, two separate `customer_address` entities are created with identical data but different UUIDs
2. **When the customer provides a separate shipping address**, the existing shipping address data is preserved (no cloning overwrites it)
3. **When `cloneBillingAsShipping` is disabled**, Shopware's default behavior applies (single shared address)
4. **The cloned shipping address has no `id` field**, ensuring Shopware generates a new UUID
5. **Modifications to the billing `RequestDataBag` after cloning do not affect the shipping `RequestDataBag`** (deep copy, no shared references)
6. **`always_private` account type strips `company`/`vatId` from both addresses** (enforcement runs before cloning)
7. **The `CustomerAddressIsolationSubscriber` does not block registration** when cloning is enabled
8. **All 5 snippet languages** have translations for the new config key
9. **Unit tests** cover all 7 scenarios defined in Phase 5