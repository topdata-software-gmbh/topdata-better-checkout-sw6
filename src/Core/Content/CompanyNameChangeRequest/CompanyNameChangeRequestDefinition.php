<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Core\Content\CompanyNameChangeRequest;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressDefinition;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\User\UserDefinition;

#[Entity('tdbc_company_name_change_request')]
class CompanyNameChangeRequestDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'tdbc_company_name_change_request';
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
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('customer_id', 'customerId', CustomerDefinition::class))->addFlags(new Required()),
            (new FkField('address_id', 'addressId', CustomerAddressDefinition::class))->addFlags(new Required()),
            (new StringField('old_company_name', 'oldCompanyName'))->addFlags(new Required()),
            (new StringField('new_company_name', 'newCompanyName'))->addFlags(new Required()),
            (new StringField('status', 'status'))->addFlags(new Required()),
            (new DateTimeField('reviewed_at', 'reviewedAt')),
            (new LongTextField('review_comment', 'reviewComment')),
            (new FkField('reviewed_by_user_id', 'reviewedByUserId', UserDefinition::class)),
            new ManyToOneAssociationField('customer', 'customer_id', CustomerDefinition::class, 'id'),
            new ManyToOneAssociationField('address', 'address_id', CustomerAddressDefinition::class, 'id'),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
