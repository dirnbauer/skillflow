<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Command;

use Netresearch\NrLlm\Domain\Enum\SyncStatus;
use Netresearch\NrLlm\Domain\Model\SkillSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webconsulting\Skillflow\Service\SkillAdministrationService;
use Webconsulting\Skillflow\Support\Typed;

/**
 * CLI counterpart of "Sync now" in nr_llm's AI → Authoring → Skills module,
 * for cron jobs and deployments. nr_llm ships no sync command of its own.
 */
#[AsCommand(
    name: 'skillflow:skills:sync',
    description: 'Synchronize nr_llm skill sources (new skills are created disabled for review)'
)]
final class SyncSkillsCommand extends Command
{
    public function __construct(
        private readonly SkillAdministrationService $skills,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::OPTIONAL, 'Skill source uid or title')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Synchronize every enabled skill source')
            ->setHelp(<<<'HELP'
Runs nr_llm's skill sync for one source or for every enabled source:

  <info>%command.full_name% 3</info>
  <info>%command.full_name% "Company skills"</info>
  <info>%command.full_name% --all</info>

New skills are created disabled for review; enable them with
<info>skillflow:skills:enable</info>. A re-sync disables enabled skills whose body
changed and orphans skills that disappeared upstream.

Exit code 1 when a source ends in status "error" or was skipped because a sync
is already running for it.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reference = Typed::string($input->getArgument('source'));
        $all = (bool)$input->getOption('all');

        if ($all === ($reference !== '')) {
            $io->error('Pass exactly one of: a source uid/title, or --all.');
            return Command::INVALID;
        }

        try {
            $sources = $all ? $this->enabledSources() : [$this->requireEnabledSource($reference)];
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        if ($sources === []) {
            $io->warning('No enabled skill sources found. Add one in AI → Authoring → Skills.');
            return Command::SUCCESS;
        }

        $failed = 0;
        foreach ($sources as $source) {
            $io->section(sprintf('%s (uid %d, %s)', $source->getTitle(), (int)$source->getUid(), $source->getType()));
            $result = $this->skills->sync($source);
            $io->definitionList(
                ['Status' => $result->status->value],
                ['Created' => $result->created],
                ['Updated' => $result->updated],
                ['Disabled on change' => $result->disabledOnChange],
                ['Orphaned' => $result->orphaned],
                ['Injection blocked' => $result->injectionBlocked],
            );
            if ($result->errors !== []) {
                $io->listing($result->errors);
            }
            if ($result->status === SyncStatus::ERROR || $result->status === SyncStatus::SYNCING) {
                $failed++;
            }
        }

        if ($failed > 0) {
            $io->error(sprintf('%d of %d source(s) did not synchronize.', $failed, count($sources)));
            return Command::FAILURE;
        }
        $io->success(sprintf('Synchronized %d source(s). Review new skills with skillflow:skills:list and enable them with skillflow:skills:enable.', count($sources)));
        return Command::SUCCESS;
    }

    /**
     * @return list<SkillSource>
     */
    private function enabledSources(): array
    {
        return array_values(array_filter(
            $this->skills->findSources(),
            static fn (SkillSource $source): bool => $source->isEnabled(),
        ));
    }

    /**
     * @throws \RuntimeException for an unknown, ambiguous or disabled source
     */
    private function requireEnabledSource(string $reference): SkillSource
    {
        $source = $this->skills->findSource($reference);
        if ($source === null) {
            throw new \RuntimeException(sprintf('Skill source not found: %s', $reference), 1757700003);
        }
        if (!$source->isEnabled()) {
            throw new \RuntimeException(sprintf(
                'Skill source "%s" (uid %d) is disabled. Enable it in AI → Authoring → Skills first.',
                $source->getTitle(),
                (int)$source->getUid(),
            ), 1757700004);
        }

        return $source;
    }
}
