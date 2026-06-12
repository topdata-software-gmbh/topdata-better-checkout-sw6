<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'topdata:better-checkout:enforce-address-separation',
    description: 'Enforce strict billing/shipping address separation for existing customers whose billing and shipping addresses point to the same entity.'
)]
class EnforceAddressSeparationCommand extends Command
{
    private const BATCH_SIZE = 50;

    private const BINARY_COLUMNS = ['id', 'customer_id', 'country_id', 'country_state_id', 'salutation_id'];

    public function __construct(
        private readonly Connection $connection,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count affected customers without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption('dry-run');

        $total = $this->countAffectedCustomers();
        if ($total === 0) {
            $output->writeln('<info>No customers with identical billing and shipping addresses found.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Found <comment>%d</comment> customer(s) with identical billing/shipping addresses.', $total));

        if ($dryRun) {
            $output->writeln('<info>Dry-run mode. No changes were made.</info>');
            return Command::SUCCESS;
        }

        return $this->processCustomers($total, $output);
    }

    private function countAffectedCustomers(): int
    {
        return (int)$this->connection->fetchOne(
            'SELECT COUNT(*) FROM customer WHERE default_billing_address_id IS NOT NULL AND default_shipping_address_id IS NOT NULL AND default_billing_address_id = default_shipping_address_id'
        );
    }

    private function processCustomers(int $total, OutputInterface $output): int
    {
        $processed = 0;
        $updated = 0;
        $failed = 0;
        $lastId = null;

        while ($processed < $total) {
            $rows = $this->fetchCustomerBatch($lastId);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $processed++;
                $lastId = $row['id'];

                try {
                    $this->cloneShippingAddress($row);
                    $updated++;
                    $output->writeln(sprintf('  [<info>OK</info>] Customer %s', $row['id']), OutputInterface::VERBOSITY_VERBOSE);
                } catch (\Throwable $e) {
                    $failed++;
                    $output->writeln(sprintf('  [<error>FAIL</error>] Customer %s => %s', $row['id'], $e->getMessage()), OutputInterface::VERBOSITY_VERBOSE);
                }
            }

            $output->writeln(sprintf('Progress: <comment>%d/%d</comment> (updated: %d, failed: %d)', $processed, $total, $updated, $failed));
        }

        $output->writeln('');
        $output->writeln(sprintf('<info>Done.</info> Updated: %d, Failed: %d', $updated, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function fetchCustomerBatch(?string $lastId): array
    {
        $params = [];
        $types = [];

        $where = 'c.default_billing_address_id IS NOT NULL AND c.default_shipping_address_id IS NOT NULL AND c.default_billing_address_id = c.default_shipping_address_id';

        if ($lastId !== null) {
            $where .= ' AND c.id > ?';
            $params[] = Uuid::fromHexToBytes($lastId);
            $types[] = ParameterType::BINARY;
        }

        $params[] = self::BATCH_SIZE;
        $types[] = ParameterType::INTEGER;

        $sql = "
            SELECT LOWER(HEX(c.id)) AS id,
                   LOWER(HEX(c.default_billing_address_id)) AS billing_address_id
            FROM customer c
            WHERE {$where}
            ORDER BY c.id ASC
            LIMIT ?
        ";

        return $this->connection->fetchAllAssociative($sql, $params, $types);
    }

    private function cloneShippingAddress(array $customer): void
    {
        $billingAddressId = $customer['billing_address_id'];

        $addressData = $this->connection->fetchAssociative(
            'SELECT * FROM customer_address WHERE id = ?',
            [Uuid::fromHexToBytes($billingAddressId)],
            [ParameterType::BINARY]
        );

        if (!$addressData) {
            throw new \RuntimeException(sprintf('Billing address %s not found for customer %s', $billingAddressId, $customer['id']));
        }

        $newAddressId = Uuid::randomBytes();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        $addressData['id'] = $newAddressId;
        $addressData['created_at'] = $now;
        unset($addressData['updated_at']);

        $types = [];
        foreach ($addressData as $column => $value) {
            $types[$column] = in_array($column, self::BINARY_COLUMNS, true) ? ParameterType::BINARY : ParameterType::STRING;
        }

        $this->connection->beginTransaction();
        try {
            $this->connection->insert('customer_address', $addressData, $types);

            $this->connection->update(
                'customer',
                ['default_shipping_address_id' => $newAddressId],
                ['id' => Uuid::fromHexToBytes($customer['id'])],
                ['default_shipping_address_id' => ParameterType::BINARY, 'id' => ParameterType::BINARY]
            );

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
