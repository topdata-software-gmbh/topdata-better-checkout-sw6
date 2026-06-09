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
use Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\AddressQualityService;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\SwissPostApiService;

#[AsCommand(
    name: 'topdata:better-checkout:backfill-address-quality',
    description: 'Backfill Swiss Post address quality for all existing CH/LI addresses.'
)]
class BackfillAddressQualityCommand extends Command
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly Connection            $connection,
        private readonly AddressQualityService $addressQualityService,
        private readonly SwissPostApiService   $swissPostApiService,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count addresses without updating');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption('dry-run');
        $exitCode = Command::SUCCESS;

        $countryIds = $this->getCountryIds();
        if (empty($countryIds)) {
            $output->writeln('<error>No CH/LI countries found in the system.</error>');

            return Command::FAILURE;
        }

        $total = $this->countAddresses($countryIds);
        if ($total === 0) {
            $output->writeln('<info>No CH/LI addresses to process.</info>');
        } else {
            $output->writeln(sprintf('Found <comment>%d</comment> CH/LI address(es).', $total));

            if (!$dryRun) {
                $exitCode = $this->processChLiAddresses($countryIds, $total, $output);
            }
        }

        if (!$dryRun) {
            $naExitCode = $this->markNotApplicableAddresses($countryIds, $output);
            if ($naExitCode !== Command::SUCCESS) {
                $exitCode = $naExitCode;
            }
        }

        return $exitCode;
    }

    private function getCountryIds(): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(id)) FROM country WHERE iso IN (?, ?)',
            ['CH', 'LI']
        );
    }

    private function countAddresses(array $countryIds): int
    {
        $params = [];
        $types = [];
        $placeholders = [];
        foreach ($countryIds as $id) {
            $placeholders[] = '?';
            $params[] = Uuid::fromHexToBytes($id);
            $types[] = ParameterType::BINARY;
        }

        return (int)$this->connection->fetchOne(
            'SELECT COUNT(*) FROM customer_address WHERE country_id IN (' . implode(',', $placeholders) . ')',
            $params,
            $types
        );
    }

    private function processChLiAddresses(array $countryIds, int $total, OutputInterface $output): int
    {
        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        $lastId = null;

        while ($processed < $total) {
            $rows = $this->fetchAddressBatch($countryIds, $lastId);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $processed++;
                $lastId = $row['id'];

                $iso = $row['country_iso'];
                if ($iso === null) {
                    $skipped++;
                    continue;
                }

                $customFields = $row['custom_fields'] !== null ? json_decode($row['custom_fields'], true) : [];
                if ($this->addressQualityService->hasStatus($customFields)) {
                    $skipped++;
                    continue;
                }

                try {
                    $validation = $this->swissPostApiService->validateAddress([
                        'firstName'   => $row['first_name'] ?? '',
                        'lastName'    => $row['last_name'] ?? '',
                        'street'      => $row['street'] ?? '',
                        'zipcode'     => $row['zipcode'] ?? '',
                        'city'        => $row['city'] ?? '',
                        'countryCode' => $iso,
                    ]);

                    $quality = $validation['quality'] ?? ($validation['success'] ? 'UNKNOWN' : 'INVALID');

                    $this->addressQualityService->upsertQualityRaw($row['id'], $quality);

                    $updated++;
                    $output->writeln(sprintf('  [<info>OK</info>] Address %s => %s', $row['id'], $quality), OutputInterface::VERBOSITY_VERBOSE);
                } catch (\Throwable $e) {
                    $failed++;
                    $output->writeln(sprintf('  [<error>FAIL</error>] Address %s => %s', $row['id'], $e->getMessage()), OutputInterface::VERBOSITY_VERBOSE);
                }
            }

            $output->writeln(sprintf('Progress: <comment>%d/%d</comment> (updated: %d, skipped: %d, failed: %d)', $processed, $total, $updated, $skipped, $failed));
        }

        $output->writeln('');
        $output->writeln(sprintf('<info>Done.</info> Updated: %d, Skipped: %d, Failed: %d', $updated, $skipped, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function fetchAddressBatch(array $countryIds, ?string $lastId): array
    {
        $params = [];
        $types = [];

        $countryPlaceholders = [];
        foreach ($countryIds as $id) {
            $countryPlaceholders[] = '?';
            $params[] = Uuid::fromHexToBytes($id);
            $types[] = ParameterType::BINARY;
        }

        $where = 'ca.country_id IN (' . implode(',', $countryPlaceholders) . ')';

        if ($lastId !== null) {
            $where .= ' AND ca.id > ?';
            $params[] = Uuid::fromHexToBytes($lastId);
            $types[] = ParameterType::BINARY;
        }

        $params[] = self::BATCH_SIZE;
        $types[] = ParameterType::INTEGER;

        $sql = "
            SELECT LOWER(HEX(ca.id)) AS id,
                   ca.first_name, ca.last_name,
                   ca.street, ca.zipcode, ca.city,
                   ca.custom_fields,
                   co.iso AS country_iso
            FROM customer_address ca
            JOIN country co ON co.id = ca.country_id
            WHERE {$where}
            ORDER BY ca.id ASC
            LIMIT ?
        ";

        return $this->connection->fetchAllAssociative($sql, $params, $types);
    }

    private function markNotApplicableAddresses(array $countryIds, OutputInterface $output): int
    {
        $params = [];
        $types = [];

        $placeholders = [];
        foreach ($countryIds as $id) {
            $placeholders[] = '?';
            $params[] = Uuid::fromHexToBytes($id);
            $types[] = ParameterType::BINARY;
        }

        $metaKey = AddressQualityService::METADATA_KEY;
        $notApplicable = AddressQualityService::NOT_APPLICABLE;

        $count = (int)$this->connection->fetchOne(
            "SELECT COUNT(*) FROM customer_address
             WHERE country_id NOT IN (" . implode(',', $placeholders) . ")
               AND (custom_fields IS NULL OR JSON_EXTRACT(custom_fields, '$.{$metaKey}') IS NULL)",
            $params,
            $types
        );

        if ($count === 0) {
            $output->writeln('<info>No non-CH/LI addresses without a status to mark.</info>');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Marking <comment>%d</comment> non-CH/LI address(es) as <comment>%s</comment>...', $count, $notApplicable));

        $processed = 0;
        $lastId = null;

        while ($processed < $count) {
            $rows = $this->fetchNotApplicableBatch($countryIds, $lastId);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $processed++;
                $lastId = $row['id'];

                $this->addressQualityService->upsertQualityRaw($row['id'], $notApplicable);
            }

            $output->writeln(sprintf('Progress: <comment>%d/%d</comment>', $processed, $count));
        }

        $output->writeln('');
        $output->writeln(sprintf('<info>Done.</info> Marked %d address(es) as %s.', $processed, $notApplicable));

        return Command::SUCCESS;
    }

    private function fetchNotApplicableBatch(array $countryIds, ?string $lastId): array
    {
        $params = [];
        $types = [];

        $countryPlaceholders = [];
        foreach ($countryIds as $id) {
            $countryPlaceholders[] = '?';
            $params[] = Uuid::fromHexToBytes($id);
            $types[] = ParameterType::BINARY;
        }

        $where = 'ca.country_id NOT IN (' . implode(',', $countryPlaceholders) . ')';

        $metaKey = AddressQualityService::METADATA_KEY;

        if ($lastId !== null) {
            $where .= ' AND ca.id > ?';
            $params[] = Uuid::fromHexToBytes($lastId);
            $types[] = ParameterType::BINARY;
        }

        $params[] = self::BATCH_SIZE;
        $types[] = ParameterType::INTEGER;

        $sql = "
            SELECT LOWER(HEX(ca.id)) AS id
            FROM customer_address ca
            WHERE {$where}
              AND (ca.custom_fields IS NULL OR JSON_EXTRACT(ca.custom_fields, '$.{$metaKey}') IS NULL)
            ORDER BY ca.id ASC
            LIMIT ?
        ";

        return $this->connection->fetchAllAssociative($sql, $params, $types);
    }
}
