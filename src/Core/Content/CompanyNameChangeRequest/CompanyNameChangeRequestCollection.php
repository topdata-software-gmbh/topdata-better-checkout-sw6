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
