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
