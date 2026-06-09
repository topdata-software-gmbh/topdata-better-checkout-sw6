<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'topdata:better-checkout:certification-stats',
    description: 'Display Swiss Post certification status statistics as a colored table.'
)]
class CertificationStatsCommand extends Command
{
    private const BAR_WIDTH = 30;

    private const STATUS_COLORS = [
        'CERTIFIED'          => 'green',
        'DOMICILE_CERTIFIED' => 'cyan',
        'USABLE'             => 'yellow',
        'UNUSABLE'           => 'red',
        '_NOT_APPLICABLE'    => 'gray',
        'FIXED'              => 'blue',
        'INVALID'            => 'red',
    ];

    public function __construct(
        private readonly Connection $connection,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                JSON_VALUE(custom_fields, '$.topdata_swiss_post_certification_status') AS certification_status,
                COUNT(*) AS total_count
            FROM
                customer_address
            WHERE
                custom_fields IS NOT NULL
            GROUP BY
                certification_status
            ORDER BY
                total_count DESC"
        );

        if (empty($rows)) {
            $output->writeln('<info>No certification status data found.</info>');
            return Command::SUCCESS;
        }

        $maxCount = (int)max(array_column($rows, 'total_count'));
        $maxCount = max($maxCount, 1);

        $output->writeln('');
        $output->writeln('  <options=bold,underscore>Swiss Post Certification Status</>');
        $output->writeln('');

        foreach ($rows as $row) {
            $status = $row['certification_status'] ?? '';
            $count = (int)$row['total_count'];
            $color = self::STATUS_COLORS[$status] ?? 'white';

            $barLen = $count > 0 ? max(1, (int)round(self::BAR_WIDTH * $count / $maxCount)) : 0;
            $bar = str_repeat('█', $barLen);

            $paddedStatus = str_pad($status, 22);
            $paddedCount = str_pad((string)$count, 7, ' ', STR_PAD_LEFT);

            $output->writeln(sprintf(
                '  <fg=%s>%s</> <fg=%s;options=bold>%s</> <fg=%s>%s</>',
                $color, $paddedStatus,
                $color, $paddedCount,
                $color, $bar,
            ));
        }

        $output->writeln('');

        return Command::SUCCESS;
    }
}
