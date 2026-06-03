<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest;

use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

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

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([]);
    }
}
