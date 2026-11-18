<?php

declare(strict_types=1);

namespace ShopBite\Staff\Command;

use Override;
use ShopBite\Passkey\PasskeyStore;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @psalm-api Registered as console command in services.xml
 */
#[AsCommand(
    name: 'shopbite:staff:passkeys',
    description: 'Lists or revokes the backoffice passkeys of an account',
)]
final class StaffPasskeysCommand extends Command
{
    public function __construct(private readonly PasskeyStore $store)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Login name')
            ->addOption('revoke', null, InputOption::VALUE_REQUIRED, 'Id of the passkey to remove (see the list)')
            ->addOption('revoke-all', null, InputOption::VALUE_NONE, 'Removes all passkeys of the account');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument('username');
        $userId = $this->store->userIdByUsername($username);
        if ($userId === null) {
            $io->error(\sprintf('No account "%s".', $username));

            return self::FAILURE;
        }

        if ($input->getOption('revoke-all')) {
            $count = $this->store->deleteForUsers([$userId]);
            $io->success(\sprintf('Removed %d passkey(s) of "%s".', $count, $username));

            return self::SUCCESS;
        }

        $revoke = $input->getOption('revoke');
        if ($revoke !== null) {
            $revoke = strtolower((string) $revoke);
            if (!Uuid::isValid($revoke) || !$this->store->delete($revoke, $userId)) {
                $io->error(\sprintf('"%s" has no passkey %s.', $username, $revoke));

                return self::FAILURE;
            }
            $io->success(\sprintf('Removed passkey %s of "%s".', $revoke, $username));

            return self::SUCCESS;
        }

        $passkeys = $this->store->listForUser($userId);
        if ($passkeys === []) {
            $io->writeln(\sprintf('"%s" has no passkeys.', $username));

            return self::SUCCESS;
        }
        $io->table(['Id', 'Name', 'Created', 'Last used'], array_map(static fn (array $passkey): array => [
            $passkey['id'],
            $passkey['name'],
            $passkey['createdAt'],
            $passkey['lastUsedAt'] ?? 'never',
        ], $passkeys));

        return self::SUCCESS;
    }
}
