<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataBetterCheckoutSW6\Core\Content\SwissPost\SwissPostApiService;

#[AsCommand(
    name: 'topdata:better-checkout:test-swiss-post',
    description: 'Run diverse verbose test requests against Swiss Post DCAPI (autocomplete + validation).'
)]
class TestSwissPostApiCommand extends Command
{
    public function __construct(
        private readonly SwissPostApiService $apiService,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Run all test scenarios (default)');
        $this->addOption('zip', null, InputOption::VALUE_NONE, 'Test ZIP autocomplete');
        $this->addOption('street', null, InputOption::VALUE_NONE, 'Test street autocomplete');
        $this->addOption('validate', null, InputOption::VALUE_NONE, 'Test address validation');
        $this->addOption('zip-query', null, InputOption::VALUE_REQUIRED, 'Query for ZIP autocomplete', '30');
        $this->addOption('street-query', null, InputOption::VALUE_REQUIRED, 'Query for street autocomplete', 'Bahnhof');
        $this->addOption('street-zip', null, InputOption::VALUE_REQUIRED, 'ZIP code for street autocomplete', '8001');
        $this->addOption('firstname', null, InputOption::VALUE_REQUIRED, 'First name for validation test', 'Hans');
        $this->addOption('lastname', null, InputOption::VALUE_REQUIRED, 'Last name for validation test', 'Muster');
        $this->addOption('test-street', null, InputOption::VALUE_REQUIRED, 'Street for validation test', 'viale Stazione');
        $this->addOption('test-housenumber', null, InputOption::VALUE_REQUIRED, 'House number for validation test', '15');
        $this->addOption('test-zip', null, InputOption::VALUE_REQUIRED, 'ZIP code for validation test', '6500');
        $this->addOption('test-city', null, InputOption::VALUE_REQUIRED, 'City for validation test', 'Bellinzona');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runAll = $input->getOption('all') || !($input->getOption('zip') || $input->getOption('street') || $input->getOption('validate'));

        $output->writeln('<options=bold>=== Swiss Post DCAPI Test Suite ===</>');
        $output->writeln('');

        $exitCode = Command::SUCCESS;

        if ($runAll || $input->getOption('zip')) {
            if ($this->testZipAutocomplete($input, $output) !== Command::SUCCESS) {
                $exitCode = Command::FAILURE;
            }
        }

        if ($runAll || $input->getOption('street')) {
            if ($this->testStreetAutocomplete($input, $output) !== Command::SUCCESS) {
                $exitCode = Command::FAILURE;
            }
        }

        if ($runAll || $input->getOption('validate')) {
            if ($this->testAddressValidation($input, $output) !== Command::SUCCESS) {
                $exitCode = Command::FAILURE;
            }
        }

        $output->writeln('');
        $output->writeln($exitCode === Command::SUCCESS ? '<info>All tests passed.</info>' : '<error>Some tests failed.</error>');

        return $exitCode;
    }

    private function testZipAutocomplete(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>--- ZIP Autocomplete ---</comment>');

        $queries = [$input->getOption('zip-query')];
        if ($input->getOption('all')) {
            $queries = ['30', '66', '94', '80'];
        }

        foreach ($queries as $query) {
            $output->writeln(sprintf('  Request:  GET /address/v1/zips?zipCity=%s&type=DOMICILE', $query));

            $start = microtime(true);
            $results = $this->apiService->autocompleteZip($query);
            $elapsed = round((microtime(true) - $start) * 1000);

            $output->writeln(sprintf('  Response: <info>%d results</info> in %d ms', count($results), $elapsed));
            if (empty($results)) {
                $output->writeln('  <error>ERROR: No results returned. Check credentials or API availability.</error>');
                $output->writeln('');

                return Command::FAILURE;
            }

            foreach (array_slice($results, 0, 5) as $item) {
                $output->writeln(sprintf('    - %s %s', str_pad($item['zip'] ?? '', 6), $item['city'] ?? ''));
            }
            if (count($results) > 5) {
                $output->writeln(sprintf('    ... and %d more', count($results) - 5));
            }
            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    private function testStreetAutocomplete(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>--- Street Autocomplete ---</comment>');

        $tests = [['query' => $input->getOption('street-query'), 'zip' => $input->getOption('street-zip')]];
        if ($input->getOption('all')) {
            $tests = [
                ['query' => 'Bahnhof', 'zip' => '8001'],
                ['query' => 'Muster', 'zip' => '3030'],
                ['query' => 'Via', 'zip' => '6600'],
                ['query' => 'Langgass', 'zip' => '3012'],
            ];
        }

        foreach ($tests as $test) {
            $output->writeln(sprintf('  Request:  GET /address/v1/streets?name=%s&zip=%s', $test['query'], $test['zip']));

            $start = microtime(true);
            $results = $this->apiService->autocompleteStreet($test['query'], $test['zip']);
            $elapsed = round((microtime(true) - $start) * 1000);

            $output->writeln(sprintf('  Response: <info>%d results</info> in %d ms', count($results), $elapsed));
            foreach (array_slice($results, 0, 8) as $item) {
                $output->writeln(sprintf('    - %s', $item['street'] ?? ''));
            }
            if (count($results) > 8) {
                $output->writeln(sprintf('    ... and %d more', count($results) - 8));
            }
            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    private function testAddressValidation(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>--- Address Validation ---</comment>');

        $addresses = [
            [
                'desc' => 'Post CH example (Bellinzona)',
                'data' => [
                    'firstName' => $input->getOption('firstname'),
                    'lastName' => $input->getOption('lastname'),
                    'street' => $input->getOption('test-street') . ' ' . $input->getOption('test-housenumber'),
                    'zipcode' => $input->getOption('test-zip'),
                    'city' => $input->getOption('test-city'),
                ],
            ],
        ];

        if ($input->getOption('all')) {
            $addresses[] = [
                'desc' => 'Bern (Bahnhofplatz)',
                'data' => [
                    'firstName' => 'Anna',
                    'lastName' => 'Beispiel',
                    'street' => 'Bahnhofplatz 10',
                    'zipcode' => '3011',
                    'city' => 'Bern',
                ],
            ];
            $addresses[] = [
                'desc' => 'Zürich (Bahnhofstrasse)',
                'data' => [
                    'firstName' => 'Peter',
                    'lastName' => 'Muster',
                    'street' => 'Bahnhofstrasse 1',
                    'zipcode' => '8001',
                    'city' => 'Zürich',
                ],
            ];
        }

        foreach ($addresses as $addr) {
            $data = $addr['data'];
            $data['countryCode'] = 'CH';

            $output->writeln(sprintf('  Address:  %s', $addr['desc']));
            $output->writeln(sprintf('    %s %s, %s %s', $data['street'], $data['zipcode'], $data['city'], $data['countryCode']));

            $start = microtime(true);
            $result = $this->apiService->validateAddress($data);
            $elapsed = round((microtime(true) - $start) * 1000);

            $output->writeln(sprintf('  Response: in %d ms', $elapsed));
            $output->writeln(sprintf('    success: %s', $result['success'] ? '<info>true</info>' : '<error>false</error>'));
            $output->writeln(sprintf('    quality: <comment>%s</comment>', $result['quality'] ?? 'N/A'));

            if (!empty($result['error'])) {
                $output->writeln(sprintf('    error:   <error>%s</error>', $result['error']));
            }

            if ($output->isVerbose() && isset($result['originalResponse'])) {
                $output->writeln('    full response:');
                $output->writeln('      ' . json_encode($result['originalResponse'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            $output->writeln('');
        }

        return Command::SUCCESS;
    }
}
