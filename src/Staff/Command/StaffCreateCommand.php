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
    name: 'shopbite:staff:create',
    description: 'Creates a backoffice account for a restaurant\'s management or staff',
)]
final class StaffCreateCommand extends Command
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
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'management (menu, prices, hours, orders) or personal (orders only)')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sales channel id of a branch the person works for (repeatable; required for personal)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Full name, e.g. "Anna Rossi"')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'E-mail address (optional)')
            ->addOption('generate-password', null, InputOption::VALUE_NONE, 'Generate a password and print it once instead of asking')
            ->setHelp(<<<'HELP'
                Examples:
                  <info>bin/console shopbite:staff:create inhaber --role=management --name="Anna Rossi"</info>
                  <info>bin/console shopbite:staff:create theke --role=personal --branch=<salesChannelId> --generate-password</info>

                The password is asked for (hidden) unless --generate-password is given.
                List sales channel ids with <info>bin/console sales-channel:list</info>.
                HELP);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument('username');
        $role = (string) ($input->getOption('role') ?? '');
        /** @var list<string> $branches */
        $branches = array_values((array) $input->getOption('branch'));
        [$firstName, $lastName] = StaffNames::split((string) ($input->getOption('name') ?? ''), $username);

        $generated = (bool) $input->getOption('generate-password');
        $password = $generated ? StaffDirectory::generatePassword() : $this->askPassword($io, $input);
        if ($password === null) {
            $io->error('No password: run interactively or pass --generate-password.');

            return self::FAILURE;
        }

        try {
            $this->staff->create(
                $username,
                $password,
                $role,
                $branches,
                $firstName,
                $lastName,
                $input->getOption('email') !== null ? (string) $input->getOption('email') : null,
                Context::createCLIContext(),
            );
        } catch (StaffException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $io->success(\sprintf('Created "%s" (%s).', $username, $role));
        if ($generated) {
            $io->writeln(\sprintf('Password: <comment>%s</comment> (shown only now)', $password));
        }

        return self::SUCCESS;
    }

    private function askPassword(SymfonyStyle $io, InputInterface $input): ?string
    {
        if (!$input->isInteractive()) {
            return null;
        }
        $password = $io->askHidden('Password (at least 8 characters)', static function (?string $value): string {
            if ($value === null || mb_strlen($value) < 8) {
                throw new \RuntimeException('At least 8 characters.');
            }

            return $value;
        });

        return \is_string($password) ? $password : null;
    }
}
