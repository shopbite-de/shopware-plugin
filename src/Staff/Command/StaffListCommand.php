<?php

declare(strict_types=1);

namespace ShopBite\Staff\Command;

use Override;
use ShopBite\Staff\StaffDirectory;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @psalm-suppress UnusedClass Registered as console command in services.xml
 */
#[AsCommand(name: 'shopbite:staff:list', description: 'Lists the backoffice accounts with role and branches')]
final class StaffListCommand extends Command
{
    public function __construct(private readonly StaffDirectory $staff)
    {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = $this->staff->list(Context::createCLIContext());
        $io = new SymfonyStyle($input, $output);
        if ($rows === []) {
            $io->writeln('No backoffice accounts yet, see shopbite:staff:create.');

            return self::SUCCESS;
        }
        $io->table(['Username', 'Name', 'Role', 'Branches', 'Active'], array_map(static fn (array $row): array => [
            $row['username'],
            $row['name'],
            $row['role'],
            $row['branches'] === [] ? 'all' : implode(', ', $row['branches']),
            $row['active'] ? 'yes' : 'no',
        ], $rows));

        return self::SUCCESS;
    }
}
