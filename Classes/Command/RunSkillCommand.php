<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webconsulting\Skillflow\Service\SkillExecutionService;
use Webconsulting\Skillflow\Service\SkillFinder;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Run one skill against one record from the CLI (Scheduler-friendly) — the
 * counterpart to the Skills module run form. Engine-agnostic: --engine selects
 * the classic single-shot chain or any context-aware engine registered via the
 * "skillflow.context_runner" DI tag.
 */
#[AsCommand(
    name: 'skillflow:run',
    description: 'Run a skill against a record (--engine selects the execution engine)'
)]
final class RunSkillCommand extends Command
{
    public function __construct(
        private readonly SkillExecutionService $skillExecutionService,
        private readonly SkillFinder $skillFinder,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('skill', InputArgument::REQUIRED, 'Skill uid or identifier')
            ->addArgument('uid', InputArgument::REQUIRED, 'Target record uid')
            ->addOption('table', 't', InputOption::VALUE_REQUIRED, 'Target table', 'pages')
            ->addOption('workspace', 'w', InputOption::VALUE_REQUIRED, 'Workspace uid', '0')
            ->addOption('engine', 'e', InputOption::VALUE_REQUIRED, 'Engine identifier: "classic" or a registered context engine (empty = auto)', '')
            ->addOption('instructions', 'i', InputOption::VALUE_REQUIRED, 'Per-run instructions', '');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $skillUid = $this->resolveSkillUid(Typed::string($input->getArgument('skill')));
        if ($skillUid <= 0) {
            $io->error('Skill not found: ' . Typed::string($input->getArgument('skill')));
            return Command::FAILURE;
        }

        $result = $this->skillExecutionService->runSkillOnRecord(
            $skillUid,
            Typed::string($input->getOption('table')),
            Typed::int($input->getArgument('uid')),
            Typed::int($input->getOption('workspace')),
            0,
            Typed::string($input->getOption('instructions')),
            Typed::string($input->getOption('engine')),
        );

        $io->section(sprintf('Skill %d — %s (runner: %s)', $skillUid, $result->status, $result->runner));
        if ($result->verdict !== '') {
            $io->writeln(sprintf('Verdict: <info>%s</info>%s', $result->verdict, $result->score >= 0 ? ' (' . $result->score . '/100)' : ''));
        }
        if ($result->runUid > 0) {
            $io->writeln('Run: #' . $result->runUid);
        }
        if ($result->externalRef !== '') {
            $io->writeln('Engine run: ' . $result->externalRef);
        }
        $io->newLine();
        $io->writeln($result->output);

        return $result->runStatus()->isAccepted() ? Command::SUCCESS : Command::FAILURE;
    }

    private function resolveSkillUid(string $skill): int
    {
        if (ctype_digit($skill)) {
            return $this->skillFinder->findSkillByUid((int)$skill) !== null ? (int)$skill : 0;
        }
        return Typed::int($this->skillFinder->findSkillByIdentifier($skill)['uid'] ?? 0);
    }
}
