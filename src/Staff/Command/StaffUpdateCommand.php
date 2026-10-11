<?php

declare(strict_types=1);

namespace ShopBite\Staff\Command;

use Override;
use ShopBite\Staff\StaffDirectory;
use ShopBite\Staff\StaffException;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @psalm-suppress UnusedClass Registered as console command in services.xml
 */
#[AsCommand(
    name: 'shopbite:staff:update',
    description: 'Changes role, branches, name, e-mail, password or status of a backoffice account',
)]
final class StaffUpdateCommand extends Command
{
    public function __construct(private readonly StaffDirectory $staff)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Login name')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'management or personal')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Replaces the branches (repeatable)')
            ->addOption('all-branches', null, InputOption::VALUE_NONE, 'Removes the branch binding (management only)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Full name')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'E-mail address')
            ->addOption('password', null, InputOption::VALUE_NONE, 'Ask for a new password (ends the person\'s backoffice sessions)')
            ->addOption('generate-password', null, InputOption::VALUE_NONE, 'Generate a new password and print it once')
            ->addOption('deactivate', null, InputOption::VALUE_NONE, 'Blocks the login')
            ->addOption('activate', null, InputOption::VALUE_NONE, 'Allows the login again');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument('username');

        /** @var list<string> $branchOption */
        $branchOption = array_values((array) $input->getOption('branch'));
        $branches = $input->getOption('all-branches') ? [] : ($branchOption !== [] ? $branchOption : null);

        $password = null;
        if ($input->getOption('generate-password')) {
            $password = StaffDirectory::generatePassword();
        } elseif ($input->getOption('password')) {
            $password = $io->askHidden('New password (at least 8 characters)', static function (?string $value): string {
                if ($value === null || mb_strlen($value) < 8) {
                    throw new \RuntimeException('At least 8 characters.');
                }

                return $value;
            });
        }

        $names = $input->getOption('name') !== null ? StaffNames::split((string) $input->getOption('name'), $username) : [null, null];
        $active = $input->getOption('deactivate') ? false : ($input->getOption('activate') ? true : null);

        try {
            $this->staff->update(
                $username,
                $input->getOption('role') !== null ? (string) $input->getOption('role') : null,
                $branches,
                \is_string($password) ? $password : null,
                $names[0],
                $names[1],
                $input->getOption('email') !== null ? (string) $input->getOption('email') : null,
                $active,
                Context::createCLIContext(),
            );
        } catch (StaffException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $io->success(\sprintf('Updated "%s".', $username));
        if ($input->getOption('generate-password') && \is_string($password)) {
            $io->writeln(\sprintf('Password: <comment>%s</comment> (shown only now)', $password));
        }

        return self::SUCCESS;
    }
}
