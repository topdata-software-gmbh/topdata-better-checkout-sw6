<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class CompanyNameChangeRequestService
{
    public function __construct(
        private readonly EntityRepository $companyNameChangeRequestRepository,
        private readonly EntityRepository $customerAddressRepository,
        private readonly CompanyNameChangeRequestEmailService $emailService,
        private readonly EntityRepository $customerRepository,
    ) {
    }

    public function createChangeRequest(
        string $customerId,
        string $addressId,
        string $oldCompanyName,
        string $newCompanyName,
        Context $context
    ): CompanyNameChangeRequestEntity {
        $this->cancelPendingRequestsForAddress($addressId, $customerId, $context);

        $id = Uuid::randomHex();
        $data = [
            'id' => $id,
            'customerId' => $customerId,
            'addressId' => $addressId,
            'oldCompanyName' => $oldCompanyName,
            'newCompanyName' => $newCompanyName,
            'status' => 'pending',
        ];

        $this->companyNameChangeRequestRepository->create([$data], $context);

        $criteria = new Criteria([$id]);
        $request = $this->companyNameChangeRequestRepository->search($criteria, $context)->first();

        $this->emailService->sendAdminNotificationEmail($request, $context);

        return $request;
    }

    public function approveChangeRequest(string $changeRequestId, Context $context, ?string $reviewComment = null): void
    {
        $criteria = new Criteria([$changeRequestId]);
        $criteria->addAssociation('address');
        /** @var CompanyNameChangeRequestEntity|null $request */
        $request = $this->companyNameChangeRequestRepository->search($criteria, $context)->first();

        if (!$request instanceof CompanyNameChangeRequestEntity) {
            throw new \InvalidArgumentException('Change request not found');
        }

        if ($request->getStatus() !== 'pending') {
            throw new \LogicException('Only pending requests can be approved');
        }

        $this->companyNameChangeRequestRepository->update([
            [
                'id' => $changeRequestId,
                'status' => 'approved',
                'reviewedAt' => new \DateTimeImmutable(),
                'reviewComment' => $reviewComment,
            ],
        ], $context);

        $this->customerAddressRepository->update([
            [
                'id' => $request->getAddressId(),
                'company' => $request->getNewCompanyName(),
            ],
        ], $context);

        $this->customerRepository->update([
            [
                'id' => $request->getCustomerId(),
                'company' => $request->getNewCompanyName(),
            ],
        ], $context);

        $this->cancelOtherPendingRequestsForCustomer(
            $request->getCustomerId(),
            $request->getId(),
            $context
        );

        $this->emailService->sendCustomerStatusEmail($request, 'approved', $context);
    }

    public function rejectChangeRequest(string $changeRequestId, Context $context, ?string $reviewComment = null): void
    {
        $criteria = new Criteria([$changeRequestId]);
        /** @var CompanyNameChangeRequestEntity|null $request */
        $request = $this->companyNameChangeRequestRepository->search($criteria, $context)->first();

        if (!$request instanceof CompanyNameChangeRequestEntity) {
            throw new \InvalidArgumentException('Change request not found');
        }

        if ($request->getStatus() !== 'pending') {
            throw new \LogicException('Only pending requests can be rejected');
        }

        $this->companyNameChangeRequestRepository->update([
            [
                'id' => $changeRequestId,
                'status' => 'rejected',
                'reviewedAt' => new \DateTimeImmutable(),
                'reviewComment' => $reviewComment,
            ],
        ], $context);

        $this->emailService->sendCustomerStatusEmail($request, 'rejected', $context);
    }

    public function hasPendingChangeRequest(string $customerId, string $addressId, Context $context): bool
    {
        return $this->findPendingChangeRequest($customerId, $addressId, $context) !== null;
    }

    public function hasPendingChangeRequestForCustomer(string $customerId, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->addFilter(new EqualsFilter('status', 'pending'));
        $criteria->setLimit(1);

        return $this->companyNameChangeRequestRepository->search($criteria, $context)->first() !== null;
    }

    public function findPendingChangeRequest(string $customerId, string $addressId, Context $context): ?CompanyNameChangeRequestEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->addFilter(new EqualsFilter('addressId', $addressId));
        $criteria->addFilter(new EqualsFilter('status', 'pending'));
        $criteria->setLimit(1);

        return $this->companyNameChangeRequestRepository->search($criteria, $context)->first();
    }

    public function findPendingChangeRequestForCustomer(string $customerId, Context $context): ?CompanyNameChangeRequestEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->addFilter(new EqualsFilter('status', 'pending'));
        $criteria->setLimit(1);

        return $this->companyNameChangeRequestRepository->search($criteria, $context)->first();
    }

    private function cancelPendingRequestsForAddress(string $addressId, string $customerId, Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('addressId', $addressId));
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->addFilter(new EqualsFilter('status', 'pending'));

        $pendingRequests = $this->companyNameChangeRequestRepository->search($criteria, $context);
        $updateData = [];
        foreach ($pendingRequests->getIds() as $id) {
            $updateData[] = [
                'id' => $id,
                'status' => 'rejected',
                'reviewComment' => 'Automatically rejected due to new change request',
                'reviewedAt' => new \DateTimeImmutable(),
            ];
        }

        if ($updateData !== []) {
            $this->companyNameChangeRequestRepository->update($updateData, $context);
        }
    }

    private function cancelOtherPendingRequestsForCustomer(string $customerId, string $excludeId, Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $criteria->addFilter(new EqualsFilter('status', 'pending'));
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('id', $excludeId)]));

        $pendingRequests = $this->companyNameChangeRequestRepository->search($criteria, $context);
        $updateData = [];
        foreach ($pendingRequests->getIds() as $id) {
            $updateData[] = [
                'id' => $id,
                'status' => 'rejected',
                'reviewComment' => 'Automatically rejected — a newer request was approved',
                'reviewedAt' => new \DateTimeImmutable(),
            ];
        }

        if ($updateData !== []) {
            $this->companyNameChangeRequestRepository->update($updateData, $context);
        }
    }
}
