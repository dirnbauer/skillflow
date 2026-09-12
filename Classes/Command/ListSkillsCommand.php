<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Command;

use Netresearch\NrLlm\Domain\Model\SkillSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webconsulting\Skillflow\Service\SkillAdministrationService;
use Webconsulting\Skillflow\Support\Typed;

/** Review view of nr_llm's skill table for the terminal. */
#[AsCommand(
    name: 'skillflow:skills:list',
    description: 'List nr_llm skills with their enabled, support and orphaned state'
)]
final class ListSkillsCommand extends Command
{
    public function __construct(
        private readonly SkillAdministrationService $skills,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Restrict to one skill source (uid or title)')
            ->addOption('enabled', 'e', InputOption::VALUE_NONE, 'Only enabled skills')
            ->setHelp(<<<'HELP'
Lists the skills nr_llm has synchronized, one row per skill:

  <info>%command.full_name%</info>
  <info>%command.full_name% --source="Company skills"</info>
  <info>%command.full_name% --enabled</info>

"Support" is nr_llm's assessment ("full", or "partial" when the skill declares
tools or references scripts); -v adds the assessment notes. Orphaned skills
disappeared upstream and cannot be enabled.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourceReference = Typed::string($input->getOption('source'));
        $source = null;
        if ($sourceReference !== '') {
            try {
                $source = $this->skills->findSource($sourceReference);
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }
            if ($source === null) {
                $io->error(sprintf('Skill source not found: %s', $sourceReference));
                return Command::FAILURE;
            }
        }

        $titles = [];
        foreach ($this->skills->findSources() as $skillSource) {
            $titles[(int)$skillSource->getUid()] = $skillSource->getTitle();
        }
        $skills = $this->skills->findSkills($source, (bool)$input->getOption('enabled'));
        if ($skills === []) {
            $io->note('No skills found. Synchronize a source with skillflow:skills:sync first.');
            return Command::SUCCESS;
        }

        $rows = [];
        $enabled = 0;
        foreach ($skills as $skill) {
            $enabled += $skill->isEnabled() ? 1 : 0;
            $support = $skill->getSupportStatus();
            if ($output->isVerbose() && $skill->getUnsupportedNotes() !== '') {
                $support .= ' (' . $skill->getUnsupportedNotes() . ')';
            }
            $rows[] = [
                (int)$skill->getUid(),
                $skill->getIdentifier(),
                $skill->getName(),
                sprintf('%s (%d)', $titles[$skill->getSource()] ?? 'unknown source', $skill->getSource()),
                $skill->isEnabled() ? 'yes' : 'no',
                $support,
                $skill->isOrphaned() ? 'yes' : 'no',
            ];
        }
        $io->table(['UID', 'Identifier', 'Name', 'Source', 'Enabled', 'Support', 'Orphaned'], $rows);
        $io->text(sprintf('%d skill(s), %d enabled.', count($skills), $enabled));

        return Command::SUCCESS;
    }
}
