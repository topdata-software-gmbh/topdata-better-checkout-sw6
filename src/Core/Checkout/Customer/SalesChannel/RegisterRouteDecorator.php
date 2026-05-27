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

        return $this->decorated->register($data, $context, $validateStorefrontUrl, $additionalValidationDefinitions);
    }

    private function enforceAccountType(RequestDataBag $data, SalesChannelContext $context, bool $isGuest): void
    {
        $configKey = $isGuest ? 'guestAccountType' : 'registrationAccountType';
        $defaultSetting = $isGuest ? 'user_choice' : 'always_business';

        $setting = $this->systemConfigService->getString(
            'TopdataBetterCheckoutSW6.config.' . $configKey,
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
