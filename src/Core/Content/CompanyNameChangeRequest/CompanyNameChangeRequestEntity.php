<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\ForeignKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Entity as DalEntity;

#[Entity]
class CompanyNameChangeRequestEntity extends DalEntity
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID)]
    protected string $id;

    #[ForeignKey(entity: CustomerEntity::class)]
    #[Field(type: FieldType::UUID)]
    protected string $customerId;

    #[ForeignKey(entity: CustomerAddressEntity::class)]
    #[Field(type: FieldType::UUID)]
    protected string $addressId;

    #[Field(type: FieldType::STRING)]
    protected string $oldCompanyName;

    #[Field(type: FieldType::STRING)]
    protected string $newCompanyName;

    #[Field(type: FieldType::STRING)]
    protected string $status;

    #[Field(type: FieldType::DATETIME)]
    protected ?\DateTimeInterface $reviewedAt = null;

    #[Field(type: FieldType::STRING, nullable: true)]
    protected ?string $reviewComment = null;

    #[Field(type: FieldType::UUID, nullable: true)]
    protected ?string $reviewedByUserId = null;

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
    public function setReviewedByUserId(?string $reviewedByUserId): void { $this->reviewedByUserId = $reviewedByUserId; }
    public function setCustomer(?CustomerEntity $customer): void { $this->customer = $customer; }
    public function setAddress(?CustomerAddressEntity $address): void { $this->address = $address; }
}
