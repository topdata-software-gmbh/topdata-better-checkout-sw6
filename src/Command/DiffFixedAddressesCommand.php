<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\AddressQualityService;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\SwissPostApiService;

#[AsCommand(
    name: 'topdata:better-checkout:diff-fixed-addresses',
    description: 'Re-validate all FIXED addresses against the Swiss Post API and show a diff between current and corrected values.'
)]
class DiffFixedAddressesCommand extends Command
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly Connection          $connection,
        private readonly SwissPostApiService $swissPostApiService,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $total = $this->countFixedAddresses();
        if ($total === 0) {
            $output->writeln('<info>No FIXED addresses found.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Found <comment>%d</comment> FIXED address(es).', $total));
        $output->writeln('');

        $processed = 0;
        $lastId = null;
        $changed = 0;
        $identical = 0;
        $failed = 0;

        while ($processed < $total) {
            $rows = $this->fetchFixedAddressBatch($lastId);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $processed++;
                $lastId = $row['id'];

                $output->writeln(sprintf('<options=bold>Address %s</>', $row['id']));
                $output->writeln(sprintf('  Customer: %s %s', $row['first_name'], $row['last_name']));
                $output->writeln(sprintf('  Current:  %s, %s %s', $row['street'], $row['zipcode'], $row['city']));

                try {
                    $validation = $this->swissPostApiService->validateAddress([
                        'firstName'   => $row['first_name'] ?? '',
                        'lastName'    => $row['last_name'] ?? '',
                        'street'      => $row['street'] ?? '',
                        'zipcode'     => $row['zipcode'] ?? '',
                        'city'        => $row['city'] ?? '',
                        'countryCode' => $row['country_iso'],
                    ], null);
// dump($validation);
                    // Check if the API call itself was successful
                    if (!isset($validation['success']) || !$validation['success']) {
                        $errorMsg = $validation['error'] ?? 'Unknown API error';
                        $output->writeln(sprintf('  <error>API Validation Error: %s</error>', $errorMsg));
                        if (!empty($validation['details'])) {
                            $output->writeln(sprintf('  Details: %s', json_encode($validation['details'])));
                        }
                        $failed++;
                        $output->writeln('');
                        continue;
                    }

                    $corrected = $this->extractCorrectedAddress($validation['originalResponse'] ?? []);

                    if (empty($corrected)) {
                        $output->writeln('  <comment>No corrected address data in API response.</comment>');
                        $failed++;
                    } else {
                        $diffs = $this->buildDiff($row, $corrected);
                        $this->renderDiff($output, $diffs);

                        if (!empty($diffs)) {
                            $changed++;
                        } else {
                            $identical++;
                        }
                    }
                } catch (\Throwable $e) {
                    $output->writeln(sprintf('  <error>API call failed: %s</error>', $e->getMessage()));
                    $failed++;
                }

                $output->writeln('');
            }
        }

        $output->writeln(sprintf(
            '<info>Done.</info> Processed: %d, Changed: %d, Identical: %d, Failed: %d',
            $processed, $changed, $identical, $failed
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }



    private function countFixedAddresses(): int
    {
        $metaKey = AddressQualityService::METADATA_KEY;

        return (int)$this->connection->fetchOne(
            "SELECT COUNT(*) FROM customer_address
             WHERE JSON_VALUE(custom_fields, '$.\"{$metaKey}\"') = 'FIXED'"
        );
    }

    private function fetchFixedAddressBatch(?string $lastId): array
    {
        $metaKey = AddressQualityService::METADATA_KEY;
        $params = [];
        $types = [];

        $where = "JSON_VALUE(ca.custom_fields, '$.\"{$metaKey}\"') = 'FIXED'";

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
                   co.iso AS country_iso
            FROM customer_address ca
            JOIN country co ON co.id = ca.country_id
            WHERE {$where}
            ORDER BY ca.id ASC
            LIMIT ?
        ";

        return $this->connection->fetchAllAssociative($sql, $params, $types);
    }

    private function extractCorrectedAddress(array $response): array
    {
        $corrected = [];

        // Support 'address' (from DCAPI validation), 'checkedAddress', or fall back to root response
        $source = $response['address'] ?? $response['checkedAddress'] ?? $response;

        if (isset($source['addressee'])) {
            if (!empty($source['addressee']['firstName'])) {
                $corrected['first_name'] = $source['addressee']['firstName'];
            }
            if (!empty($source['addressee']['lastName'])) {
                $corrected['last_name'] = $source['addressee']['lastName'];
            }
        }

        if (isset($source['geographicLocation']['house'])) {
            $street = $source['geographicLocation']['house']['street'] ?? '';
            $houseNumber = $source['geographicLocation']['house']['houseNumber'] ?? '';
            if ($street !== '') {
                $corrected['street'] = $houseNumber !== '' ? $street . ' ' . $houseNumber : $street;
            }
        }

        // Zip/city can be nested directly under geographicLocation or geographicLocation.house
        $zipData = $source['geographicLocation']['zip'] ?? $source['geographicLocation']['house']['zip'] ?? null;
        if (is_array($zipData)) {
            if (!empty($zipData['zip'])) {
                $corrected['zipcode'] = $zipData['zip'];
            }
            if (!empty($zipData['city'])) {
                $corrected['city'] = $zipData['city'];
            }
        }

        return $corrected;
    }

    private function buildDiff(array $row, array $corrected): array
    {
        $fields = ['first_name', 'last_name', 'street', 'zipcode', 'city'];
        $diffs = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $corrected)) {
                continue;
            }
            $current = $row[$field] ?? '';
            $proposed = $corrected[$field];

            if ($current !== $proposed) {
                $diffs[] = [
                    'field'    => $field,
                    'current'  => $current,
                    'proposed' => $proposed,
                ];
            }
        }

        return $diffs;
    }

    private function renderDiff(OutputInterface $output, array $diffs): void
    {
        if (empty($diffs)) {
            $output->writeln('  <info>Address matches corrected version — no diff.</info>');
            return;
        }

        $rows = [];
        foreach ($diffs as $diff) {
            $rows[] = sprintf(
                '    <fg=red>%s</> <fg=yellow>%s</> => <fg=green>%s</>',
                str_pad($diff['field'], 12),
                $diff['current'],
                $diff['proposed'],
            );
        }

        $output->writeln('  Changes:');
        $output->writeln(implode("\n", $rows));
    }
}
