<?php

declare(strict_types=1);

namespace App\Module\Identity\Console;

use App\Module\Identity\Entity\Membership;
use App\Module\Identity\Entity\User;
use App\Module\Identity\Repository\UserRepository;
use App\Module\Tenant\Contract\TenantProvisionerInterface;
use App\Module\Tenant\Contract\TenantReaderInterface;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use OpenEnu\Kernel\Crypto\Encryptor;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Setup\SetupRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates a workspace and a verified user in it, without the email round trip.
 *
 * Signup deliberately leaves an account unusable until its address is proved,
 * which is right for the public form and wrong for three other situations: the
 * first account on a freshly provisioned server, a developer who wants to log in
 * thirty seconds after `make builddev`, and the end-to-end suite, which needs a
 * known account to exist before a browser opens.
 *
 * Idempotent on purpose - it is run repeatedly by all three.
 */
#[AsCommand(name: 'app:user:create', description: 'Create (or top up) a workspace and a verified user in it')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TenantProvisionerInterface $tenants,
        private readonly TenantReaderInterface $reader,
        private readonly EntityManagerInterface $em,
        private readonly Encryptor $encryptor,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly SetupRunner $setup,
        private readonly ScopeContext $scope,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Prompted for if omitted')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Workspace name', 'Acme')
            ->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Workspace slug; derived from the name if omitted')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'owner | admin | member', Membership::ROLE_OWNER)
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name')
            ->addOption('seed', null, InputOption::VALUE_NONE, 'Also add each module\'s example rows');
    }

    #[InfrastructureWrite(reason: 'bootstrapping an account before any actor exists to attribute a command to')]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');
        $role = (string) $input->getOption('role');

        if (!\in_array($role, [Membership::ROLE_OWNER, Membership::ROLE_ADMIN, Membership::ROLE_MEMBER], true)) {
            $io->error(sprintf('Unknown role "%s".', $role));

            return Command::FAILURE;
        }

        $password = $input->getOption('password');

        if (!\is_string($password) || $password === '') {
            // Hidden prompt rather than an argument: a password on the command
            // line lands in shell history and in the process list.
            $question = (new Question('Password: '))->setHidden(true)->setHiddenFallback(false);
            $password = (string) $io->askQuestion($question);
        }

        if (mb_strlen($password) < 12) {
            $io->error('The password must be at least 12 characters.');

            return Command::FAILURE;
        }

        $tenantName = (string) $input->getOption('tenant');
        $slug = \is_string($input->getOption('slug')) && $input->getOption('slug') !== ''
            ? (string) $input->getOption('slug')
            : $this->slugify($tenantName);

        $tenant = $this->tenantBySlug($slug) ?? $this->tenants->create($slug, $tenantName);
        $tenantId = (string) $tenant['id'];

        // Active, not pending: nobody is going to click a verification link on
        // behalf of a server being provisioned.
        $this->tenants->activate($tenantId);

        $user = $this->users->byEmailHash($this->encryptor->hashForLookup($email));

        if ($user === null) {
            $user = new User($email, $this->encryptor->hashForLookup($email));
            $this->em->persist($user);
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        $user->verify();

        $displayName = $input->getOption('name');
        if (\is_string($displayName) && $displayName !== '') {
            $user->setDisplayName($displayName);
        }

        if ($user->membershipIn($tenantId) === null) {
            // The constructor attaches it to the user; the association is
            // cascaded on flush.
            new Membership($user, $tenantId, $role);
        }

        $this->em->flush();

        if ($input->getOption('seed') === true) {
            // In the tenant's scope, or every hook's "have I already done this?"
            // read returns nothing and re-running duplicates the examples.
            $this->scope->enter([ScopeContext::TENANT => $tenantId]);
            $this->setup->onTenantCreated($tenantId);
            $this->setup->seedExamples($tenantId);
            $io->writeln('  seeded example rows');
        }

        $io->success(sprintf('%s can sign in to "%s" as %s.', $email, $tenantName, $role));
        $io->writeln(sprintf('  user:   %s', $user->id()));
        $io->writeln(sprintf('  tenant: %s (%s)', $tenantId, $slug));

        return Command::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function tenantBySlug(string $slug): ?array
    {
        // Through the reader's contract rather than the Tenant repository: a
        // cross-module repository call is a build failure (ADR-0002).
        foreach ($this->reader->page(0, 500) as $tenant) {
            if (($tenant['slug'] ?? null) === $slug) {
                return $tenant;
            }
        }

        return null;
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));

        return $slug !== '' ? $slug : 'workspace';
    }
}
