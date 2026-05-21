<?php declare(strict_types=1);

namespace Topdata\TopdataBetterCheckoutSW6\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'bettercheckoutsw6:example',
    description: 'Example command for BetterCheckoutSW6 plugin'
)]
class ExampleCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>BetterCheckoutSW6 plugin example command executed successfully!</info>');
        $output->writeln('This is a minimal example command to get you started.');
        
        return Command::SUCCESS;
    }
}