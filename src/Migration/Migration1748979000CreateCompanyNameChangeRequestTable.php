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
