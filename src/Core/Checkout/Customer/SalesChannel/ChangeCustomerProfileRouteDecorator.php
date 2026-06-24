<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\SalesChannel;

use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractChangeCustomerProfileRoute;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SuccessResponse;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class ChangeCustomerProfileRouteDecorator extends AbstractChangeCustomerProfileRoute
{
    public function __construct(
        private readonly AbstractChangeCustomerProfileRoute $decorated,
        private readonly EntityRepository $customerRepository,
    ) {
    }

    public function getDecorated(): AbstractChangeCustomerProfileRoute
    {
        return $this->decorated;
    }

    public function change(RequestDataBag $data, SalesChannelContext $context, CustomerEntity $customer): SuccessResponse
    {
        $this->preserveCompany($data, $context, $customer);

        return $this->decorated->change($data, $context, $customer);
    }

    /**
     * The company field on the profile edit page is read-only (change-request
     * mechanism) and never submitted with the form. Shopware's core
     * ChangeCustomerProfileRoute either adds a NotBlank constraint on company
     * for business accounts (validation failure on the empty field) or
     * overwrites the stored customer.company with the missing value. Guard the
     * invariant that a business customer's company name can never be emptied
     * through the profile edit form by re-injecting the persisted value
     * whenever the submitted one is empty.
     */
    private function preserveCompany(RequestDataBag $data, SalesChannelContext $context, CustomerEntity $customer): void
    {
        $submittedCompany = $data->get('company');
        if (\is_string($submittedCompany) && trim($submittedCompany) !== '') {
            return;
        }

        /** @var CustomerCollection|null $customers */
        $customers = $this->customerRepository->search(new Criteria([$customer->getId()]), $context->getContext())->getEntities();
        $existingCustomer = $customers?->get($customer->getId());

        $existingCompany = $existingCustomer?->getCompany();
        if (\is_string($existingCompany) && trim($existingCompany) !== '') {
            $data->set('company', $existingCompany);
        }
    }
}