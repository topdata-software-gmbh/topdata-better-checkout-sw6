<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Controller;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\Exception\AddressNotFoundException;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractListAddressRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractUpsertAddressRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\Country\SalesChannel\AbstractCountryRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Salutation\SalesChannel\AbstractSalutationRoute;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestService;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class BillingAddressEditController extends StorefrontController
{
    public function __construct(
        private readonly AbstractListAddressRoute $listAddressRoute,
        private readonly AbstractUpsertAddressRoute $upsertAddressRoute,
        private readonly AbstractCountryRoute $countryRoute,
        private readonly AbstractSalutationRoute $salutationRoute,
        private readonly CompanyNameChangeRequestService $companyNameChangeRequestService,
    ) {
    }

    #[Route(
        path: '/widgets/checkout/billing-address-edit/{addressId}',
        name: 'frontend.checkout.billing-address.edit.get',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true, '_loginRequired' => true],
        methods: ['GET']
    )]
    public function getBillingAddressForm(
        string $addressId,
        SalesChannelContext $context,
        CustomerEntity $customer,
    ): Response {
        $address = $this->getCustomerAddress($addressId, $context, $customer);
        $page = $this->getPageWithCountries($context);

        $hasPendingRequest = $this->companyNameChangeRequestService->hasPendingChangeRequest(
            $customer->getId(),
            $addressId,
            $context->getContext()
        );

        $response = $this->renderStorefront(
            '@TopdataBetterCheckoutSW6/storefront/component/address/billing-address-edit-modal.html.twig',
            [
                'address' => $address,
                'page' => $page,
                'hasPendingCompanyNameChange' => $hasPendingRequest,
            ],
        );
        $response->headers->set('x-robots-tag', 'noindex');
        return $response;
    }

    #[Route(
        path: '/widgets/checkout/billing-address-edit/{addressId}',
        name: 'frontend.checkout.billing-address.edit.save',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true, '_loginRequired' => true],
        methods: ['POST']
    )]
    public function saveBillingAddress(
        string $addressId,
        RequestDataBag $data,
        SalesChannelContext $context,
        CustomerEntity $customer,
    ): Response {
        $address = $this->getCustomerAddress($addressId, $context, $customer);

        /** @var RequestDataBag $addressData */
        $addressData = $data->get('address');
        $addressData->set('id', $addressId);

        $newCompanyName = $addressData->get('company', $address->getCompany() ?? '');
        $oldCompanyName = $address->getCompany() ?? '';

        if ($newCompanyName !== $oldCompanyName && trim($newCompanyName) !== '') {
            $this->companyNameChangeRequestService->createChangeRequest(
                $customer->getId(),
                $addressId,
                $oldCompanyName,
                $newCompanyName,
                $context->getContext()
            );

            $addressData->remove('company');

            if ($addressData->count() === 1 && $addressData->has('id')) {
                return $this->redirectToRoute('frontend.checkout.confirm.page');
            }
        }

        try {
            $this->upsertAddressRoute->upsert(
                $addressId,
                $addressData->toRequestDataBag(),
                $context,
                $customer,
            );

            return $this->redirectToRoute('frontend.checkout.confirm.page');
        } catch (ConstraintViolationException $formViolations) {
            $address = $this->getCustomerAddress($addressId, $context, $customer);
            $page = $this->getPageWithCountries($context);
            $response = $this->renderStorefront(
                '@TopdataBetterCheckoutSW6/storefront/component/address/billing-address-edit-modal.html.twig',
                [
                    'address' => $address,
                    'page' => $page,
                    'formViolations' => $formViolations,
                    'postedData' => $addressData,
                ],
            );
            $response->setStatusCode(422);
            $response->headers->set('x-robots-tag', 'noindex');
            return $response;
        }
    }

    private function getPageWithCountries(SalesChannelContext $context): array
    {
        $criteria = (new Criteria())
            ->addSorting(new FieldSorting('position', FieldSorting::ASCENDING))
            ->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));

        $countries = $this->countryRoute->load(new Request(), $criteria, $context)->getCountries();
        $salutations = $this->salutationRoute->load(new Request(), $context, new Criteria())->getSalutations();
        $salutations->sort(fn ($a, $b) => $b->getSalutationKey() <=> $a->getSalutationKey());

        return [
            'countries' => $countries,
            'salutations' => $salutations,
        ];
    }

    private function getCustomerAddress(
        string $addressId,
        SalesChannelContext $context,
        CustomerEntity $customer,
    ): CustomerAddressEntity {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $addressId));
        $criteria->addFilter(new EqualsFilter('customerId', $customer->getId()));

        $address = $this->listAddressRoute
            ->load($criteria, $context, $customer)
            ->getAddressCollection()
            ->get($addressId);

        if (!$address) {
            throw AddressNotFoundException::byId($addressId);
        }

        return $address;
    }
}
