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
