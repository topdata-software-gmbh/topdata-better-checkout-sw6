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
