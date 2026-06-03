---
filename: "_ai/backlog/active/260603_1951__IMPLEMENTATION_PLAN__company-name-change-approval.md"
title: "Company Name Change Approval Workflow"
createdAt: 2026-06-03 19:51
updatedAt: 2026-06-03 19:51
status: draft
priority: high
tags: [checkout, billing-address, admin, email, approval-workflow, sw6.7]
estimatedComplexity: complex
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description

Currently, customers can freely change the company name on their billing address. This is problematic because:

1. The company name on the billing address is commercially sensitive — changing it could indicate fraud or an unauthorized party taking over the account.
2. There is **no approval mechanism**: any change is immediately effective.
3. There is **no notification**: shop administrators are not informed when a company name changes.
4. While a change request is pending approval, the customer should **not be able to place orders** — they should see a clear, meaningful message explaining why.

The requirement is:
- When a customer edits their billing address and changes the `company` field, the change must **not** be applied immediately.
- Instead, a **change request** (Änderungsantrag) entity is created with status `pending`.
- The shop admin must be able to review these requests in the **Shopware Admin** (list view + detail view) and approve or reject them.
- Upon approval, the company name on the billing address is updated.
- Upon rejection, the change request is marked as rejected and the original company name remains.
- An **email** must be sent to the shop admin when a new change request is submitted.
- While a customer has a **pending** change request for their billing address company name, they must be **blocked from placing orders** (checkout confirm) with a clear error message.

## 2. Executive Summary

This plan introduces a **Company Name Change Request** approval workflow:

| Component | Purpose |
|---|---|
| `CompanyNameChangeRequestEntity` + DAL Definition | New custom entity to persist change requests (old company, new company, status, timestamps, customer/address references) |
| `CompanyNameChangeRequestRepository` | DAL repository for the new entity |
| Database Migration | Creates the `topdata_better_checkout_company_name_change_request` table |
| `BillingAddressEditController` (modified) | Intercept company name changes; create a change request instead of directly updating the address |
| `CheckoutBlockSubscriber` | New event subscriber that blocks order placement when a pending change request exists |
| `CheckoutConfirmPageController` decorator | Blocks checkout confirmation page, shows error message |
| Admin module (list + detail) | Vue.js admin module for reviewing, approving, and rejecting change requests |
| `CompanyNameChangeRequestService` | Core service for approve/reject logic, email dispatch, status transitions |
| `CompanyNameChangeRequestEmailService` | Sends notification emails (admin on request, customer on approval/rejection) |
| Twig overrides | Show pending-change-request notice on storefront (account address page, checkout confirm) |
| Snippets | Five languages for all new user-facing strings |
| Config | No new config needed — the workflow is always active |

## 3. Project Environment Details

- **Project Name:** SW6.7 Plugin (TopdataBetterCheckoutSW6)
- **Backend root:** `src`
- **PHP Version:** 8.2+ (minimum 8.2, supports 8.3, 8.4)
- **Shopware Version:** 6.7
- **Symfony Version:** 7.4
- **Admin Build System:** Vite (`src/Resources/app/administration/`)

## 4. Implementation Steps

### Phase 1: Custom Entity & Database Migration

#### 1.1 Entity Definition

Create a new DAL entity definition for the change request. This entity stores the proposed company name change, links to the customer and address, and tracks the approval status.

```php
// [NEW FILE] src/Core/Content/CompanyNameChangeRequest/CompanyNameChangeRequestDefinition.php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\ForeignKey;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;

#[Entity('topdata_better_checkout_company_name_change_request')]
class CompanyNameChangeRequestDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'topdata_better_checkout_company_name_change_request';
    }

    public function getEntityClass(): string
    {
        return CompanyNameChangeRequestEntity::class;
    }

    public function getCollectionClass(): string
    {
        return CompanyNameChangeRequestCollection::class;
    }
}
```

#### 1.2 Entity Class

```php
// [NEW FILE] src/Core/Content/CompanyNameChangeRequest/CompanyNameChangeRequestEntity.php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\ForeignKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

#[Entity]
class CompanyNameChangeRequestEntity extends Entity
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID, api: [FieldType::UUID])]
    protected string $id;

    #[ForeignKey(entity: CustomerDefinition::class)]
    #[Field(type: FieldType::UUID)]
    protected string $customerId;

    #[ForeignKey(entity: CustomerAddressDefinition::class)]
    #[Field(type: FieldType::UUID)]
    protected string $addressId;

    #[Field(type: FieldType::STRING)]
    protected string $oldCompanyName;

    #[Field(type: FieldType::STRING)]
    protected string $newCompanyName;

    #[Field(type: FieldType::STRING)]
    protected string $status; // 'pending', 'approved', 'rejected'

    #[Field(type: FieldType::DATETIME)]
    protected ?\DateTimeInterface $reviewedAt = null;

    #[Field(type: FieldType::STRING, nullable: true)]
    protected ?string $reviewComment = null;

    #[Field(type: FieldType::UUID, nullable: true)]
    #[ForeignKey(entity: UserDefinition::class)]
    protected ?string $reviewedByUserId = null;

    // Runtime fields (not persisted)
    protected ?CustomerEntity $customer = null;
    protected ?CustomerAddressEntity $address = null;

    public function getId(): string { return $this->id; }
    public function getCustomerId(): string { return $this->customerId; }
    public function getAddressId(): string { return $this->addressId; }
    public function getOldCompanyName(): string { return $this->oldCompanyName; }
    public function getNewCompanyName(): string { return $this->newCompanyName; }
    public function getStatus(): string { return $this->status; }
    public function getReviewedAt(): ?\DateTimeInterface { return $this->reviewedAt; }
    public function getReviewComment(): ?string { return $this->reviewComment; }
    public function getReviewedByUserId(): ?string { return $this->reviewedByUserId; }
    public function getCustomer(): ?CustomerEntity { return $this->customer; }
    public function getAddress(): ?CustomerAddressEntity { return $this->address; }

    public function setId(string $id): void { $this->id = $id; }
    public function setCustomerId(string $customerId): void { $this->customerId = $customerId; }
    public function setAddressId(string $addressId): void { $this->addressId = $addressId; }
    public function setOldCompanyName(string $oldCompanyName): void { $this->oldCompanyName = $oldCompanyName; }
    public function setNewCompanyName(string $newCompanyName): void { $this->newCompanyName = $newCompanyName; }
    public function setStatus(string $status): void { $this->status = $status; }
    public function setReviewedAt(?\DateTimeInterface $reviewedAt): void { $this->reviewedAt = $reviewedAt; }
    public function setReviewComment(?string $reviewComment): void { $this->reviewComment = $reviewComment; }
    public function setReviewedByUserId(?string $reviewedByUserId): void { $this->reviewedByUserId = $this->reviewedByUserId; }
    public function setCustomer(?CustomerEntity $customer): void { $this->customer = $customer; }
    public function setAddress(?CustomerAddressEntity $address): void { $this->address = $address; }
}
```

#### 1.3 Entity Collection

```php
// [NEW FILE] src/Core/Content/CompanyNameChangeRequest/CompanyNameChangeRequestCollection.php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(CompanyNameChangeRequestEntity $entity)
 * @method void get(string $key): CompanyNameChangeRequestEntity
 * @method CompanyNameChangeRequestEntity[] getIterator()
 */
class CompanyNameChangeRequestCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return CompanyNameChangeRequestEntity::class;
    }
}
```

#### 1.4 Database Migration

```php
// [NEW FILE] src/Migration/Migration1748979000CreateCompanyNameChangeRequestTable.php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1748979000CreateCompanyNameChangeRequestTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1748979000;
    }

    public function update(Connection $connection): void
    {
        $sql = <<<'SQL'
            CREATE TABLE IF NOT EXISTS `topdata_better_checkout_company_name_change_request` (
                `id` BINARY(16) NOT NULL,
                `customer_id` BINARY(16) NOT NULL,
                `address_id` BINARY(16) NOT NULL,
                `old_company_name` VARCHAR(255) NOT NULL,
                `new_company_name` VARCHAR(255) NOT NULL,
                `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
                `reviewed_at` DATETIME(3) NULL,
                `review_comment` VARCHAR(2000) NULL,
                `reviewed_by_user_id` BINARY(16) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.crreq.customer_id` (`customer_id`),
                KEY `idx.crreq.address_id` (`address_id`),
                KEY `idx.crreq.status` (`status`),
                CONSTRAINT `fk.crreq.customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk.crreq.address_id` FOREIGN KEY (`address_id`) REFERENCES `customer_address` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL;

        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
```

### Phase 2: Core Service Layer

#### 2.1 CompanyNameChangeRequestService

This is the central service handling all business logic: creating requests, approving, rejecting, and checking for pending requests.

```php
// [NEW FILE] src/Core/Content/CompanyNameChangeRequest/CompanyNameChangeRequestService.php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class CompanyNameChangeRequestService
{
    public function __construct(
        private readonly EntityRepository $companyNameChangeRequestRepository,
        private readonly EntityRepository $customerAddressRepository,
        private readonly CompanyNameChangeRequestEmailService $emailService,
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
```

#### 2.2 Email Service

```php
// [NEW FILE] src/Core/Content/CompanyNameChangeRequest/CompanyNameChangeRequestEmailService.php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest;

use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class CompanyNameChangeRequestEmailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function sendAdminNotificationEmail(?CompanyNameChangeRequestEntity $changeRequest, Context $context): void
    {
        if (!$changeRequest instanceof CompanyNameChangeRequestEntity) {
            return;
        }

        $recipientEmail = $this->systemConfigService->getString('core.basicInformation.email');

        if ($recipientEmail === '') {
            $recipientEmail = $this->systemConfigService->getString('core.mailerSettings.mailerSender');
        }

        if ($recipientEmail === '') {
            return;
        }

        $subject = 'Neuer Antrag auf Firmennameänderung / New company name change request';

        $htmlBody = $this->twig->render(
            '@TopdataBetterCheckoutSW6/email/admin-company-name-change-notification.html.twig',
            [
                'changeRequest' => $changeRequest,
            ]
        );

        $email = (new Email())
            ->from($recipientEmail)
            ->to($recipientEmail)
            ->subject($subject)
            ->html($htmlBody);

        $this->mailer->send($email);
    }

    public function sendCustomerStatusEmail(CompanyNameChangeRequestEntity $changeRequest, string $status, Context $context): void
    {
        if (!$changeRequest->getCustomer() instanceof \Shopware\Core\Checkout\Customer\CustomerEntity) {
            return;
        }

        $customerEmail = $changeRequest->getCustomer()->getEmail();
        $shopName = $this->systemConfigService->getString('core.basicInformation.shopName');

        if ($status === 'approved') {
            $subject = 'Ihr Antrag auf Firmennameänderung wurde genehmigt / Your company name change request has been approved';
        } else {
            $subject = 'Ihr Antrag auf Firmennameänderung wurde abgelehnt / Your company name change request has been rejected';
        }

        $htmlBody = $this->twig->render(
            $status === 'approved'
                ? '@TopdataBetterCheckoutSW6/email/customer-company-name-approved.html.twig'
                : '@TopdataBetterCheckoutSW6/email/customer-company-name-rejected.html.twig',
            [
                'changeRequest' => $changeRequest,
                'shopName' => $shopName,
            ]
        );

        $senderEmail = $this->systemConfigService->getString('core.basicInformation.email');
        if ($senderEmail === '') {
            $senderEmail = $this->systemConfigService->getString('core.mailerSettings.mailerSender');
        }

        $email = (new Email())
            ->from($senderEmail)
            ->to($customerEmail)
            ->subject($subject)
            ->html($htmlBody);

        $this->mailer->send($email);
    }
}
```

### Phase 3: Storefront — Intercept Company Name Changes

#### 3.1 Modify `BillingAddressEditController`

When a customer edits their billing address via the existing storefront controller, we must detect whether the `company` field has changed. If the company changed on the **billing address**, we create a change request instead of directly updating the address. All other fields (street, zip, city, etc.) are still updated immediately.

```php
// [MODIFY] src/Controller/BillingAddressEditController.php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Controller;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\Exception\AddressNotFoundException;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractListAddressRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractUpsertAddressRoute;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
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
        private readonly EntityRepository $customerRepository,
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
```

#### 3.2 Checkout Block — Event Subscriber

When a customer has a pending company name change request, they must be blocked from completing the checkout. We intercept the `CheckoutConfirmPageLoader` (or the confirm page controller) to show a clear error message.

```php
// [NEW FILE] src/Core/Checkout/Customer/Subscriber/CheckoutConfirmBlockSubscriber.php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestService;

class CheckoutConfirmBlockSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CompanyNameChangeRequestService $companyNameChangeRequestService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onConfirmPageLoaded',
        ];
    }

    public function onConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $customer = $event->getSalesChannelContext()->getCustomer();
        if (!$customer instanceof CustomerEntity) {
            return;
        }

        $pendingRequest = $this->companyNameChangeRequestService->findPendingChangeRequestForCustomer(
            $customer->getId(),
            $event->getContext()
        );

        if ($pendingRequest !== null) {
            $event->getPage()->addExtension(
                'topdataCompanyNameChangePending',
                new CompanyNameChangePendingExtension($pendingRequest)
            );
        }
    }
}
```

#### 3.3 Template Extension (Struct)

```php
// [NEW FILE] src/Core/Content/CompanyNameChangeRequest/CompanyNameChangePendingExtension.php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest;

use Shopware\Core\Framework\Struct\Struct;

class CompanyNameChangePendingExtension extends Struct
{
    public function __construct(
        private readonly CompanyNameChangeRequestEntity $changeRequest
    ) {
    }

    public function getChangeRequest(): CompanyNameChangeRequestEntity
    {
        return $this->changeRequest;
    }

    public function getApiAlias(): string
    {
        return 'topdata_company_name_change_pending';
    }
}
```

### Phase 4: Storefront Twig Templates

#### 4.1 Checkout Confirm Page — Pending Change Notice

When there is a pending company name change request, show a blocking notice on the checkout confirm page.

```twig
{# [NEW FILE] src/Resources/views/storefront/page/checkout/confirm/confirm-company-name-change-pending.html.twig #}
{% sw_extends '@Storefront/storefront/page/checkout/confirm/index.html.twig' %}

{% block page_checkout_confirm %}
    {% set pendingChange = page.getExtension('topdataCompanyNameChangePending') %}

    {% if pendingChange %}
        <div class="alert alert-danger d-flex align-items-center" role="alert">
            <div class="me-3">
                {% sw_icon 'warning' style { size: 'lg' } %}
            </div>
            <div>
                <strong>{{ 'better-checkout.companyChange.blockedTitle'|trans|sw_sanitize }}</strong>
                <p class="mb-0 mt-1">
                    {{ 'better-checkout.companyChange.blockedMessage'|trans({
                        '%oldCompany%': pendingChange.changeRequest.oldCompanyName,
                        '%newCompany%': pendingChange.changeRequest.newCompanyName
                    })|sw_sanitize }}
                </p>
            </div>
        </div>
    {% endif %}

    {% if not pendingChange %}
        {{ parent() }}
    {% endif %}
{% endblock %}
```

#### 4.2 Billing Address Edit Modal — Pending Change Notice

When the billing address edit modal is opened and there is a pending company name change, show a notice inside the modal.

```twig
{# [MODIFY] src/Resources/views/storefront/component/address/billing-address-edit-modal.html.twig #}
{# Add after the modal-header block, inside modal-body, before the form #}
{% if hasPendingCompanyNameChange is defined and hasPendingCompanyNameChange %}
    <div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
        <div class="me-3">
            {% sw_icon 'warning' style { size: 'md' } %}
        </div>
        <div>
            <strong>{{ 'better-checkout.companyChange.pendingTitle'|trans|sw_sanitize }}</strong>
            <p class="mb-0 mt-1">
                {{ 'better-checkout.companyChange.pendingMessage'|trans|sw_sanitize }}
            </p>
        </div>
    </div>
{% endif %}
```

#### 4.3 Account Address Page — Pending Change Notice

```twig
{# [NEW FILE] src/Resources/views/storefront/page/account/addressbook/company-change-pending-notice.html.twig #}
{# This will be rendered as a separate include block in the address book page #}
<div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
    <div class="me-3">
        {% sw_icon 'warning' style { size: 'md' } %}
    </div>
    <div>
        <strong>{{ 'better-checkout.companyChange.pendingTitle'|trans|sw_sanitize }}</strong>
        <p class="mb-0 mt-1">
            {{ 'better-checkout.companyChange.pendingMessage'|trans|sw_sanitize }}
        </p>
    </div>
</div>
```

### Phase 5: Email Templates

#### 5.1 Admin Notification Email

```html
{# [NEW FILE] src/Resources/views/email/admin-company-name-change-notification.html.twig #}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Neuer Antrag auf Firmennameänderung</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2>Neuer Antrag auf Firmennameänderung</h2>
    <p>New Company Name Change Request / Neuer Antrag auf Firmennameänderung</p>

    <table style="border-collapse: collapse; width: 100%; max-width: 500px;">
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">Customer ID</td>
            <td style="padding: 8px; border: 1px solid #ddd;">{{ changeRequest.customerId }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">Address ID</td>
            <td style="padding: 8px; border: 1px solid #ddd;">{{ changeRequest.addressId }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">Alter Firmenname / Old Company</td>
            <td style="padding: 8px; border: 1px solid #ddd;">{{ changeRequest.oldCompanyName }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">Neuer Firmenname / New Company</td>
            <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold; color: #c0392b;">{{ changeRequest.newCompanyName }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">Datum / Date</td>
            <td style="padding: 8px; border: 1px solid #ddd;">{{ changeRequest.createdAt|date('d.m.Y H:i') }}</td>
        </tr>
    </table>

    <p style="margin-top: 20px;">
        Bitte prüfen Sie diesen Antrag im Admin-Bereich.<br>
        Please review this request in the admin area.
    </p>
</body>
</html>
```

#### 5.2 Customer Approval Email

```html
{# [NEW FILE] src/Resources/views/email/customer-company-name-approved.html.twig #}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Firmennameänderung genehmigt</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2>Ihr Antrag auf Firmennameänderung wurde genehmigt</h2>
    <p>Your company name change request has been approved.</p>

    <table style="border-collapse: collapse; width: 100%; max-width: 500px;">
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">Alter Firmenname / Old Company</td>
            <td style="padding: 8px; border: 1px solid #ddd;">{{ changeRequest.oldCompanyName }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">Neuer Firmenname / New Company</td>
            <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold; color: #27ae60;">{{ changeRequest.newCompanyName }}</td>
        </tr>
    </table>

    <p style="margin-top: 20px;">Sie können jetzt Ihre Bestellung abschließen.<br>You can now complete your order.</p>
</body>
</html>
```

#### 5.3 Customer Rejection Email

```html
{# [NEW FILE] src/Resources/views/email/customer-company-name-rejected.html.twig #}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Firmennameänderung abgelehnt</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2>Ihr Antrag auf Firmennameänderung wurde abgelehnt</h2>
    <p>Your company name change request has been rejected.</p>

    <table style="border-collapse: collapse; width: 100%; max-width: 500px;">
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">Beantragter Firmenname / Requested Company</td>
            <td style="padding: 8px; border: 1px solid #ddd;">{{ changeRequest.newCompanyName }}</td>
        </tr>
        <tr>
            <td style="padding: 8px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">Behält / Retained</td>
            <td style="padding: 8px; border: 1px solid #ddd;">{{ changeRequest.oldCompanyName }}</td>
        </tr>
    </table>

    <p style="margin-top: 20px;">Bitte kontaktieren Sie uns bei Fragen.<br>Please contact us if you have any questions.</p>
</body>
</html>
```

### Phase 6: Shopware Admin Module

This is a Vue.js-based admin module providing **list view** and **detail view** for company name change requests.

#### 6.1 Module Bootstrap

```javascript
// [NEW FILE] src/Resources/app/administration/src/main.js
import './module/topdata-better-checkout-company-name-change';
```

#### 6.2 Module Index

```javascript
// [NEW FILE] src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/index.js
import defaultSearchConfiguration from './default-search-configuration';
import './page/topdata-company-name-change-list';
import './page/topdata-company-name-change-detail';

const { Module } = Shopware;

Module.register('topdata-better-checkout-company-name-change', {
    type: 'plugin',
    name: 'topdata-better-checkout-company-name-change.module.name',
    title: 'topdata-better-checkout-company-name-change.module.title',
    description: 'topdata-better-checkout-company-name-change.module.description',
    color: '#ff5722',
    icon: 'regular:window',
    entity: 'topdata_better_checkout_company_name_change_request',

    routes: {
        list: {
            component: 'topdata-company-name-change-list',
            path: 'list',
        },
        detail: {
            component: 'topdata-company-name-change-detail',
            path: 'detail/:id',
        },
    },

    navigation: [{
        id: 'topdata-better-checkout-company-name-change',
        label: 'topdata-better-checkout-company-name-change.module.title',
        color: '#ff5722',
        icon: 'regular:window',
        path: 'topdata.better.checkout.company.name.change.list',
        parent: 'customer',
        position: 100,
    }],

    defaultSearchConfiguration,
});
```

#### 6.3 Default Search Configuration

```javascript
// [NEW FILE] src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/default-search-configuration.js
import { searchBehavior } from 'src/app/default-search-configuration';

const defaults = {
    ...searchBehavior,
    searchConfig: {
        searches: [
            {
                field: 'oldCompanyName',
                rank: 500,
            },
            {
                field: 'newCompanyName',
                rank: 500,
            },
        ],
    },
};

export default defaults;
```

#### 6.4 List Page Component

```javascript
// [NEW FILE] src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/page/topdata-company-name-change-list/index.js
import template from './topdata-company-name-change-list.html.twig';

const { Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Shopware.Component.register('topdata-company-name-change-list', {
    template,

    inject: ['repositoryFactory', 'acl'],

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('listing'),
    ],

    data() {
        return {
            items: null,
            isLoading: false,
            sortBy: 'createdAt',
            sortDirection: 'DESC',
            naturalSorting: false,
            total: 0,
            filterStatus: null,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('topdata_better_checkout_company_name_change_request');
        },

        listFilters() {
            return [
                {
                    property: 'status',
                    label: this.$tc('topdata-better-checkout-company-name-change.list.filter.status'),
                    options: [
                        { value: null, label: this.$tc('global.default.all') },
                        { value: 'pending', label: this.$tc('topdata-better-checkout-company-name-change.status.pending') },
                        { value: 'approved', label: this.$tc('topdata-better-checkout-company-name-change.status.approved') },
                        { value: 'rejected', label: this.$tc('topdata-better-checkout-company-name-change.status.rejected') },
                    ],
                },
            ];
        },

        columns() {
            return [
                {
                    property: 'newCompanyName',
                    label: this.$tc('topdata-better-checkout-company-name-change.list.column.newCompanyName'),
                    allowResize: true,
                    primary: true,
                },
                {
                    property: 'oldCompanyName',
                    label: this.$tc('topdata-better-checkout-company-name-change.list.column.oldCompanyName'),
                    allowResize: true,
                },
                {
                    property: 'status',
                    label: this.$tc('topdata-better-checkout-company-name-change.list.column.status'),
                    allowResize: true,
                },
                {
                    property: 'createdAt',
                    label: this.$tc('topdata-better-checkout-company-name-change.list.column.createdAt'),
                    allowResize: true,
                },
            ];
        },
    },

    methods: {
        getList() {
            this.isLoading = true;

            const criteria = new Criteria(this.page, this.limit);
            criteria.setTerm(this.term);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));
            criteria.addAssociation('customer');
            criteria.addAssociation('address');

            if (this.filterStatus) {
                criteria.addFilter(Criteria.equals('status', this.filterStatus));
            }

            return this.repository.search(criteria, Shopware.Context.api).then((result) => {
                this.items = result;
                this.total = result.total;
                this.isLoading = false;
            }).catch(() => {
                this.isLoading = false;
            });
        },

        getStatusVariant(status) {
            const variants = {
                pending: 'warning',
                approved: 'success',
                rejected: 'danger',
            };
            return variants[status] || 'info';
        },

        getStatusLabel(status) {
            return this.$tc(`topdata-better-checkout-company-name-change.status.${status}`);
        },
    },
});
```

#### 6.5 List Page Template

```html
<!-- [NEW FILE] src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/page/topdata-company-name-change-list/topdata-company-name-change-list.html.twig -->
<sw-page class="topdata-company-name-change-list">
    <template #smart-bar-header>
        <h2>{{ $tc('topdata-better-checkout-company-name-change.list.title') }}</h2>
    </template>

    <template #smart-bar-actions>
        <sw-button variant="primary" @click="getList">
            {{ $tc('global.default.refresh') }}
        </sw-button>
    </template>

    <template #content>
        <sw-entity-listing
            v-if="items"
            :items="items"
            :repository="repository"
            :columns="columns"
            :isLoading="isLoading"
            :sort-by="sortBy"
            :sort-direction="sortDirection"
            detail-route="topdata.better.checkout.company.name.change.detail"
            identifier="id"
            @page-change="onPageChange"
        >
            <template #column-status="{ item }">
                <sw-badge :variant="getStatusVariant(item.status)">
                    {{ getStatusLabel(item.status) }}
                </sw-badge>
            </template>

            <template #column-createdAt="{ item }">
                {{ date(item.createdAt) }}
            </template>
        </sw-entity-listing>
    </template>

    <template #sidebar>
        <sw-sidebar>
            <sw-sidebar-filter-panel
                entity="topdata_better_checkout_company_name_change_request"
                :filters="listFilters"
                @filters-changed="onFiltersChanged"
            />
        </sw-sidebar>
    </template>
</sw-page>
```

#### 6.6 Detail Page Component

```javascript
// [NEW FILE] src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/page/topdata-company-name-change-detail/index.js
import template from './topdata-company-name-change-detail.html.twig';
import './topdata-company-name-change-detail.scss';

const { Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Shopware.Component.register('topdata-company-name-change-detail', {
    template,

    inject: ['repositoryFactory', 'acl', 'companyNameChangeRequestService'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            item: null,
            isLoading: false,
            isSaving: false,
            reviewComment: '',
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('topdata_better_checkout_company_name_change_request');
        },

        changeRequestId() {
            return this.$route.params.id;
        },

        isPending() {
            return this.item && this.item.status === 'pending';
        },
    },

    created() {
        this.loadEntity();
    },

    methods: {
        loadEntity() {
            this.isLoading = true;

            const criteria = new Criteria();
            criteria.addAssociation('customer');
            criteria.addAssociation('address');

            this.repository.get(this.changeRequestId, Shopware.Context.api, criteria).then((result) => {
                this.item = result;
                this.isLoading = false;
            }).catch(() => {
                this.isLoading = false;
            });
        },

        onApprove() {
            this.isSaving = true;

            this.companyNameChangeRequestService.approve(this.item.id, this.reviewComment).then(() => {
                this.createNotificationSuccess({
                    message: this.$tc('topdata-better-checkout-company-name-change.detail.approveSuccess'),
                });
                this.loadEntity();
                this.isSaving = false;
            }).catch(() => {
                this.createNotificationError({
                    message: this.$tc('topdata-better-checkout-company-name-change.detail.approveError'),
                });
                this.isSaving = false;
            });
        },

        onReject() {
            this.isSaving = true;

            this.companyNameChangeRequestService.reject(this.item.id, this.reviewComment).then(() => {
                this.createNotificationSuccess({
                    message: this.$tc('topdata-better-checkout-company-name-change.detail.rejectSuccess'),
                });
                this.loadEntity();
                this.isSaving = false;
            }).catch(() => {
                this.createNotificationError({
                    message: this.$tc('topdata-better-checkout-company-name-change.detail.rejectError'),
                });
                this.isSaving = false;
            });
        },
    },
});
```

#### 6.7 Detail Page Template

```html
<!-- [NEW FILE] src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/page/topdata-company-name-change-detail/topdata-company-name-change-detail.html.twig -->
<sw-page class="topdata-company-name-change-detail">
    <template #smart-bar-header>
        <h2>{{ $tc('topdata-better-checkout-company-name-change.detail.title') }}</h2>
    </template>

    <template #smart-bar-actions>
        <sw-button
            v-if="isPending"
            variant="danger"
            :isLoading="isSaving"
            @click="onReject"
        >
            {{ $tc('topdata-better-checkout-company-name-change.detail.reject') }}
        </sw-button>

        <sw-button
            v-if="isPending"
            variant="primary"
            :isLoading="isSaving"
            @click="onApprove"
        >
            {{ $tc('topdata-better-checkout-company-name-change.detail.approve') }}
        </sw-button>
    </template>

    <template #content>
        <sw-card-group v-if="item">
            <sw-card :title="$tc('topdata-better-checkout-company-name-change.detail.card.requestInfo')">
                <sw-description-list>
                    <dt>{{ $tc('topdata-better-checkout-company-name-change.detail.label.oldCompanyName') }}</dt>
                    <dd>{{ item.oldCompanyName }}</dd>

                    <dt>{{ $tc('topdata-better-checkout-company-name-change.detail.label.newCompanyName') }}</dt>
                    <dd class="new-company-name">{{ item.newCompanyName }}</dd>

                    <dt>{{ $tc('topdata-better-checkout-company-name-change.detail.label.status') }}</dt>
                    <dd>
                        <sw-badge :variant="getStatusVariant(item.status)">
                            {{ $tc(`topdata-better-checkout-company-name-change.status.${item.status}`) }}
                        </sw-badge>
                    </dd>

                    <dt>{{ $tc('topdata-better-checkout-company-name-change.detail.label.createdAt') }}</dt>
                    <dd>{{ date(item.createdAt) }}</dd>

                    <template v-if="item.reviewedAt">
                        <dt>{{ $tc('topdata-better-checkout-company-name-change.detail.label.reviewedAt') }}</dt>
                        <dd>{{ date(item.reviewedAt) }}</dd>
                    </template>

                    <template v-if="item.reviewComment">
                        <dt>{{ $tc('topdata-better-checkout-company-name-change.detail.label.reviewComment') }}</dt>
                        <dd>{{ item.reviewComment }}</dd>
                    </template>
                </sw-description-list>
            </sw-card>

            <sw-card
                v-if="item.customer"
                :title="$tc('topdata-better-checkout-company-name-change.detail.card.customerInfo')"
            >
                <sw-description-list>
                    <dt>{{ $tc('topdata-better-checkout-company-name-change.detail.label.customerName') }}</dt>
                    <dd>{{ item.customer.firstName }} {{ item.customer.lastName }}</dd>

                    <dt>{{ $tc('topdata-better-checkout-company-name-change.detail.label.customerEmail') }}</dt>
                    <dd>{{ item.customer.email }}</dd>
                </sw-description-list>
            </sw-card>

            <sw-card
                v-if="isPending"
                :title="$tc('topdata-better-checkout-company-name-change.detail.card.review')"
            >
                <sw-textarea-field
                    v-model:value="reviewComment"
                    :label="$tc('topdata-better-checkout-company-name-change.detail.label.reviewComment')"
                    :placeholder="$tc('topdata-better-checkout-company-name-change.detail.placeholder.reviewComment')"
                />
            </sw-card>
        </sw-card-group>

        <sw-loader v-else-if="isLoading" />

        <sw-empty-state
            v-else
            :title="$tc('topdata-better-checkout-company-name-change.detail.notFound')"
        />
    </template>
</sw-page>
```

#### 6.8 Detail Page SCSS

```scss
/* [NEW FILE] src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/page/topdata-company-name-change-detail/topdata-company-name-change-detail.scss */
.topdata-company-name-change-detail {
    .new-company-name {
        font-weight: bold;
        color: #c0392b;
    }
}
```

#### 6.9 Admin API Service

```javascript
// [NEW FILE] src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/service/company-name-change-request.service.js
const { Application } = Shopware;

Application.addServiceProvider('companyNameChangeRequestService', () => {
    const httpClient = Shopware.Application.getContainer('init').httpClient;

    return {
        approve(id, reviewComment = null) {
            return httpClient.post(
                `/api/topdata-better-checkout/company-name-change-request/${id}/approve`,
                { reviewComment },
                {
                    headers: {
                        'Content-Type': 'application/json',
                    },
                }
            );
        },

        reject(id, reviewComment = null) {
            return httpClient.post(
                `/api/topdata-better-checkout/company-name-change-request/${id}/reject`,
                { reviewComment },
                {
                    headers: {
                        'Content-Type': 'application/json',
                    },
                }
            );
        },
    };
});
```

#### 6.10 Import the service in module index (updated module index)

```javascript
// [MODIFY] src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/index.js
import defaultSearchConfiguration from './default-search-configuration';
import './service/company-name-change-request.service';
import './page/topdata-company-name-change-list';
import './page/topdata-company-name-change-detail';

// ... rest stays the same
```

### Phase 7: Admin API Controller

The admin module calls custom API endpoints to approve/reject change requests.

```php
// [NEW FILE] src/Controller/AdminApi/CompanyNameChangeRequestController.php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Controller\AdminApi;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestService;

#[Route(defaults: ['_routeScope' => ['api']])]
class CompanyNameChangeRequestController extends AbstractController
{
    public function __construct(
        private readonly CompanyNameChangeRequestService $changeRequestService,
    ) {
    }

    #[Route(
        path: '/api/topdata-better-checkout/company-name-change-request/{id}/approve',
        name: 'api.topdata_better_checkout.company_name_change.approve',
        methods: ['POST']
    )]
    public function approve(string $id, RequestDataBag $data, Context $context): JsonResponse
    {
        $reviewComment = $data->get('reviewComment');

        try {
            $this->changeRequestService->approveChangeRequest($id, $context, $reviewComment);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 404);
        } catch (\LogicException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route(
        path: '/api/topdata-better-checkout/company-name-change-request/{id}/reject',
        name: 'api.topdata_better_checkout.company_name_change.reject',
        methods: ['POST']
    )]
    public function reject(string $id, RequestDataBag $data, Context $context): JsonResponse
    {
        $reviewComment = $data->get('reviewComment');

        try {
            $this->changeRequestService->rejectChangeRequest($id, $context, $reviewComment);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 404);
        } catch (\LogicException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return new JsonResponse(['success' => true]);
    }
}
```

### Phase 8: Dependency Injection & Service Registration

#### 8.1 Update services.xml

```xml
<!-- [MODIFY] src/Resources/config/services.xml -->
<!-- Add these service entries before the closing </services> tag: -->

        <!-- Company Name Change Request Service -->
        <service id="Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestEmailService">
            <argument type="service" id="Symfony\Component\Mailer\MailerInterface"/>
            <argument type="service" id="twig"/>
            <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService"/>
        </service>

        <service id="Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestService">
            <argument type="service" id="topdata_better_checkout_company_name_change_request.repository"/>
            <argument type="service" id="customer_address.repository"/>
            <argument type="service" id="Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestEmailService"/>
        </service>

        <!-- Controller injections -->
        <service id="Topdata\TopdataBetterCheckoutSW6\Controller\BillingAddressEditController" public="true">
            <argument type="service" id="Shopware\Core\Checkout\Customer\SalesChannel\ListAddressRoute"/>
            <argument type="service" id="Shopware\Core\Checkout\Customer\SalesChannel\UpsertAddressRoute"/>
            <argument type="service" id="Shopware\Core\System\Country\SalesChannel\CountryRoute"/>
            <argument type="service" id="Shopware\Core\System\Salutation\SalesChannel\SalutationRoute"/>
            <argument type="service" id="Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestService"/>
            <argument type="service" id="customer.repository"/>
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>

        <!-- Admin API Controller -->
        <service id="Topdata\TopdataBetterCheckoutSW6\Controller\AdminApi\CompanyNameChangeRequestController" public="true">
            <argument type="service" id="Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestService"/>
        </service>

        <!-- Checkout Block Subscriber -->
        <service id="Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber\CheckoutConfirmBlockSubscriber">
            <argument type="service" id="Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestService"/>
            <tag name="kernel.event_subscriber"/>
        </service>

        <!-- Account Address Page Subscriber (injects pending change request data) -->
        <service id="Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber\AccountAddressPageSubscriber">
            <argument type="service" id="Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestService"/>
            <tag name="kernel.event_subscriber"/>
        </service>
```

### Phase 9: Snippets

#### 9.1 German Snippets (de-DE)

Add to `src/Resources/snippet/de_DE/storefront.de-DE.json`:

```json
{
    "better-checkout": {
        "companyChange": {
            "blockedTitle": "Bestellung derzeit nicht möglich",
            "blockedMessage": "Ihr Antrag auf Änderung des Firmennamens von \"%oldCompany%\" zu \"%newCompany%\" wird gerade geprüft. Bis zur Bestätigung können Sie keine Bestellung aufgeben.",
            "pendingTitle": "Firmenname-Änderung wird geprüft",
            "pendingMessage": "Ihr Antrag auf Änderung des Firmennamens wird aktuell geprüft. Bis zur Bestätigung sind Bestellungen nicht möglich."
        }
    }
}
```

#### 9.2 English Snippets (en-GB)

Add to `src/Resources/snippet/en_GB/storefront.en-GB.json`:

```json
{
    "better-checkout": {
        "companyChange": {
            "blockedTitle": "Order not possible at this time",
            "blockedMessage": "Your request to change the company name from \"%oldCompany%\" to \"%newCompany%\" is currently under review. You cannot place an order until the change is confirmed.",
            "pendingTitle": "Company name change under review",
            "pendingMessage": "Your request to change the company name is currently under review. Orders are not possible until the change is confirmed."
        }
    }
}
```

#### 9.3 Admin Snippets (de-DE)

```javascript
// [NEW FILE] src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/snippet/de-DE.js
const deDE = {
    'topdata-better-checkout-company-name-change': {
        'module': {
            'name': 'Firmennamen-Änderungen',
            'title': 'Firmennamen-Änderungsanträge',
            'description': 'Verwaltung von Änderungsanträgen für Firmennamen in Rechnungsadressen',
        },
        'list': {
            'title': 'Firmennamen-Änderungsanträge',
            'filter': {
                'status': 'Status',
            },
            'column': {
                'newCompanyName': 'Neuer Firmenname',
                'oldCompanyName': 'Alter Firmenname',
                'status': 'Status',
                'createdAt': 'Erstellt am',
            },
        },
        'detail': {
            'title': 'Firmennamen-Änderungsantrag',
            'card': {
                'requestInfo': 'Antragsdetails',
                'customerInfo': 'Kundeninformationen',
                'review': 'Prüfung',
            },
            'label': {
                'oldCompanyName': 'Alter Firmenname',
                'newCompanyName': 'Neuer Firmenname',
                'status': 'Status',
                'createdAt': 'Erstellt am',
                'reviewedAt': 'Geprüft am',
                'reviewComment': 'Kommentar',
                'customerName': 'Kundenname',
                'customerEmail': 'Kunden-E-Mail',
            },
            'placeholder': {
                'reviewComment': 'Optionaler Kommentar zur Entscheidung...',
            },
            'approve': 'Genehmigen',
            'reject': 'Ablehnen',
            'approveSuccess': 'Änderungsantrag wurde genehmigt',
            'approveError': 'Fehler beim Genehmigen des Antrags',
            'rejectSuccess': 'Änderungsantrag wurde abgelehnt',
            'rejectError': 'Fehler beim Ablehnen des Antrags',
            'notFound': 'Antrag nicht gefunden',
        },
        'status': {
            'pending': 'Ausstehend',
            'approved': 'Genehmigt',
            'rejected': 'Abgelehnt',
        },
    },
};

export default deDE;
```

#### 9.4 Admin Snippets (en-GB)

```javascript
// [NEW FILE] src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/snippet/en-GB.js
const enGB = {
    'topdata-better-checkout-company-name-change': {
        'module': {
            'name': 'Company Name Changes',
            'title': 'Company Name Change Requests',
            'description': 'Manage company name change requests for billing addresses',
        },
        'list': {
            'title': 'Company Name Change Requests',
            'filter': {
                'status': 'Status',
            },
            'column': {
                'newCompanyName': 'New Company Name',
                'oldCompanyName': 'Old Company Name',
                'status': 'Status',
                'createdAt': 'Created At',
            },
        },
        'detail': {
            'title': 'Company Name Change Request',
            'card': {
                'requestInfo': 'Request Details',
                'customerInfo': 'Customer Information',
                'review': 'Review',
            },
            'label': {
                'oldCompanyName': 'Old Company Name',
                'newCompanyName': 'New Company Name',
                'status': 'Status',
                'createdAt': 'Created At',
                'reviewedAt': 'Reviewed At',
                'reviewComment': 'Comment',
                'customerName': 'Customer Name',
                'customerEmail': 'Customer Email',
            },
            'placeholder': {
                'reviewComment': 'Optional comment for the decision...',
            },
            'approve': 'Approve',
            'reject': 'Reject',
            'approveSuccess': 'Change request has been approved',
            'approveError': 'Error approving the request',
            'rejectSuccess': 'Change request has been rejected',
            'rejectError': 'Error rejecting the request',
            'notFound': 'Request not found',
        },
        'status': {
            'pending': 'Pending',
            'approved': 'Approved',
            'rejected': 'Rejected',
        },
    },
};

export default enGB;
```

### Phase 10: Account Address Page Subscriber

This subscriber adds the pending change request data to the account address book page, so we can render a notice there.

```php
// [NEW FILE] src/Core/Checkout/Customer/Subscriber/AccountAddressPageSubscriber.php
<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Checkout\Customer\Subscriber;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Storefront\Page\Account\Overview\AccountOverviewPageLoadedEvent;
use Shopware\Storefront\Page\Address\Detail\AddressDetailPageLoadedEvent;
use Shopware\Storefront\Page\Address\Listing\AddressListingPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangePendingExtension;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest\CompanyNameChangeRequestService;

class AccountAddressPageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CompanyNameChangeRequestService $companyNameChangeRequestService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AddressListingPageLoadedEvent::class => 'onAddressListingPageLoaded',
            AddressDetailPageLoadedEvent::class => 'onAddressDetailPageLoaded',
        ];
    }

    public function onAddressListingPageLoaded(AddressListingPageLoadedEvent $event): void
    {
        $customer = $event->getSalesChannelContext()->getCustomer();
        if (!$customer instanceof CustomerEntity) {
            return;
        }

        $pendingRequest = $this->companyNameChangeRequestService->findPendingChangeRequestForCustomer(
            $customer->getId(),
            $event->getContext()
        );

        if ($pendingRequest !== null) {
            $event->getPage()->addExtension(
                'topdataCompanyNameChangePending',
                new CompanyNameChangePendingExtension($pendingRequest)
            );
        }
    }

    public function onAddressDetailPageLoaded(AddressDetailPageLoadedEvent $event): void
    {
        $customer = $event->getSalesChannelContext()->getCustomer();
        if (!$customer instanceof CustomerEntity) {
            return;
        }

        $pendingRequest = $this->companyNameChangeRequestService->findPendingChangeRequestForCustomer(
            $customer->getId(),
            $event->getContext()
        );

        if ($pendingRequest !== null) {
            $event->getPage()->addExtension(
                'topdataCompanyNameChangePending',
                new CompanyNameChangePendingExtension($pendingRequest)
            );
        }
    }
}
```

### Phase 11: Vite Build Configuration

The admin module needs to be built with Vite. We need an administration entry point.

```javascript
// [NEW FILE] src/Resources/app/administration/src/app.js
// This is the main entry point for the administration JS build
import './module/topdata-better-checkout-company-name-change';
```

### Phase 12: Updated Snippet File Structure

Since the AGENTS.md convention says snippet files should be flat (`storefront.<locale>.json`), we only update the existing storefront snippet files. The admin snippets are handled via the admin module's snippet system.

However, we also need additional store snippet files for fr-FR, fr-CH, and pt-PT per the spec.

#### 12.1 French (fr-FR) Storefront

```json
{
    "better-checkout": {
        "companyChange": {
            "blockedTitle": "Commande impossible pour le moment",
            "blockedMessage": "Votre demande de changement de nom d'entreprise de \"%oldCompany%\" à \"%newCompany%\" est en cours de vérification. Vous ne pouvez pas passer de commande tant que le changement n'est pas confirmé.",
            "pendingTitle": "Changement de nom d'entreprise en cours de vérification",
            "pendingMessage": "Votre demande de changement de nom d'entreprise est en cours de vérification. Les commandes ne sont pas possibles tant que le changement n'est pas confirmé."
        }
    }
}
```

#### 12.2 French Swiss (fr-CH) Storefront

```json
{
    "better-checkout": {
        "companyChange": {
            "blockedTitle": "Commande impossible pour le moment",
            "blockedMessage": "Votre demande de changement de nom d'entreprise de \"%oldCompany%\" à \"%newCompany%\" est en cours de vérification. Vous ne pouvez pas passer de commande tant que le changement n'est pas confirmé.",
            "pendingTitle": "Changement de nom d'entreprise en cours de vérification",
            "pendingMessage": "Votre demande de changement de nom d'entreprise est en cours de vérification. Les commandes ne sont pas possibles tant que le changement n'est pas confirmé."
        }
    }
}
```

#### 12.3 Portuguese (pt-PT) Storefront

```json
{
    "better-checkout": {
        "companyChange": {
            "blockedTitle": "Encomenda não possível neste momento",
            "blockedMessage": "O seu pedido de alteração do nome da empresa de \"%oldCompany%\" para \"%newCompany%\" está em revisão. Não pode fazer encomendas até a alteração ser confirmada.",
            "pendingTitle": "Alteração do nome da empresa em revisão",
            "pendingMessage": "O seu pedido de alteração do nome da empresa está em revisão. Encomendas não são possíveis até a alteração ser confirmada."
        }
    }
}
```

### Phase 13: Merge Storefront Snippets

The existing storefront snippet files need to be **merged** with the new `companyChange` keys. The full merged files:

#### 13.1 `src/Resources/snippet/de_DE/storefront.de-DE.json` (full content)

```json
{
    "better-checkout": {
        "box": {
            "registerTitle": "Ich möchte mich als neuer Kunde registrieren",
            "registerText": "Melden Sie sich einmal an und profitieren Sie für lange Zeit.",
            "registerBtn": "Ein Konto erstellen",
            "loginTitle": "Ich habe bereits ein Konto",
            "guestTitle": "Ich möchte nur als Gast bestellen",
            "guestText": "Der schnelle Weg zu Ihrer Bestellung ohne Kundenkonto",
            "guestBtn": "Bestellung als Gast"
        },
        "register": {
            "emailAlreadyRegistered": "Sie sind bereits als Kunde registriert - bitte loggen Sie sich ein."
        },
        "account": {
            "addressesTitle": "Rechnungsadresse",
            "addressesAvailable": "Verfügbare Lieferadressen"
        },
        "billingAddressEdit": {
            "title": "Rechnungsadresse bearbeiten",
            "cancel": "Abbrechen",
            "save": "Speichern"
        },
        "companyChange": {
            "blockedTitle": "Bestellung derzeit nicht möglich",
            "blockedMessage": "Ihr Antrag auf Änderung des Firmennamens von \"%oldCompany%\" zu \"%newCompany%\" wird gerade geprüft. Bis zur Bestätigung können Sie keine Bestellung aufgeben.",
            "pendingTitle": "Firmenname-Änderung wird geprüft",
            "pendingMessage": "Ihr Antrag auf Änderung des Firmennamens wird aktuell geprüft. Bis zur Bestätigung sind Bestellungen nicht möglich."
        }
    },
    "account": {
        "addressesTitleDefaultBillingAddress": "Rechnungsadresse"
    },
    "checkout": {
        "confirmChangeBillingAddress": "Rechnungsadresse bearbeiten"
    }
}
```

#### 13.2 `src/Resources/snippet/en_GB/storefront.en-GB.json` (full content)

```json
{
    "better-checkout": {
        "box": {
            "registerTitle": "I want to register as a new customer",
            "registerText": "Sign up once and benefit for a long time.",
            "registerBtn": "Create an account",
            "loginTitle": "I already have an account",
            "guestTitle": "I only want to order as a guest",
            "guestText": "The quick way to your order without a customer account",
            "guestBtn": "Order as guest"
        },
        "register": {
            "emailAlreadyRegistered": "You are already registered as a customer - please log in."
        },
        "account": {
            "addressesTitle": "Billing address",
            "addressesAvailable": "Available shipping addresses"
        },
        "billingAddressEdit": {
            "title": "Edit billing address",
            "cancel": "Cancel",
            "save": "Save"
        },
        "companyChange": {
            "blockedTitle": "Order not possible at this time",
            "blockedMessage": "Your request to change the company name from \"%oldCompany%\" to \"%newCompany%\" is currently under review. You cannot place an order until the change is confirmed.",
            "pendingTitle": "Company name change under review",
            "pendingMessage": "Your request to change the company name is currently under review. Orders are not possible until the change is confirmed."
        }
    },
    "account": {
        "addressesTitleDefaultBillingAddress": "Billing address"
    },
    "checkout": {
        "confirmChangeBillingAddress": "Edit billing address"
    }
}
```

### Phase 14: Account Address List Twig Override

Add the pending-change-request notice to the account address book page.

```twig
{# [NEW FILE] src/Resources/views/storefront/page/account/addressbook/index.html.twig #}
{% sw_extends '@Storefront/storefront/page/account/addressbook/index.html.twig' %}

{% block page_account_addressbook %}
    {% set pendingChange = page.getExtension('topdataCompanyNameChangePending') %}

    {% if pendingChange %}
        <div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
            <div class="me-3">
                {% sw_icon 'warning' style { size: 'md' } %}
            </div>
            <div>
                <strong>{{ 'better-checkout.companyChange.pendingTitle'|trans|sw_sanitize }}</strong>
                <p class="mb-0 mt-1">
                    {{ 'better-checkout.companyChange.pendingMessage'|trans|sw_sanitize }}
                </p>
            </div>
        </div>
    {% endif %}

    {{ parent() }}
{% endblock %}
```

### Phase 15: Complete File Manifest

| Action | Path | Description |
|--------|------|-------------|
| NEW | `src/Core/Content/CompanyNameChangeRequest/CompanyNameChangeRequestDefinition.php` | DAL entity definition |
| NEW | `src/Core/Content/CompanyNameChangeRequest/CompanyNameChangeRequestEntity.php` | Entity class |
| NEW | `src/Core/Content/CompanyNameChangeRequest/CompanyNameChangeRequestCollection.php` | Entity collection |
| NEW | `src/Core/Content/CompanyNameChangeRequest/CompanyNameChangeRequestService.php` | Core business logic service |
| NEW | `src/Core/Content/CompanyNameChangeRequest/CompanyNameChangeRequestEmailService.php` | Email notification service |
| NEW | `src/Core/Content/CompanyNameChangeRequest/CompanyNameChangePendingExtension.php` | Template struct extension |
| NEW | `src/Migration/Migration1748979000CreateCompanyNameChangeRequestTable.php` | DB migration |
| NEW | `src/Core/Checkout/Customer/Subscriber/CheckoutConfirmBlockSubscriber.php` | Blocks checkout on pending request |
| NEW | `src/Core/Checkout/Customer/Subscriber/AccountAddressPageSubscriber.php` | Adds pending data to address pages |
| NEW | `src/Controller/AdminApi/CompanyNameChangeRequestController.php` | Admin API endpoints for approve/reject |
| MODIFY | `src/Controller/BillingAddressEditController.php` | Intercept company changes on save |
| MODIFY | `src/Resources/config/services.xml` | Register new services + update existing |
| MODIFY | `src/Resources/snippet/de_DE/storefront.de-DE.json` | Add companyChange snippets |
| MODIFY | `src/Resources/snippet/en_GB/storefront.en-GB.json` | Add companyChange snippets |
| NEW | `src/Resources/views/storefront/page/checkout/confirm/confirm-company-name-change-pending.html.twig` | Checkout block notice |
| MODIFY | `src/Resources/views/storefront/component/address/billing-address-edit-modal.html.twig` | Add pending notice to modal |
| NEW | `src/Resources/views/storefront/page/account/addressbook/index.html.twig` | Address book pending notice |
| NEW | `src/Resources/views/email/admin-company-name-change-notification.html.twig` | Admin notification email |
| NEW | `src/Resources/views/email/customer-company-name-approved.html.twig` | Customer approval email |
| NEW | `src/Resources/views/email/customer-company-name-rejected.html.twig` | Customer rejection email |
| NEW | `src/Resources/app/administration/src/main.js` | Admin module entry point |
| NEW | `src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/` | Admin module directory |
| NEW | `src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/index.js` | Module registration |
| NEW | `src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/default-search-configuration.js` | Search config |
| NEW | `src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/page/topdata-company-name-change-list/` | List page |
| NEW | `src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/page/topdata-company-name-change-detail/` | Detail page |
| NEW | `src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/service/company-name-change-request.service.js` | Admin API service |
| NEW | `src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/snippet/de-DE.js` | Admin German snippets |
| NEW | `src/Resources/app/administration/src/module/topdata-better-checkout-company-name-change/snippet/en-GB.js` | Admin English snippets |
| NEW | `src/Resources/snippet/fr_FR/SnippetFile_fr_FR.php` + `storefront.fr-FR.json` | French snippets |
| NEW | `src/Resources/snippet/fr_CH/SnippetFile_fr_CH.php` + `storefront.fr-CH.json` | Swiss French snippets |
| NEW | `src/Resources/snippet/pt_PT/SnippetFile_pt_PT.php` + `storefront.pt-PT.json` | Portuguese snippets |

### Phase 16: Report

After implementation, write a report to `_ai/backlog/reports/{YYMMDD_HHmm}__IMPLEMENTATION_REPORT__company-name-change-approval.md` with the following frontmatter:

```yaml
---
filename: "_ai/backlog/reports/{YYMMDD_HHmm}__IMPLEMENTATION_REPORT__company-name-change-approval.md"
title: "Report: Company Name Change Approval Workflow"
createdAt: YYYY-MM-DD HH:mm
updatedAt: YYYY-MM-DD HH:mm
planFile: "_ai/backlog/active/260603_1951__IMPLEMENTATION_PLAN__company-name-change-approval.md"
project: "TopdataBetterCheckoutSW6"
status: completed
filesCreated: 0
filesModified: 0
filesDeleted: 0
tags: [checkout, billing-address, admin, email, approval-workflow, sw6.7]
documentType: IMPLEMENTATION_REPORT
---
```

The report must include:
1. **Summary** of what was accomplished
2. **Files Changed** (new, modified, deleted)
3. **Key Changes** (technical decisions, deviations)
4. **Deviations from Plan** (if any)
5. **Technical Decisions** (trade-offs, alternatives considered)
6. **Testing Notes** (manual QA checklist items)
7. **Usage Examples** for admin module (list, detail, approve, reject)
8. **Documentation Updates** (snippet keys, config, new entity)
9. **Next Steps** (optional improvements)

---

## 5. Key Design Decisions

| Decision | Rationale |
|---|---|
| Custom entity (not `system_config`) | Change requests are transactional data with lifecycle states (pending → approved/rejected). They require querying, filtering, and per-row audit trails — `system_config` is not suitable. |
| Auto-cancel previous pending requests | When a customer submits a new change request, any existing pending request for the same address is automatically rejected. This prevents accumulation and simplifies the approval workflow. |
| Block checkout at confirm page level | Rather than blocking at the cart/line-item level, we block at the `CheckoutConfirmPageLoadedEvent` level. This is the cleanest hook — the customer can still browse and fill their cart, but cannot finalize. |
| Keep other address fields editable | Only `company` changes on the **billing** address trigger the approval workflow. Street, zip, city, etc. are still updated immediately. |
| Symposium Mailer (not Shopware Mail system) | We use `Symfony\Component\Mailer\MailerInterface` directly for simplicity. The Shopware mail system would require mail template entities and is heavier. For a workflow notification (admin-facing), direct mailer is appropriate. |
| Admin module uses plain API calls | The approve/reject actions use custom API endpoints rather than direct DAL writes, ensuring the service layer handles side effects (address update, email dispatch). |
| Vite-based admin module | SW6.7 uses Vite for admin builds. The module follows the standard SW6 module structure with `routes`, `navigation`, and `entity` registration. |

## 6. Potential Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Customer creates multiple change requests for same address | `createChangeRequest()` auto-rejects any pre-existing pending requests |
| Company field set to empty string (instead of actual change) | Only trigger approval when `$newCompanyName !== $oldCompanyName && trim($newCompanyName) !== ''` |
| Admin never approves/rejects → customer permanently blocked | Consider adding a config option for timeout (future enhancement, not in scope) |
| Race condition on approve (address deleted meanwhile) | Service catches exceptions gracefully; approve logic validates address still exists |
| Guest customers also blocked | The workflow applies to all customers with billing addresses — guests typically don't edit addresses, so this is a low risk |