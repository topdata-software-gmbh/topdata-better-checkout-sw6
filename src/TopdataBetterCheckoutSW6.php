<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\CustomField\CustomFieldTypes;

class TopdataBetterCheckoutSW6 extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $this->installCustomFields($installContext->getContext());
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
        $this->installCustomFields($updateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        if ($uninstallContext->keepUserData()) {
            parent::uninstall($uninstallContext);

            return;
        }

        $this->removeCustomFields($uninstallContext);
        parent::uninstall($uninstallContext);
    }

    private function installCustomFields(Context $context): void
    {
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('name', ['topdata_swiss_post_address_validation']));
        $existing = $customFieldSetRepository->searchIds($criteria, $context);

        if ($existing->getTotal() > 0) {
            return;
        }

        $customFieldSetRepository->create([
            [
                'name' => 'topdata_swiss_post_address_validation',
                'config' => [
                    'label' => [
                        'en-GB' => 'Topdata Swiss Post',
                        'de-DE' => 'Topdata Swiss Post',
                    ],
                ],
                'customFields' => [
                    [
                        'name' => 'topdata_swiss_post_certification_status',
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => [
                                'en-GB' => 'Swiss Post certificate',
                                'de-DE' => 'Swiss Post Zertifikat',
                            ],
                            'type' => 'text',
                            'customFieldType' => 'text',
                            'customFieldPosition' => 1,
                        ],
                    ],
                ],
                'relations' => [
                    [
                        'id' => Uuid::randomHex(),
                        'entityName' => 'customer_address',
                    ],
                ],
            ],
        ], $context);
    }

    private function removeCustomFields(UninstallContext $uninstallContext): void
    {
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('name', ['topdata_swiss_post_address_validation']));

        $ids = $customFieldSetRepository->searchIds($criteria, $uninstallContext->getContext());

        if ($ids->getTotal() > 0) {
            $customFieldSetRepository->delete(array_values($ids->getData()), $uninstallContext->getContext());
        }
    }
}