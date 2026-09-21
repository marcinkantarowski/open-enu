<?php

declare(strict_types=1);

namespace App\Module\Attachment\Console;

use App\Module\Attachment\Repository\AttachmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenEnu\Kernel\Attribute\InfrastructureWrite;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Storage\StorageInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes uploads that were never attached to anything.
 *
 * An upload happens before the form it belongs to is saved, so every abandoned
 * form leaves a file nobody will ever reference. Without this they accumulate
 * forever, and the bill for them is the first anyone hears of it.
 *
 * The grace period is not optional: an attachment uploaded thirty seconds ago
 * and not yet attached is a form somebody is still filling in.
 */
#[AsCommand(name: 'app:attachment:sweep-orphans', description: 'Delete uploads never attached to a record')]
final class SweepOrphanAttachmentsCommand extends Command
{
    private const int DEFAULT_GRACE_HOURS = 24;

    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly StorageInterface $storage,
        private readonly EntityManagerInterface $em,
        private readonly ScopeContext $scope,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Grace period', (string) self::DEFAULT_GRACE_HOURS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would go, delete nothing');
    }

    #[InfrastructureWrite(reason: 'housekeeping across every tenant; the rows being removed were never referenced')]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cutoff = new \DateTimeImmutable(sprintf('-%d hours', max(1, (int) $input->getOption('hours'))));
        $dryRun = $input->getOption('dry-run') === true;

        $swept = $this->scope->runUnscoped(
            'sweeping unreferenced uploads across every tenant',
            function () use ($cutoff, $dryRun, $io): int {
                $count = 0;

                foreach ($this->attachments->orphansOlderThan($cutoff) as $attachment) {
                    $io->writeln(sprintf('  %s %s (%s)', $dryRun ? '·' : '✗', $attachment->filename(), $attachment->id()));

                    if (!$dryRun) {
                        // The blob first, then the row. The other order leaves a
                        // file with nothing pointing at it - invisible, and
                        // billed for.
                        $this->storage->delete($attachment->storageKey());
                        $this->em->remove($attachment);
                    }

                    ++$count;
                }

                if (!$dryRun && $count > 0) {
                    $this->em->flush();
                }

                return $count;
            },
        );

        $io->success(sprintf('%d orphan(s) %s.', $swept, $dryRun ? 'would be removed' : 'removed'));

        return Command::SUCCESS;
    }
}
