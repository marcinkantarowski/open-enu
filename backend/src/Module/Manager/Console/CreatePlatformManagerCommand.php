<?php

declare(strict_types=1);

namespace App\Module\Manager\Console;

use App\Module\Manager\Entity\PlatformManager;
use App\Module\Manager\Repository\PlatformManagerRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use OpenEnu\Kernel\Crypto\Encryptor;
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
 * Creates a platform operator.
 *
 * The bootstrap problem: operators are created by operators, so the first one
 * has to come from somewhere outside the application. A CLI command is that
 * somewhere - it requires shell access to the server, which is a meaningful bar
 * and one that leaves a trace.
 *
 * There is deliberately no signup endpoint for this, and there never should be.
 */
#[AsCommand(name: 'app:manager:create', description: 'Create a platform operator account')]
final class CreatePlatformManagerCommand extends Command
{
    public function __construct(
        private readonly PlatformManagerRepository $managers,
        private readonly EntityManagerInterface $em,
        private readonly Encryptor $encryptor,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Prompted for if omitted')
            ->addOption('name', null, InputOption::VALUE_REQUIRED)
            ->addOption('super', null, InputOption::VALUE_NONE, 'Can manage other operators');
    }

    #[InfrastructureWrite(reason: 'bootstrapping the first operator, before any actor exists to attribute a command to')]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        if ($this->managers->byEmailHash($this->encryptor->hashForLookup($email)) !== null) {
            $io->error('An operator with that address already exists.');

            return Command::FAILURE;
        }

        $password = $input->getOption('password');

        if (!\is_string($password) || $password === '') {
            // Hidden prompt rather than an argument: a password on the command
            // line lands in shell history and in the process list.
            $question = (new Question('Password: '))->setHidden(true)->setHiddenFallback(false);
            $password = (string) $io->askQuestion($question);
        }

        if (mb_strlen($password) < 16) {
            // Longer than a tenant user's minimum, deliberately: this account
            // can read every tenant's data.
            $io->error('An operator password must be at least 16 characters.');

            return Command::FAILURE;
        }

        $manager = new PlatformManager($email, $this->encryptor->hashForLookup($email));
        $manager->setPassword($this->hasher->hashPassword($manager, $password));

        $name = $input->getOption('name');
        if (\is_string($name) && $name !== '') {
            $manager->setDisplayName($name);
        }

        if ($input->getOption('super') === true) {
            $manager->setRoles([PlatformManager::ROLE_OPERATOR, PlatformManager::ROLE_SUPER]);
        }

        $this->em->persist($manager);
        $this->em->flush();

        $io->success(sprintf('Operator %s created.', $email));
        $io->writeln(sprintf('  id:    %s', $manager->id()));
        $io->writeln(sprintf('  roles: %s', implode(', ', $manager->getRoles())));

        return Command::SUCCESS;
    }
}
